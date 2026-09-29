<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\PostLedgerTransaction;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\EarnIntegrityVerifier;
use App\Models\ConfigurationVersion;
use App\Models\Device;
use App\Models\Episode;
use App\Models\FinancialAccount;
use App\Models\Show;
use App\Services\InAppNotificationDelivery;
use App\Support\ApiResponse;
use App\Support\EarnRegion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EarnController extends Controller
{
    public function access(Request $request, EarnRegion $region): JsonResponse
    {
        $country = $region->country($request);

        return ApiResponse::success([
            'allowed' => $country === 'US',
            'country' => $country,
        ]);
    }

    public function shows(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,20'],
        ]);
        $query = Show::query()
            ->where('shows.earn_enabled', true)
            ->where('shows.status', 'active')
            ->whereHas('episodes', fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60))
            ->leftJoin('categories as earn_categories', function ($join): void {
                $join->on('earn_categories.id', '=', 'shows.earn_category_id')->where('earn_categories.active', true);
            })
            ->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url', 'shows.earn_position', 'earn_categories.name as niche')
            ->withCount(['episodes as episodes_count' => fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60)])
            ->orderByRaw('CASE WHEN earn_categories.name IS NULL THEN 1 ELSE 0 END')
            ->orderBy('earn_categories.name')
            ->orderBy('shows.earn_position')
            ->orderBy('shows.title')
            ->orderBy('shows.id');
        if (! empty($filters['q'])) {
            $term = $this->likeTerm($filters['q']);
            $query->where(function ($inner) use ($term): void {
                $inner->where('shows.title', 'like', $term)->orWhere('shows.author', 'like', $term);
            });
        }
        $page = $query->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);

        return ApiResponse::success(collect($page->items())->map(fn (Show $show): array => [
            'id' => $show->id,
            'title' => $show->title,
            'author' => $show->author,
            'artwork_url' => $show->artwork_url,
            'episodes_count' => (int) $show->episodes_count,
            'niche' => $show->getAttribute('niche'),
        ])->values(), $this->pageMeta($page));
    }

    public function episodes(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'show_id' => ['sometimes', 'string', 'exists:shows,id'],
            'q' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,20'],
        ]);
        $query = Episode::query()
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('shows.earn_enabled', true)
            ->where('shows.status', 'active')
            ->whereNotNull('episodes.duration_seconds')
            ->where('episodes.duration_seconds', '>=', 60)
            ->leftJoin('categories as earn_categories', function ($join): void {
                $join->on('earn_categories.id', '=', 'shows.earn_category_id')->where('earn_categories.active', true);
            })
            ->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.duration_seconds', 'episodes.audio_url', 'shows.title as show_title', 'shows.author as show_author', 'shows.artwork_url as artwork_url', 'earn_categories.name as niche')
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id');
        if (! empty($filters['show_id'])) {
            $query->where('episodes.show_id', $filters['show_id']);
        }
        if (! empty($filters['q'])) {
            $term = $this->likeTerm($filters['q']);
            $query->where(function ($inner) use ($term): void {
                $inner->where('episodes.title', 'like', $term)->orWhere('shows.title', 'like', $term);
            });
        }
        $page = $query->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $ids = collect($page->items())->pluck('id');
        $locks = $ids->isEmpty() ? collect() : DB::table('earn_awards')
            ->where('user_id', $request->user()->id)
            ->whereIn('episode_id', $ids)
            ->whereNull('unlocked_at')
            ->where('locked_until', '>', now())
            ->pluck('locked_until', 'episode_id');

        return ApiResponse::success(collect($page->items())->map(function (object $row) use ($locks): array {
            $lockedUntil = $locks[$row->id] ?? null;
            $locked = $lockedUntil !== null;

            return [
                'id' => $row->id,
                'show_id' => $row->show_id,
                'show_title' => $row->show_title,
                'show_author' => $row->show_author,
                'title' => $row->title,
                'artwork_url' => $row->artwork_url,
                'duration_seconds' => (int) $row->duration_seconds,
                'audio_url' => $locked ? null : $row->audio_url,
                'niche' => $row->niche,
                'coins' => $this->coinsFor((int) $row->duration_seconds),
                'locked' => $locked,
                'locked_until' => $locked ? Carbon::parse($lockedUntil)->toIso8601String() : null,
            ];
        })->values(), $this->pageMeta($page));
    }

    private function likeTerm(string $value): string
    {
        return '%'.addcslashes(trim($value), '%_\\').'%';
    }

    private function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'has_more' => $page->hasMorePages(),
            'cursor' => null,
        ];
    }

    public function wallet(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $balance = Cache::remember('earn_wallet:'.$userId, 60, fn () => (string) (FinancialAccount::where('owner_type', get_class($request->user()))->where('owner_id', $userId)->where('type', 'earn_wallet')->where('unit', 'ECN')->value('balance') ?? 0));

        $configuration = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first();

        return ApiResponse::success([
            'user_id' => $userId, 'unit' => 'ECN', 'balance' => (string) $balance,
            'minimum_withdrawal' => (string) data_get($configuration?->payload, 'money.earn_min_withdraw_coins', config('finance.earn_min_withdraw_coins')),
            'withdrawals_enabled' => (bool) config('finance.public_enabled'),
            'coin_usd' => (string) data_get($configuration?->payload, 'money.earn_coin_usd', config('finance.earn_coin_usd')),
            'payout_schedule' => 'monthly',
        ]);
    }

    public function start(Episode $episode, Request $request): JsonResponse
    {
        if (! config('finance.public_enabled')) {
            return ApiResponse::error('SERVICE_DEGRADED', 'Earn is awaiting finance sign-off.', 503);
        }
        $device = Device::where('user_id', $request->user()->id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
        if (! $device) {
            return ApiResponse::error('FORBIDDEN', 'A registered active device is required for Earn.', 403);
        }
        $show = Show::query()->whereKey($episode->show_id)->first();
        if (! $show || ! $show->earn_enabled || $show->status !== 'active' || (int) $episode->duration_seconds < 60) {
            return ApiResponse::error('NOT_FOUND', 'This episode is not available for Earn.', 404);
        }
        if ($this->episodeLocked($request->user()->id, $episode->id)) {
            return ApiResponse::error('EPISODE_LOCKED', 'This episode remains locked for Earn.', 409);
        }
        if ($fraud = $this->fraudHold($request)) {
            return $fraud;
        }
        $config = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first();
        $award = $this->coinsFor((int) $episode->duration_seconds);
        if (DB::table('earn_awards')->where('user_id', $request->user()->id)->where('created_at', '>=', now()->startOfDay())->count() >= config('finance.earn_daily_completion_cap')) {
            return ApiResponse::error('MODERATION_HOLD', 'Daily Earn completion limit reached.', 409);
        }
        $campaign = DB::table('earn_campaigns')->where('state', 'active')->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('starts_at')->first();
        $snapshot = ['config_version' => $config?->version, 'campaign_version' => $campaign?->config_version, 'award' => $award, 'lock_hours' => data_get($config?->payload, 'money.earn_lock_hours', 72), 'risk_policy' => 1];

        return DB::transaction(function () use ($request, $episode, $device, $campaign, $award, $snapshot): JsonResponse {
            $existing = DB::table('earn_sessions')
                ->where('user_id', $request->user()->id)
                ->where('active_guard', 'active')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $stale = $existing->nonce_expires_at && now()->greaterThan($existing->nonce_expires_at);
                if (! $stale && $existing->episode_id === $episode->id && $existing->state === 'active') {
                    $nonce = Str::random(64);
                    DB::table('earn_sessions')->where('id', $existing->id)->update([
                        'nonce_hash' => hash('sha256', $nonce),
                        'nonce_expires_at' => now()->addHours(4),
                        'device_id' => $device->id,
                        'updated_at' => now(),
                    ]);

                    return ApiResponse::success(array_merge($this->presentSession($existing->id, $nonce), [
                        'resume_position' => (int) $existing->last_position,
                        'last_sequence' => (int) $existing->last_sequence,
                    ]));
                }
                DB::table('earn_sessions')->where('id', $existing->id)->update([
                    'state' => $stale ? 'expired' : 'abandoned',
                    'active_guard' => null,
                    'nonce_hash' => null,
                    'updated_at' => now(),
                ]);
            }

            $id = (string) Str::ulid();
            $nonce = Str::random(64);
            try {
                DB::table('earn_sessions')->insert([
                    'id' => $id,
                    'user_id' => $request->user()->id,
                    'episode_id' => $episode->id,
                    'device_id' => $device->id,
                    'earn_campaign_id' => $campaign?->id,
                    'active_guard' => 'active',
                    'nonce_hash' => hash('sha256', $nonce),
                    'nonce_expires_at' => now()->addHours(4),
                    'expected_award' => $award,
                    'eligibility_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return ApiResponse::error('MODERATION_HOLD', 'Only one Earn session may be active.', 409);
            } catch (QueryException $e) {
                if ($this->isUniqueActiveSessionConflict($e)) {
                    return ApiResponse::error('MODERATION_HOLD', 'Only one Earn session may be active.', 409);
                }
                throw $e;
            }

            return ApiResponse::success($this->presentSession($id, $nonce), status: 201);
        }, 3);
    }

    public function heartbeat(string $session, Request $request, EarnIntegrityVerifier $integrity): JsonResponse
    {
        $data = $request->validate(['position' => ['required', 'integer', 'min:0'], 'sequence' => ['required', 'integer', 'min:1'], 'elapsed_seconds' => ['required', 'integer', 'between:1,30'], 'playback_rate' => ['required', 'numeric'], 'foreground' => ['required', 'boolean'], 'audio_active' => ['required', 'boolean'], 'integrity_token' => ['required', 'string', 'max:4096'], 'nonce' => ['required', 'string', 'size:64'], 'audio_fingerprint' => ['nullable', 'string', 'max:500']]);

        return DB::transaction(function () use ($session, $request, $data, $integrity): JsonResponse {
            $row = DB::table('earn_sessions')->where('id', $session)->where('user_id', $request->user()->id)->where('state', 'active')->lockForUpdate()->first();
            if (! $row) {
                $existing = DB::table('earn_sessions')->where('id', $session)->first(['state', 'risk_state', 'verified_seconds', 'last_position']);
                Log::warning('earn.heartbeat', [
                    'result' => 'missing',
                    'reason' => $existing ? 'session_not_active' : 'session_not_found',
                    'session' => $session,
                    'state' => $existing->state ?? null,
                    'risk_state' => $existing->risk_state ?? null,
                    'verified_seconds' => $existing->verified_seconds ?? null,
                    'last_position' => $existing->last_position ?? null,
                ]);

                return ApiResponse::error('NOT_FOUND', 'Earn session not found.', 404);
            }
            $device = Device::where('id', $row->device_id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
            $nonceValid = $row->nonce_hash && $row->nonce_expires_at && now()->lte($row->nonce_expires_at) && hash_equals($row->nonce_hash, hash('sha256', $data['nonce']));
            $advance = (int) $data['position'] - (int) $row->last_position;
            $rateOk = abs((float) $data['playback_rate'] - 1.0) <= 0.01;
            $integrityOk = $integrity->valid($data['integrity_token'], (string) $request->header('X-Device-Id'), $session, (int) $data['sequence'], $data['nonce']);
            $sequenceOk = (int) $data['sequence'] === (int) $row->last_sequence + 1;
            $advanceOk = $advance >= 0 && $advance <= (int) $data['elapsed_seconds'] + 3;
            $claimed = (int) $data['elapsed_seconds'];
            $previousAt = DB::table('earn_heartbeats')->where('earn_session_id', $session)->orderByDesc('created_at')->value('created_at');
            $anchorAt = Carbon::parse($previousAt ?? $row->created_at);
            $nowTs = now()->getTimestamp();
            $wall = $nowTs - $anchorAt->getTimestamp();
            $sessionAge = $nowTs - Carbon::parse($row->created_at)->getTimestamp();
            $paced = $claimed <= $wall + 2 && (int) $row->verified_seconds + $claimed <= $sessionAge + 2;
            if (! $device || ! $nonceValid || ! $data['audio_active'] || ! $rateOk || ! $integrityOk) {
                $reasons = array_keys(array_filter([
                    'device' => ! $device,
                    'nonce' => ! $nonceValid,
                    'audio_inactive' => ! $data['audio_active'],
                    'rate' => ! $rateOk,
                    'integrity' => ! $integrityOk,
                ]));
                Log::warning('earn.heartbeat', [
                    'result' => 'rejected',
                    'reason' => implode(',', $reasons),
                    'session' => $session,
                    'device' => (bool) $device,
                    'nonce' => $nonceValid,
                    'audio_active' => (bool) $data['audio_active'],
                    'rate' => $rateOk,
                    'integrity' => $integrityOk,
                    'token_kind' => str_starts_with((string) $data['integrity_token'], 'v1.') ? 'v1' : 'other',
                    'secret_set' => filled(config('services.earn_integrity.token')),
                    'secret_matches_app' => config('services.earn_integrity.token') === 'pelevo-dev-earn-integrity',
                    'sequence' => $sequenceOk,
                    'advance' => $advanceOk,
                    'expected_sequence' => (int) $row->last_sequence + 1,
                    'got_sequence' => (int) $data['sequence'],
                    'position' => (int) $data['position'],
                    'last_position' => (int) $row->last_position,
                    'verified_seconds' => (int) $row->verified_seconds,
                ]);

                return $this->hold($session, 'Heartbeat evidence failed integrity checks.');
            }
            if (! $sequenceOk || ! $advanceOk) {
                Log::warning('earn.heartbeat', [
                    'result' => 'resync',
                    'reason' => ! $sequenceOk ? 'sequence' : 'advance',
                    'session' => $session,
                    'expected_sequence' => (int) $row->last_sequence + 1,
                    'got_sequence' => (int) $data['sequence'],
                    'position' => (int) $data['position'],
                    'last_position' => (int) $row->last_position,
                    'elapsed_seconds' => $claimed,
                    'advance' => $advance,
                    'verified_seconds' => (int) $row->verified_seconds,
                ]);

                return ApiResponse::error('EARN_RESYNC', 'Listening is still in progress.', 409, [
                    'last_sequence' => (string) $row->last_sequence,
                    'last_position' => (string) $row->last_position,
                ]);
            }
            if (! $paced) {
                Log::warning('earn.heartbeat', [
                    'result' => 'pace',
                    'reason' => 'faster_than_real_time',
                    'session' => $session,
                    'claimed' => $claimed,
                    'wall' => $wall,
                    'session_age' => $sessionAge,
                    'verified_seconds' => (int) $row->verified_seconds,
                    'position' => (int) $data['position'],
                ]);

                return ApiResponse::error('VALIDATION', 'Listening evidence must match real playback time.', 422);
            }
            $fingerprint = isset($data['audio_fingerprint']) ? hash('sha256', $data['audio_fingerprint']) : null;
            if ($fingerprint && DB::table('earn_heartbeats')->where('audio_fingerprint_hash', $fingerprint)->where('ip_address', '!=', $request->ip())->where('created_at', '>', now()->subDay())->exists()) {
                Log::warning('earn.heartbeat', [
                    'result' => 'rejected',
                    'reason' => 'fingerprint',
                    'session' => $session,
                    'verified_seconds' => (int) $row->verified_seconds,
                ]);

                return $this->hold($session, 'Heartbeat requires fraud review.');
            }
            $verified = (int) $row->verified_seconds + $claimed;
            DB::table('earn_heartbeats')->insert(['id' => (string) Str::ulid(), 'earn_session_id' => $session, 'sequence' => $data['sequence'], 'position' => $data['position'], 'elapsed_seconds' => $data['elapsed_seconds'], 'playback_rate' => $data['playback_rate'], 'foreground' => $data['foreground'], 'audio_active' => $data['audio_active'], 'integrity_token_hash' => hash('sha256', $data['integrity_token']), 'audio_fingerprint_hash' => $fingerprint, 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('earn_sessions')->where('id', $session)->update(['last_position' => $data['position'], 'last_sequence' => $data['sequence'], 'verified_seconds' => $verified, 'updated_at' => now()]);
            Log::info('earn.heartbeat', [
                'result' => 'accepted',
                'session' => $session,
                'sequence' => (int) $data['sequence'],
                'position' => (int) $data['position'],
                'elapsed_seconds' => $claimed,
                'verified_seconds' => $verified,
            ]);

            return ApiResponse::success(['accepted' => true]);
        }, 3);
    }

    public function complete(string $session, Request $request, PostLedgerTransaction $post): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422);
        }

        $created = false;
        $notice = null;
        $payload = DB::transaction(function () use ($session, $request, $post, $key, &$created, &$notice): array {
            $row = DB::table('earn_sessions')->where('id', $session)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $row) {
                Log::warning('earn.complete', ['result' => 'refused', 'reason' => 'session_not_found', 'session' => $session]);

                return ['error' => ApiResponse::error('NOT_FOUND', 'Earn session not found.', 404)];
            }
            if ($row->state === 'completed') {
                Log::info('earn.complete', ['result' => 'already_awarded', 'session' => $session]);

                return ['award' => $this->presentAward(DB::table('earn_awards')->where('earn_session_id', $row->id)->first())];
            }
            if ($row->state !== 'active' || $row->risk_state !== 'clear') {
                Log::warning('earn.complete', [
                    'result' => 'refused',
                    'reason' => 'session_not_active',
                    'session' => $session,
                    'state' => $row->state,
                    'risk_state' => $row->risk_state,
                    'verified_seconds' => (int) $row->verified_seconds,
                    'last_position' => (int) $row->last_position,
                ]);

                return ['error' => ApiResponse::error('MODERATION_HOLD', 'Earn session requires review.', 409)];
            }
            $episode = Episode::findOrFail($row->episode_id);
            $required = max(1, (int) floor(((int) $episode->duration_seconds) * 0.95));
            $listenedFor = now()->getTimestamp() - Carbon::parse($row->created_at)->getTimestamp();
            if ((int) $row->verified_seconds < $required || (int) $row->last_position < $required || $listenedFor + 5 < $required) {
                Log::warning('earn.complete', [
                    'result' => 'refused',
                    'reason' => 'short',
                    'session' => $session,
                    'verified_seconds' => (int) $row->verified_seconds,
                    'last_position' => (int) $row->last_position,
                    'required' => $required,
                    'listened_for' => $listenedFor,
                    'duration_seconds' => (int) $episode->duration_seconds,
                ]);

                return ['error' => ApiResponse::error('MODERATION_HOLD', 'Not enough verified listening evidence.', 409)];
            }
            if ($this->episodeLocked($row->user_id, $row->episode_id, true)) {
                return ['error' => ApiResponse::error('EPISODE_LOCKED', 'This episode remains locked for Earn.', 409)];
            }
            $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
            $liability = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'earn_liability', 'unit' => 'ECN'], ['balance' => 0]);
            $transaction = $post->handle('earn.awarded', $key, 'ECN', [['account_id' => $liability->id, 'amount' => -$row->expected_award], ['account_id' => $wallet->id, 'amount' => $row->expected_award]], ['eligibility_snapshot' => json_decode($row->eligibility_snapshot, true)]);
            $awardId = (string) Str::ulid();
            DB::table('earn_awards')->insert(['id' => $awardId, 'user_id' => $row->user_id, 'episode_id' => $row->episode_id, 'earn_session_id' => $row->id, 'ledger_transaction_id' => $transaction->id, 'coins' => $row->expected_award, 'locked_until' => now()->addHours(data_get(json_decode($row->eligibility_snapshot, true), 'lock_hours', 72)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('earn_sessions')->where('id', $row->id)->update(['state' => 'completed', 'active_guard' => null, 'nonce_hash' => null, 'completed_at' => now(), 'updated_at' => now()]);
            Cache::forget('earn_wallet:'.$row->user_id);
            $created = true;
            Log::info('earn.complete', [
                'result' => 'awarded',
                'session' => $session,
                'coins' => (int) $row->expected_award,
                'verified_seconds' => (int) $row->verified_seconds,
                'required' => $required,
            ]);
            $notice = [
                'user_id' => (string) $row->user_id,
                'coins' => (int) $row->expected_award,
                'episode_title' => (string) $episode->title,
                'award_id' => $awardId,
            ];

            return ['award' => $this->presentAward(DB::table('earn_awards')->find($awardId))];
        }, 3);
        if (isset($payload['error'])) {
            return $payload['error'];
        }
        if ($created && is_array($notice)) {
            $this->notifyEarnAward($notice);
        }

        return ApiResponse::success($payload['award'], status: $created ? 201 : 200);
    }

    private function coinsFor(int $seconds): int
    {
        $minutes = intdiv($seconds, 60);

        return $minutes < 10 ? 1 : ($minutes < 20 ? 2 : 3);
    }

    private function episodeLocked(string $userId, string $episodeId, bool $lock = false): bool
    {
        $query = DB::table('earn_awards')
            ->where('user_id', $userId)
            ->where('episode_id', $episodeId)
            ->whereNull('unlocked_at')
            ->where('locked_until', '>', now());
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    private function fraudHold(Request $request): ?JsonResponse
    {
        $ip = (string) $request->ip();
        $ipUsers = $ip === '' ? 0 : DB::table('earn_sessions')->where('ip_address', $ip)->where('created_at', '>=', now()->subHour())->distinct()->count('user_id');
        if ($ipUsers >= (int) config('finance.earn_ip_user_cap', 12)) {
            return ApiResponse::error('MODERATION_HOLD', 'Earn needs a fraud review before another session can start.', 409);
        }
        $identifier = (string) $request->header('X-Device-Id');
        $deviceIds = Device::query()->where('device_identifier', $identifier)->pluck('id');
        $otherUsers = $deviceIds->isEmpty() ? 0 : DB::table('earn_sessions')->whereIn('device_id', $deviceIds)->where('user_id', '!=', $request->user()->id)->where('created_at', '>=', now()->subDay())->distinct()->count('user_id');
        if ($otherUsers >= 1) {
            return ApiResponse::error('MODERATION_HOLD', 'Earn needs a fraud review before another session can start.', 409);
        }

        return null;
    }

    private function isUniqueActiveSessionConflict(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($e->getMessage(), 'earn_one_active_session');
    }

    private function hold(string $session, string $message): JsonResponse
    {
        DB::table('earn_sessions')->where('id', $session)->update(['risk_state' => 'review', 'state' => 'review', 'active_guard' => null, 'updated_at' => now()]);

        return ApiResponse::error('MODERATION_HOLD', $message, 409);
    }

    /** @param array{user_id: string, coins: int, episode_title: string, award_id: string} $notice */
    private function notifyEarnAward(array $notice): void
    {
        $coins = $notice['coins'];
        $label = $coins === 1 ? '1 coin' : $coins.' coins';
        try {
            app(InAppNotificationDelivery::class)->deliver($notice['user_id'], [
                'type' => 'earn_award',
                'key' => 'earn-award:'.$notice['award_id'],
                'title' => 'You earned '.$label,
                'body' => 'Your Earn listen of '.$notice['episode_title'].' is complete. This episode stays locked for 72 hours.',
                'data' => [
                    'type' => 'earn_award',
                    'coins' => (string) $coins,
                    'award_id' => $notice['award_id'],
                ],
            ]);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    private function presentSession(string $id, string $nonce): array
    {
        $row = DB::table('earn_sessions')->where('id', $id)->first();
        $snapshot = json_decode((string) $row?->eligibility_snapshot, true);

        return [
            'id' => $row?->id, 'episode_id' => $row?->episode_id, 'expected_award' => (int) $row?->expected_award,
            'nonce' => $nonce, 'nonce_expires_at' => $row?->nonce_expires_at, 'lock_hours' => (int) data_get($snapshot, 'lock_hours', 72),
        ];
    }

    private function presentAward(?object $row): array
    {
        return [
            'id' => $row?->id, 'episode_id' => $row?->episode_id, 'coins' => (int) $row?->coins,
            'locked_until' => $row?->locked_until,
        ];
    }
}
