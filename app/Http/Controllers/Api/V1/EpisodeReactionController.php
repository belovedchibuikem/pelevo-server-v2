<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EpisodeReactionController extends Controller
{
    public function like(Episode $episode, Request $request): JsonResponse
    {
        DB::table('episode_reactions')->updateOrInsert(['user_id' => $request->user()->id, 'episode_id' => $episode->id], ['reaction' => 'like', 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['reaction' => 'like']);
    }

    public function store(Episode $episode, Request $request): JsonResponse
    {
        $data = $request->validate(['reaction' => ['required', 'in:like,love,insightful']]);
        DB::table('episode_reactions')->updateOrInsert(['user_id' => $request->user()->id, 'episode_id' => $episode->id], ['reaction' => $data['reaction'], 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['reaction' => $data['reaction']]);
    }

    public function destroy(Episode $episode, Request $request): JsonResponse
    {
        DB::table('episode_reactions')->where('user_id', $request->user()->id)->where('episode_id', $episode->id)->delete();

        return ApiResponse::success(['reaction' => null]);
    }
}
