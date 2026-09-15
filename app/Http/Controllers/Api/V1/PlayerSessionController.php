<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\ConsumeHomeDailyPick;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlayerSessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present(DB::table('player_sessions')->where('user_id', $request->user()->id)->first()));
    }

    public function update(Request $request, ConsumeHomeDailyPick $consumePick): JsonResponse
    {
        $data = $request->validate(['episode_id' => ['nullable', 'exists:episodes,id'], 'playing' => ['required', 'boolean'], 'playback_rate' => ['required', 'numeric', 'between:0.5,3'], 'version' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($request, $data, $consumePick): JsonResponse {
            $row = DB::table('player_sessions')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (($row?->version ?? 0) !== $data['version']) {
                return ApiResponse::error('VERSION_CONFLICT', 'Player session changed on another device.', 409);
            }
            $values = ['episode_id' => $data['episode_id'] ?? null, 'playing' => $data['playing'], 'playback_rate' => $data['playback_rate'], 'version' => ($row?->version ?? 0) + 1, 'updated_at' => now()];
            $row ? DB::table('player_sessions')->where('user_id', $request->user()->id)->update($values) : DB::table('player_sessions')->insert(['user_id' => $request->user()->id, ...$values, 'created_at' => now()]);
            if ($data['playing']) {
                $consumePick->handle($request->user()->id, $data['episode_id'] ?? null);
            }

            return ApiResponse::success($this->present(DB::table('player_sessions')->where('user_id', $request->user()->id)->first()));
        });
    }

    private function present(?object $row): array
    {
        return [
            'episode_id' => $row?->episode_id,
            'playing' => (bool) ($row?->playing ?? false),
            'playback_rate' => (float) ($row?->playback_rate ?? 1),
            'version' => (int) ($row?->version ?? 0),
        ];
    }
}
