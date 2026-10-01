<?php

namespace App\Services\Reels;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ReelAdCycleService
{
    public function recordValidView(
        string $userId,
        string $feedSessionId,
        string $reelViewId,
        string $platform,
        ?string $installationId,
    ): ?array {
        if (! config('reels_ads.enabled') || ! in_array($platform, ['android', 'ios'], true)) {
            return null;
        }

        $key = "reels:ad-cycle:{$userId}:{$feedSessionId}";
        $lock = Cache::lock($key.':lock', 10);

        try {
            return $lock->block(5, function () use ($key, $userId, $feedSessionId, $reelViewId, $platform, $installationId): ?array {
                if (DB::table('ad_impression_attributions')->where('reel_view_id', $reelViewId)->exists()) {
                    return null;
                }

                $state = Cache::get($key);
                $state = is_array($state) ? $state : $this->freshState();
                $viewIds = array_values(array_unique(array_map('strval', $state['reel_view_ids'] ?? [])));

                if (! in_array($reelViewId, $viewIds, true)) {
                    $viewIds[] = $reelViewId;
                }

                $threshold = (int) ($state['threshold'] ?? $this->threshold());
                if (count($viewIds) < $threshold) {
                    Cache::put($key, [
                        'cycle_id' => $state['cycle_id'] ?? (string) Str::uuid(),
                        'threshold' => $threshold,
                        'reel_view_ids' => $viewIds,
                        'started_at' => $state['started_at'] ?? now()->toIso8601String(),
                    ], now()->addSeconds((int) config('reels_ads.cycle_ttl_seconds')));

                    return null;
                }

                $impression = $this->snapshot(
                    $userId,
                    $feedSessionId,
                    array_slice($viewIds, 0, $threshold),
                    $threshold,
                    $platform,
                    $installationId,
                );

                if ($impression === null) {
                    Cache::put($key, [
                        'cycle_id' => $state['cycle_id'] ?? (string) Str::uuid(),
                        'threshold' => $threshold,
                        'reel_view_ids' => $viewIds,
                        'started_at' => $state['started_at'] ?? now()->toIso8601String(),
                    ], now()->addSeconds((int) config('reels_ads.cycle_ttl_seconds')));

                    return null;
                }

                $remaining = array_slice($viewIds, $threshold);
                $next = $this->freshState();
                $next['reel_view_ids'] = $remaining;
                Cache::put($key, $next, now()->addSeconds((int) config('reels_ads.cycle_ttl_seconds')));

                return $impression;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function snapshot(
        string $userId,
        string $feedSessionId,
        array $viewIds,
        int $threshold,
        string $platform,
        ?string $installationId,
    ): ?array {
        return DB::transaction(function () use ($userId, $feedSessionId, $viewIds, $threshold, $platform, $installationId): ?array {
            $views = DB::table('reel_views')
                ->join('reels', 'reels.id', '=', 'reel_views.reel_id')
                ->leftJoin('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')
                ->whereIn('reel_views.id', $viewIds)
                ->where('reel_views.user_id', $userId)
                ->where('reel_views.is_valid_view', true)
                ->whereNotNull('reel_views.counted_at')
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('ad_impression_attributions')
                    ->whereColumn('ad_impression_attributions.reel_view_id', 'reel_views.id'))
                ->select(
                    'reel_views.id as reel_view_id',
                    'reel_views.reel_id',
                    'reel_views.counted_at',
                    'reel_views.fraud_state as view_fraud_state',
                    'reels.creator_profile_id',
                    'reels.duration_ms',
                    'creator_profiles.user_id as creator_user_id',
                )
                ->lockForUpdate()
                ->get()
                ->keyBy('reel_view_id');

            $ordered = collect($viewIds)->map(fn (string $id) => $views->get($id))->filter()->values();
            if ($ordered->count() < $threshold) {
                return null;
            }

            $id = (string) Str::uuid();
            $now = now();
            $fraudState = $ordered->contains(fn (object $view): bool => $view->view_fraud_state !== 'clear'
                || ! $view->creator_profile_id
                || $view->creator_user_id === $userId)
                || $this->isImplausiblyFast($ordered)
                ? 'review'
                : 'clear';
            DB::table('ad_impressions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'feed_session_id' => $feedSessionId,
                'installation_hash' => $installationId ? hash('sha256', $installationId) : null,
                'platform' => $platform,
                'ad_unit_key' => (string) config("reels_ads.ad_unit_keys.{$platform}"),
                'triggered_at' => $now,
                'expires_at' => $now->copy()->addSeconds((int) config('reels_ads.pending_ttl_seconds')),
                'status' => 'pending',
                'threshold' => $threshold,
                'eligible_view_count' => $ordered->count(),
                'payout_pool_usd_micros' => (int) config('reels_ads.payout_pool_usd_micros'),
                'reserve_bps' => (int) config('reels_ads.reserve_bps'),
                'fraud_state' => $fraudState,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($ordered as $view) {
                if (! $view->creator_profile_id) {
                    continue;
                }
                DB::table('ad_impression_attributions')->insert([
                    'ad_impression_id' => $id,
                    'reel_id' => $view->reel_id,
                    'creator_profile_id' => $view->creator_profile_id,
                    'reel_view_id' => $view->reel_view_id,
                    'counted_at' => $view->counted_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Log::info('reels_ad.cycle_reached', [
                'ad_impression_id' => $id,
                'platform' => $platform,
                'threshold' => $threshold,
                'attribution_count' => $ordered->filter(fn (object $view): bool => (bool) $view->creator_profile_id)->count(),
                'fraud_state' => $fraudState,
            ]);

            return [
                'ad_impression_id' => $id,
                'expires_at' => $now->copy()->addSeconds((int) config('reels_ads.pending_ttl_seconds'))->toIso8601String(),
                'ad_unit_key' => (string) config("reels_ads.ad_unit_keys.{$platform}"),
                'format' => 'native_advanced',
            ];
        }, 3);
    }

    private function freshState(): array
    {
        return [
            'cycle_id' => (string) Str::uuid(),
            'threshold' => $this->threshold(),
            'reel_view_ids' => [],
            'started_at' => now()->toIso8601String(),
        ];
    }

    private function isImplausiblyFast($views): bool
    {
        $ordered = $views->sortBy('counted_at')->values();
        if ($ordered->count() < 2) {
            return false;
        }

        $first = CarbonImmutable::parse($ordered->first()->counted_at);
        $last = CarbonImmutable::parse($ordered->last()->counted_at);
        $elapsedMs = max(0, $first->diffInMilliseconds($last));
        $minimumMs = $ordered
            ->slice(1)
            ->sum(fn (object $view): int => min(
                (int) config('reels_ads.valid_view_ms'),
                max(0, (int) $view->duration_ms),
            ));
        $networkGraceMs = (int) config('reels_ads.heartbeat_grace_ms') * ($ordered->count() - 1);

        return $elapsedMs + $networkGraceMs < $minimumMs;
    }

    private function threshold(): int
    {
        $minimum = max(1, (int) config('reels_ads.threshold_min', 5));
        $maximum = max($minimum, (int) config('reels_ads.threshold_max', 8));

        return random_int($minimum, $maximum);
    }
}
