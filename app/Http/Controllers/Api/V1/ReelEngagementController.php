<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function heartbeat(string $reel, Request $request): JsonResponse
    {
        $limits = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first()?->payload['limits'] ?? [];
        $data = $request->validate(['session_id' => ['required', 'uuid'], 'watched_ms' => ['required', 'integer', 'min:0', 'max:86400000']]);
        abort_unless(DB::table('reels')->where('id', $reel)->where('state', 'published')->exists(), 404);
        $view = DB::transaction(function () use ($reel, $request, $data, $limits): object {
            $existing = DB::table('reel_views')
                ->where('reel_id', $reel)
                ->where('user_id', $request->user()->id)
                ->where('session_id', $data['session_id'])
                ->lockForUpdate()
                ->first();
            $watched = max((int) ($existing?->watched_ms ?? 0), (int) $data['watched_ms']);
            $threshold = (int) ($limits['reel_qualified_view_ms'] ?? config('media.qualified_view_ms'));
            $qualified = (bool) ($existing?->qualified ?? false) || $watched >= $threshold;
            $attributes = [
                'watched_ms' => $watched,
                'qualified' => $qualified,
                'qualified_at' => $qualified ? ($existing?->qualified_at ?? now()) : null,
                'updated_at' => now(),
            ];
            $viewId = isset($existing->id) && is_string($existing->id) && $existing->id !== ''
                ? $existing->id
                : (string) Str::ulid();
            if ($existing) {
                DB::table('reel_views')->where('id', $viewId)->update($attributes);
            } else {
                DB::table('reel_views')->insert([
                    'id' => $viewId,
                    'reel_id' => $reel,
                    'user_id' => $request->user()->id,
                    'session_id' => $data['session_id'],
                    ...$attributes,
                    'created_at' => now(),
                ]);
            }

            if ($qualified && ! DB::table('reel_view_credits')->where('reel_view_id', $viewId)->exists()) {
                $windowSeconds = max(60, ($limits['reel_view_window_minutes'] ?? config('media.view_window_minutes')) * 60);
                $windowKey = (string) intdiv(now()->timestamp, $windowSeconds);
                $maxOrdinal = max(1, $limits['reel_qualified_views_per_window'] ?? config('media.qualified_views_per_window'));
                for ($ordinal = 1; $ordinal <= $maxOrdinal; $ordinal++) {
                    if (DB::table('reel_view_credits')->insertOrIgnore([
                        'reel_id' => $reel,
                        'user_id' => $request->user()->id,
                        'window_key' => $windowKey,
                        'ordinal' => $ordinal,
                        'reel_view_id' => $viewId,
                        'created_at' => now(),
                    ])) {
                        break;
                    }
                }
            }

            $view = DB::table('reel_views')->where('id', $viewId)->first()
                ?? (object) ['id' => $viewId, 'watched_ms' => $watched, 'qualified' => $qualified];
            $view->counted = DB::table('reel_view_credits')->where('reel_view_id', $viewId)->exists();

            return $view;
        });

        return ApiResponse::success($view);
    }

    private function transition(string $reel, Request $request, string $action): JsonResponse
    {
        $request->merge(['action' => $action]);

        return $this->update($reel, $request);
    }
}
