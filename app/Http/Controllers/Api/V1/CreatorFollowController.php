<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CreatorFollowController extends Controller
{
    public function store(CreatorProfile $creator, Request $request): JsonResponse
    {
        if ($creator->user_id === $request->user()->id) {
            return ApiResponse::error('VALIDATION', 'You cannot follow your own creator profile.', 422);
        }
        DB::table('creator_followers')->insertOrIgnore(['creator_profile_id' => $creator->id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['following' => true], status: 201);
    }

    public function destroy(CreatorProfile $creator, Request $request): JsonResponse
    {
        DB::table('creator_followers')->where('creator_profile_id', $creator->id)->where('user_id', $request->user()->id)->delete();

        return ApiResponse::success(['following' => false]);
    }
}
