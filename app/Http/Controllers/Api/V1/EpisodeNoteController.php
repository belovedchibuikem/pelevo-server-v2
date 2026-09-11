<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EpisodeNoteController extends Controller
{
    public function show(Episode $episode, Request $request): JsonResponse
    {
        $note = DB::table('episode_notes')->where('episode_id', $episode->id)->where('user_id', $request->user()->id)->first();

        return ApiResponse::success($this->present($note));
    }

    public function update(Episode $episode, Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'position_seconds' => ['nullable', 'integer', 'min:0'], 'version' => ['required', 'integer', 'min:0']]);
        $note = DB::table('episode_notes')->where('episode_id', $episode->id)->where('user_id', $request->user()->id)->first();
        if (($note?->version ?? 0) !== $data['version']) {
            return ApiResponse::error('VERSION_CONFLICT', 'Episode note changed on another device.', 409);
        }
        $values = ['body' => $data['body'], 'position_seconds' => $data['position_seconds'] ?? null, 'version' => ($note?->version ?? 0) + 1, 'updated_at' => now()];
        $note ? DB::table('episode_notes')->where('id', $note->id)->update($values) : DB::table('episode_notes')->insert(['id' => (string) Str::ulid(), 'user_id' => $request->user()->id, 'episode_id' => $episode->id, ...$values, 'created_at' => now()]);

        return ApiResponse::success($this->present(DB::table('episode_notes')->where('episode_id', $episode->id)->where('user_id', $request->user()->id)->first()));
    }

    public function destroy(Episode $episode, Request $request): JsonResponse
    {
        DB::table('episode_notes')->where('episode_id', $episode->id)->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    private function present(?object $note): array
    {
        return [
            'body' => $note?->body,
            'position_seconds' => $note?->position_seconds === null ? null : (int) $note->position_seconds,
            'version' => (int) ($note?->version ?? 0),
        ];
    }
}
