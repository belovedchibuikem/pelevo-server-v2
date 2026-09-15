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
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EarnController extends Controller
{
    public function shows(): JsonResponse
    {
        $page = Show::query()->whereHas('episodes', fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60))->withCount(['episodes as episodes_count' => fn ($query) => $query->whereNotNull('duration_seconds')->where('duration_seconds', '>=', 60)])->orderByDesc('episodes_count')->orderBy('id')->cursorPaginate(20);

        return ApiResponse::success(collect($page->items())->map(fn (Show $show): array => [
            'id' => $show->id, 'title' => $show->title, 'author' => $show->author, 'artwork_url' => $show->artwork_url, 'episodes_count' => (int) $show->episodes_count,
        ])->values(), ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    public function episodes(Request $request): JsonResponse
    {
        $query = Episode::query()->join('shows', 'shows.id', '=', 'episodes.show_id')->whereNotNull('episodes.duration_seconds')->where('episodes.duration_seconds', '>=', 60)->select('episodes.id', 'episodes.show_id', 'episodes.title', 'episodes.duration_seconds', 'episodes.audio_url', 'shows.title as show_title', 'shows.author as show_author', 'shows.artwork_url as artwork_url')->orderByDesc('episodes.published_at')->orderByDesc('episodes.id');
        if ($request->filled('show_id')) {
            $query->where('episodes.show_id', $request->validate(['show_id' => ['required', 'exists:shows,id']])['show_id']);
        }
        $page = $query->cursorPaginate(20);

        return ApiResponse::success(collect($page->items())->map(fn (object $row): array => [
            'id' => $row->id, 'show_id' => $row->show_id, 'show_title' => $row->show_title, 'show_author' => $row->show_author, 'title' => $row->title, 'artwork_url' => $row->artwork_url, 'duration_seconds' => (int) $row->duration_seconds, 'audio_url' => $row->audio_url,
        ])->values(), ['cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $balance = FinancialAccount::where('owner_type', get_class($request->user()))->where('owner_id', $request->user()->id)->where('type', 'earn_wallet')->where('unit', 'ECN')->value('balance') ?? 0;

        $configuration = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first();

        return ApiResponse::success([
            'user_id' => $request->user()->id, 'unit' => 'ECN', 'balance' => (string) $balance,
            'minimum_withdrawal' => (string) data_get($configuration?->payload, 'money.earn_min_withdraw_coins', config('finance.earn_min_withdraw_coins')),
            'withdrawals_enabled' => (bool) config('finance.public_enabled'),
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
        if (DB::table('earn_awards')->where('user_id', $request->user()->id)->where('episode_id', $episode->id)->where('locked_until', '>', now())->exists()) {
            return ApiResponse::error('EPISODE_LOCKED', 'This episode remains locked for Earn.', 409);
        }
        $config = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first();
        $minutes = intdiv((int) $episode->duration_seconds, 60);
        $award = $minutes < 10 ? 1 : ($minutes < 20 ? 2 : 3);
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
        $data = $request->validate(['position' => ['required', 'integer', 'min:0'], 'sequence' => ['required', 'integer', 'min:1'], 'elapsed_seconds' => ['required', 'integer', 'between:15,30'], 'playback_rate' => ['required', 'numeric', 'between:0.5,2'], 'foreground' => ['required', 'boolean'], 'audio_active' => ['required', 'boolean'], 'integrity_token' => ['required', 'string', 'max:4096'], 'nonce' => ['required', 'string', 'size:64'], 'audio_fingerprint' => ['nullable', 'string', 'max:500']]);

        return DB::transaction(function () use ($session, $request, $data, $integrity): JsonResponse {
            $row = DB::table('earn_sessions')->where('id', $session)->where('user_id', $request->user()->id)->where('state', 'active')->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('NOT_FOUND', 'Earn session not found.', 404);
            }
            $device = Device::where('id', $row->device_id)->where('device_identifier', $request->header('X-Device-Id'))->whereNull('revoked_at')->first();
            $nonceValid = $row->nonce_hash && $row->nonce_expires_at && now()->lte($row->nonce_expires_at) && hash_equals($row->nonce_hash, hash('sha256', $data['nonce']));
            $invalid = ! $device || ! $nonceValid || ! $data['foreground'] || ! $data['audio_active'] || ! $integrity->valid($data['integrity_token'], (string) $request->header('X-Device-Id'), $session, (int) $data['sequence'], $data['nonce']) || $data['sequence'] !== $row->last_sequence + 1 || $data['position'] < $row->last_position || (int) ceil($data['elapsed_seconds'] * min(2, $data['playback_rate']) + 3) < $data['position'] - $row->last_position;
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
            if ($row->verified_seconds < min((int) $episode->duration_seconds, 600)) {
                return ApiResponse::error('MODERATION_HOLD', 'Not enough verified listening evidence.', 409);
            }
            if (DB::table('earn_awards')->where('user_id', $row->user_id)->where('episode_id', $row->episode_id)->where('locked_until', '>', now())->lockForUpdate()->exists()) {
                return ApiResponse::error('EPISODE_LOCKED', 'This episode remains locked for Earn.', 409);
            }
            $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($request->user()), 'owner_id' => $request->user()->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
            $liability = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'earn_liability', 'unit' => 'ECN'], ['balance' => 0]);
            $transaction = $post->handle('earn.awarded', $key, 'ECN', [['account_id' => $liability->id, 'amount' => -$row->expected_award], ['account_id' => $wallet->id, 'amount' => $row->expected_award]], ['eligibility_snapshot' => json_decode($row->eligibility_snapshot, true)]);
            $awardId = (string) Str::ulid();
            DB::table('earn_awards')->insert(['id' => $awardId, 'user_id' => $row->user_id, 'episode_id' => $row->episode_id, 'earn_session_id' => $row->id, 'ledger_transaction_id' => $transaction->id, 'coins' => $row->expected_award, 'locked_until' => now()->addHours(data_get(json_decode($row->eligibility_snapshot, true), 'lock_hours', 72)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('earn_sessions')->where('id', $row->id)->update(['state' => 'completed', 'active_guard' => null, 'nonce_hash' => null, 'completed_at' => now(), 'updated_at' => now()]);

            return ApiResponse::success($this->presentAward(DB::table('earn_awards')->find($awardId)), status: 201);
        }, 3);
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
