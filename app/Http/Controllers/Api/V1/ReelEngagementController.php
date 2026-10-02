<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reels\ReelAdCycleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReelEngagementController extends Controller
{
    public function like(string $reel, Request $request): JsonResponse
    {
        return $this->transition($reel, $request, 'like');
    }

    public function unlike(string $reel, Request $request): JsonResponse
    {
        return $this->transition($reel, $request, 'unlike');
    }

    public function save(string $reel, Request $request): JsonResponse
    {
        return $this->transition($reel, $request, 'save');
    }

    public function unsave(string $reel, Request $request): JsonResponse
    {
        return $this->transition($reel, $request, 'unsave');
    }

    public function notInterested(string $reel, Request $request): JsonResponse
    {
        return $this->transition($reel, $request, 'not_interested');
    }

    public function update(string $reel, Request $request): JsonResponse
    {
        abort_unless(DB::table('reels')->where('id', $reel)->where('state', 'published')->exists(), 404);
        $data = $request->validate([
            'action' => ['required', 'in:like,unlike,save,unsave,not_interested,interested'],
        ]);
        $values = match ($data['action']) {
            'like' => ['liked' => true], 'unlike' => ['liked' => false],
            'save' => ['saved' => true], 'unsave' => ['saved' => false],
            'not_interested' => ['not_interested' => true],
            default => ['not_interested' => false],
        };
        DB::table('reel_engagements')->upsert([[
            'reel_id' => $reel, 'user_id' => $request->user()->id,
            'liked' => false, 'saved' => false, 'not_interested' => false,
            ...$values, 'created_at' => now(), 'updated_at' => now(),
        ]], ['reel_id', 'user_id'], [...array_keys($values), 'updated_at']);

        return ApiResponse::success(DB::table('reel_engagements')->where('reel_id', $reel)->where('user_id', $request->user()->id)->first());
    }

    public function heartbeat(string $reel, Request $request, ReelAdCycleService $adCycles): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'uuid'],
            'feed_session_id' => ['nullable', 'uuid'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'playback_position_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'watched_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'completed' => ['sometimes', 'boolean'],
            'app_foreground' => ['sometimes', 'boolean'],
            'audible' => ['sometimes', 'boolean'],
            'ad_eligible' => ['sometimes', 'boolean'],
        ]);
        $reelRow = DB::table('reels')
            ->leftJoin('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')
            ->where('reels.id', $reel)
            ->where('reels.state', 'published')
            ->select('reels.id', 'reels.duration_ms', 'reels.creator_profile_id', 'creator_profiles.user_id as creator_user_id')
            ->first();
        abort_unless($reelRow, 404);

        $feedSessionId = $data['feed_session_id'] ?? $data['session_id'];
        $position = (int) ($data['playback_position_ms'] ?? $data['watched_ms'] ?? 0);
        $sequence = (int) ($data['sequence'] ?? max(1, (int) floor($position / max(1, (int) config('reels_ads.heartbeat_interval_ms'))) + 1));
        $foreground = (bool) ($data['app_foreground'] ?? true);
        $audible = (bool) ($data['audible'] ?? true);
        $completed = (bool) ($data['completed'] ?? false);
        $now = now();

        $result = DB::transaction(function () use (
            $reel,
            $reelRow,
            $request,
            $data,
            $feedSessionId,
            $position,
            $sequence,
            $foreground,
            $audible,
            $completed,
            $now,
        ): array {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('reel_views')
                ->where('reel_id', $reel)
                ->where('user_id', $request->user()->id)
                ->where('session_id', $data['session_id'])
                ->lockForUpdate()
                ->first();
            $viewId = isset($existing->id) && is_string($existing->id) && $existing->id !== ''
                ? $existing->id
                : (string) Str::ulid();

            if ($existing && $sequence <= (int) ($existing->last_sequence ?? 0)) {
                return [
                    'view_id' => $viewId,
                    'server_watched_ms' => (int) ($existing->server_watched_ms ?? 0),
                    'is_valid_view' => (bool) ($existing->is_valid_view ?? false),
                    'became_valid' => false,
                    'heartbeat_accepted' => false,
                    'rejection_reason' => 'sequence_replayed',
                ];
            }

            $lastHeartbeatAt = $existing?->last_heartbeat_at ? now()->parse($existing->last_heartbeat_at) : null;
            $lastPosition = (int) ($existing?->watched_ms ?? 0);
            $positionDelta = max(0, $position - $lastPosition);
            $interval = max(1000, (int) config('reels_ads.heartbeat_interval_ms'));
            $grace = max(0, (int) config('reels_ads.heartbeat_grace_ms'));
            $wallClockAllowance = $lastHeartbeatAt
                ? min($interval + $grace, max(0, (int) floor($lastHeartbeatAt->diffInMilliseconds($now))))
                : min($interval + $grace, $position);
            $countedDelta = ($foreground && $audible)
                ? (int) min($positionDelta, $wallClockAllowance)
                : 0;
            $serverWatched = (int) ($existing?->server_watched_ms ?? 0) + $countedDelta;
            $duration = max(0, (int) ($reelRow->duration_ms ?? 0));
            $longThreshold = max(1000, (int) config('reels_ads.valid_view_ms'));
            $shortCompletion = $duration > 0
                && $duration < $longThreshold
                && $completed
                && $position >= max(0, $duration - 500)
                && $serverWatched >= max(0, $duration - $grace);
            $eligible = $serverWatched >= $longThreshold || $shortCompletion;
            $alreadyValid = (bool) ($existing?->is_valid_view ?? false);
            $becameValid = false;
            $countedAt = $existing?->counted_at;

            if ($eligible && ! $alreadyValid) {
                $duplicate = DB::table('reel_views')
                    ->where('user_id', $request->user()->id)
                    ->where('reel_id', $reel)
                    ->where('id', '!=', $viewId)
                    ->whereNotNull('counted_at')
                    ->where('counted_at', '>', $now->copy()->subHours((int) config('reels_ads.dedup_hours')))
                    ->exists();
                if (! $duplicate) {
                    $becameValid = true;
                    $countedAt = $now;
                }
            }

            $isValid = $alreadyValid || $becameValid;
            $fraudState = $positionDelta > ($interval + $grace) * 3 ? 'review' : ($existing?->fraud_state ?? 'clear');
            $attributes = [
                'feed_session_id' => $feedSessionId,
                'watched_ms' => max($lastPosition, $position),
                'server_watched_ms' => $serverWatched,
                'last_sequence' => $sequence,
                'last_heartbeat_at' => $now,
                'completed_at' => $completed ? ($existing?->completed_at ?? $now) : $existing?->completed_at,
                'qualified' => $eligible || (bool) ($existing?->qualified ?? false),
                'qualified_at' => $eligible ? ($existing?->qualified_at ?? $now) : $existing?->qualified_at,
                'is_valid_view' => $isValid,
                'counted_at' => $countedAt,
                'fraud_state' => $fraudState,
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('reel_views')->where('id', $viewId)->update($attributes);
            } else {
                DB::table('reel_views')->insert([
                    'id' => $viewId,
                    'reel_id' => $reel,
                    'user_id' => $request->user()->id,
                    'session_id' => $data['session_id'],
                    ...$attributes,
                    'created_at' => $now,
                ]);
            }

            DB::table('reel_view_heartbeats')->insert([
                'id' => (string) Str::ulid(),
                'reel_view_id' => $viewId,
                'user_id' => $request->user()->id,
                'reel_id' => $reel,
                'session_id' => $data['session_id'],
                'sequence' => $sequence,
                'received_at' => $now,
                'playback_position_ms' => $position,
                'server_counted_ms' => $countedDelta,
                'completed' => $completed,
                'app_foreground' => $foreground,
                'audible' => $audible,
                'integrity_result' => $request->header('X-Earn-Integrity') ? 'provided' : 'unverified',
                'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
                'device_hash' => $request->header('X-Device-Id')
                    ? hash('sha256', (string) $request->header('X-Device-Id'))
                    : null,
                'created_at' => $now,
            ]);

            return [
                'view_id' => $viewId,
                'server_watched_ms' => $serverWatched,
                'is_valid_view' => $isValid,
                'became_valid' => $becameValid,
                'heartbeat_accepted' => true,
                'rejection_reason' => null,
                'feed_session_id' => $feedSessionId,
            ];
        }, 3);

        $adDue = null;
        if (
            $result['became_valid']
            && (bool) ($data['ad_eligible'] ?? false)
            && $reelRow->creator_user_id !== $request->user()->id
            && $result['heartbeat_accepted']
        ) {
            $adDue = $adCycles->recordValidView(
                (string) $request->user()->id,
                (string) $feedSessionId,
                (string) $result['view_id'],
                strtolower((string) $request->header('X-Platform', '')),
                $request->header('X-Device-Id'),
            );
        }
        Log::info('reels_ad.heartbeat', [
            'user_hash' => hash('sha256', (string) $request->user()->id),
            'platform' => strtolower((string) $request->header('X-Platform', 'unknown')),
            'accepted' => $result['heartbeat_accepted'],
            'rejection_reason' => $result['rejection_reason'],
            'became_valid' => $result['became_valid'],
            'ad_due' => $adDue !== null,
        ]);

        return ApiResponse::success([
            ...$result,
            'counted' => $result['is_valid_view'],
            'qualified' => $result['is_valid_view'],
            'ad_due' => $adDue,
        ]);
    }

    private function transition(string $reel, Request $request, string $action): JsonResponse
    {
        $request->merge(['action' => $action]);

        return $this->update($reel, $request);
    }
}
