<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\ConsumeHomeDailyPick;
use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Episode;
use App\Models\PlaybackProgress;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlaybackController extends Controller
{
    public function show(Episode $episode, Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($request->user()->id, $episode->id, PlaybackProgress::firstOrNew(['user_id' => $request->user()->id, 'episode_id' => $episode->id], ['position_seconds' => 0, 'completed' => false, 'version' => 0])));
    }

    public function update(Episode $episode, Request $request, InvalidateDiscoveryCache $cache, ConsumeHomeDailyPick $consumePick): JsonResponse
    {
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0'], 'completed' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']]);
        $progress = PlaybackProgress::where('user_id', $request->user()->id)->where('episode_id', $episode->id)->first();
        if ($progress && $progress->version !== $data['version']) {
            return ApiResponse::error('VERSION_CONFLICT', 'Playback progress changed on another device.', 409);
        }
        $device = Device::where('user_id', $request->user()->id)->where('device_identifier', $request->header('X-Device-Id'))->first();
        $position = max(0, (int) $data['position_seconds']);
        $duration = (int) ($episode->duration_seconds ?? 0);
        if ($duration > 0) {
            $position = min($position, $duration);
        }
        $completed = $this->isCompleted($duration, $position, (bool) $data['completed']);
        $progress = PlaybackProgress::updateOrCreate(['user_id' => $request->user()->id, 'episode_id' => $episode->id], ['device_id' => $device?->id, 'position_seconds' => $position, 'completed' => $completed, 'version' => ($progress?->version ?? 0) + 1]);
        if ($position > 0 || $completed) {
            $consumePick->handle($request->user()->id, $episode->id);
        }
        $cache->user($request->user()->id);

        return ApiResponse::success($this->present($request->user()->id, $episode->id, $progress));
    }

    private function present(string $owner, string $episodeId, PlaybackProgress $progress): array
    {
        return [
            'user_id' => $owner,
            'episode_id' => $episodeId,
            'position_seconds' => (int) $progress->position_seconds,
            'completed' => (bool) $progress->completed,
            'version' => (int) $progress->version,
        ];
    }

    private function isCompleted(int $durationSeconds, int $positionSeconds, bool $clientCompleted = false): bool
    {
        if ($positionSeconds <= 0 && ! $clientCompleted) {
            return false;
        }

        if ($durationSeconds < 30) {
            return $clientCompleted && $positionSeconds > 0;
        }

        $remaining = $durationSeconds - $positionSeconds;

        return $remaining <= 15 || $positionSeconds >= (int) floor($durationSeconds * 0.95);
    }
}
