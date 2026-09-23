<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Support\ApiResponse;
use App\Support\CreatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->fresh() ?? $request->user();
        $claimedShows = DB::table('creator_profiles')
            ->join('show_claims', 'show_claims.creator_profile_id', '=', 'creator_profiles.id')
            ->join('verified_show_claims', 'verified_show_claims.show_claim_id', '=', 'show_claims.id')
            ->where('creator_profiles.user_id', $user->id)
            ->count();

        return ApiResponse::success([
            ...$user->toArray(),
            'onboarded' => $user->onboarded_at !== null,
            'interests' => DB::table('user_interests')->where('user_id', $user->id)->orderBy('interest')->pluck('interest')->values()->all(),
            'profile' => DB::table('user_profiles')->where('user_id', $user->id)->first(),
            'capabilities' => CreatorAccess::capabilities($user->id),
            'stats' => [
                'following' => DB::table('follows')->where('user_id', $user->id)->count(),
                'followers' => DB::table('creator_followers')
                    ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_followers.creator_profile_id')
                    ->where('creator_profiles.user_id', $user->id)->count(),
                'podcasts' => $claimedShows,
                'playlists' => DB::table('playlists')->where('user_id', $user->id)->count(),
            ],
            'unread_notifications' => DB::table('notifications')->where('user_id', $user->id)->whereNull('read_at')->whereNull('dismissed_at')->count(),
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $config = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->firstOrFail();
        $etag = '"config-'.$config->version.'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return ApiResponse::success(['version' => $config->version, ...$config->payload])->header('ETag', $etag)->header('Cache-Control', 'private, max-age=60');
    }
}
