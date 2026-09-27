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
use App\Support\ApiResponse;
use App\Support\EarnRegion;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function shows(): JsonResponse
    {
        $page = Show::query()
            ->where('shows.earn_enabled', true)
            ->where('shows.status', 'active')
            ->whereHas('episodes', fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60))
            ->leftJoinSub($this->nicheQuery(), 'earn_niches', 'earn_niches.show_id', '=', 'shows.id')
            ->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url', 'shows.earn_position', 'earn_niches.niche')
            ->withCount(['episodes as episodes_count' => fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60)])
            ->orderByRaw('CASE WHEN earn_niches.niche IS NULL THEN 1 ELSE 0 END')
            ->orderBy('earn_niches.niche')
            ->orderBy('shows.earn_position')
            ->orderBy('shows.title')
            ->orderBy('shows.id')
            ->cursorPaginate(20);

        return ApiResponse::success(collect($page->items())->map(fn (Show $show): array => [
            'id' => $show->id,
            'title' => $show->title,
            'author' => $show->author,
            'artwork_url' => $show->artwork_url,
            'episodes_count' => (int) $show->episodes_count,
            'niche' => $show->getAttribute('niche'),
        ])->values(), ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    public function episodes(Request $request): JsonResponse
    {
        $query = Episode::query()
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->where('shows.earn_enabled', true)
            ->where('shows.status', 'active')
            ->whereNotNull('episodes.duration_seconds')
            ->where('episodes.duration_seconds', '>=', 60)
            ->leftJoinSub($this->nicheQuery(), 'earn_niches', 'earn_niches.show_id', '=', 'shows.id')
            ->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.duration_seconds', 'episodes.audio_url', 'shows.title as show_title', 'shows.author as show_author', 'shows.artwork_url as artwork_url', 'earn_niches.niche')
            ->orderByDesc('episodes.published_at')
            ->orderByDesc('episodes.id');
        if ($request->filled('show_id')) {
            $query->where('episodes.show_id', $request->validate(['show_id' => ['required', 'exists:shows,id']])['show_id']);
        }
        $page = $query->cursorPaginate(20);
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
        })->values(), ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
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
                if (! $stale) {
                    return ApiResponse::error('MODERATION_HOLD', 'Only one Earn session may be active.', 409);
                }
                DB::table('earn_sessions')->where('id', $existing->id)->update([
                    'state' => 'expired',
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
        $data = $request->validate(['position' => ['required', 'integer', 'min:0'], 'sequence' => ['required', 'integer', 'min:1'], 'elapsed_seconds' => ['required', 'integer', 'between:15,30'], 'playback_rate' => ['required', 'numeric'], 'foreground' => ['required', 'boolean'], 'audio_active' => ['required', 'boolean'], 'integrity_token' => ['required', 'string', 'max:4096'], 'nonce' => ['required', 'string', 'size:64'], 'audio_fingerprint' => ['nullable', 'string', 'max:500']]);

        return DB::transaction(function () use ($session, $request, $data, $integrity): JsonResponse {
            $row = DB::table('earn_sessions')->where('id', $session)->where('user_id', $request->user()->id)->where('state', 'active')->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Earn session not found.', 404);
            }
            $device = Device::where('id', $row->device_id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
            $nonceValid = $row->nonce_hash && $row->nonce_expires_at && now()->lte($row->nonce_expires_at) && hash_equals($row->nonce_hash, hash('sha256', $data['nonce']));
            $advance = (int) $data['position'] - (int) $row->last_position;
            $invalid = ! $device || ! $nonceValid || ! $data['foreground'] || ! $data['audio_active'] || abs((float) $data['playback_rate'] - 1.0) > 0.01 || ! $integrity->valid($data['integrity_token'], (string) $request->header('X-Device-Id'), $session, (int) $data['sequence'], $data['nonce']) || (int) $data['sequence'] !== (int) $row->last_sequence + 1 || $advance < 0 || $advance > (int) $data['elapsed_seconds'] + 3;
            if ($invalid) {
                return $this->hold($session, 'Heartbeat evidence failed integrity checks.');
            }
            $fingerprint = isset($data['audio_fingerprint']) ? hash('sha256', $data['audio_fingerprint']) : null;
            if ($fingerprint && DB::table('earn_heartbeats')->where('audio_fingerprint_hash', $fingerprint)->where('ip_address', '!=', $request->ip())->where('created_at', '>', now()->subDay())->exists()) {
                return $this->hold($session, 'Heartbeat requires fraud review.');
            }
            DB::table('earn_heartbeats')->insert(['id' => (string) Str::ulid(), 'earn_session_id' => $session, 'sequence' => $data['sequence'], 'position' => $data['position'], 'elapsed_seconds' => $data['elapsed_seconds'], 'playback_rate' => $data['playback_rate'], 'foreground' => $data['foreground'], 'audio_active' => $data['audio_active'], 'integrity_token_hash' => hash('sha256', $data['integrity_token']), 'audio_fingerprint_hash' => $fingerprint, 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('earn_sessions')->where('id', $session)->update(['last_position' => $data['position'], 'last_sequence' => $data['sequence'], 'verified_seconds' => $row->verified_seconds + $data['elapsed_seconds'], 'updated_at' => now()]);

            return ApiResponse::success(['accepted' => true]);
        }, 3);
    }

    public function complete(string $session, Request $request, PostLedgerTransaction $post): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '') {
            return ApiResponse::error('VALIDATION', 'Idempotency-Key header is required.', 422);
        }

        return DB::transaction(function () use ($session, $request, $post, $key): JsonResponse {
            $row = DB::table('earn_sessions')->where('id', $session)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Earn session not found.', 404);
            }
            if ($row->state === 'completed') {
                return ApiResponse::success($this->presentAward(DB::table('earn_awards')->where('earn_session_id', $row->id)->first()));
            }
            if ($row->state !== 'active' || $row->risk_state !== 'clear') {
                return ApiResponse::error('MODERATION_HOLD', 'Earn session requires review.', 409);
            }
            $episode = Episode::findOrFail($row->episode_id);
            $required = max(1, (int) floor(((int) $episode->duration_seconds) * 0.95));
            if ((int) $row->verified_seconds < $required || (int) $row->last_position < $required) {
                return ApiResponse::error('MODERATION_HOLD', 'Not enough verified listening evidence.', 409);
            }
            if ($this->episodeLocked($row->user_id, $row->episode_id, true)) {
                return ApiResponse::error('EPISODE_LOCKED', 'This episode remains locked for Earn.', 409);
            }
            $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
            $liability = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'earn_liability', 'unit' => 'ECN'], ['balance' => 0]);
            $transaction = $post->handle('earn.awarded', $key, 'ECN', [['account_id' => $liability->id, 'amount' => -$row->expected_award], ['account_id' => $wallet->id, 'amount' => $row->expected_award]], ['eligibility_snapshot' => json_decode($row->eligibility_snapshot, true)]);
            $awardId = (string) Str::ulid();
            DB::table('earn_awards')->insert(['id' => $awardId, 'user_id' => $row->user_id, 'episode_id' => $row->episode_id, 'earn_session_id' => $row->id, 'ledger_transaction_id' => $transaction->id, 'coins' => $row->expected_award, 'locked_until' => now()->addHours(data_get(json_decode($row->eligibility_snapshot, true), 'lock_hours', 72)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('earn_sessions')->where('id', $row->id)->update(['state' => 'completed', 'active_guard' => null, 'nonce_hash' => null, 'completed_at' => now(), 'updated_at' => now()]);
            Cache::forget('earn_wallet:'.$row->user_id);

            return ApiResponse::success($this->presentAward(DB::table('earn_awards')->find($awardId)), status: 201);
        }, 3);
    }

    private function nicheQuery()
    {
        return DB::table('shows')
            ->leftJoin('categories', function ($join): void {
                $join->on('categories.id', '=', 'shows.earn_category_id')->where('categories.active', true);
            })
            ->select('shows.id as show_id', 'categories.name as niche');
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
        DB::table('earn_sessions')->where('id', $session)->update(['risk_state' => 'review', 'state' => 'review', 'updated_at' => now()]);

        return ApiResponse::error('MODERATION_HOLD', $message, 409);
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
