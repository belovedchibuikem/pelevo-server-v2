<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReelMonetizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator) {
            return ApiResponse::error('CLAIM_REQUIRED', 'Creator access is required.', 403);
        }

        return ApiResponse::success($this->present($this->evaluate($creator)));
    }

    public function store(Request $request): JsonResponse
    {
        $creator = CreatorProfile::where('user_id', $request->user()->id)->first();
        if (! $creator) {
            return ApiResponse::error('CLAIM_REQUIRED', 'Creator access is required.', 403);
        }
        $profile = $this->evaluate($creator);
        if (! $profile->eligible) {
            return ApiResponse::error('NOT_ELIGIBLE', 'Follower and qualified-view requirements are not met.', 409);
        }
        DB::table('reel_monetization_profiles')->where('creator_profile_id', $creator->id)->update(['opted_in' => true, 'opted_in_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success($this->present(DB::table('reel_monetization_profiles')->where('creator_profile_id', $creator->id)->first()));
    }

    private function evaluate(CreatorProfile $creator): object
    {
        $money = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first()?->payload['money'] ?? [];
        $followers = DB::table('creator_followers')->where('creator_profile_id', $creator->id)->count();
        $views = DB::table('reel_view_credits')->join('reels', 'reels.id', '=', 'reel_view_credits.reel_id')->where('reels.creator_profile_id', $creator->id)->count();
        $eligible = $followers >= ($money['reels_min_followers'] ?? 100) && $views >= ($money['reels_min_views'] ?? 1000);
        DB::table('reel_monetization_profiles')->updateOrInsert(['creator_profile_id' => $creator->id], ['eligible' => $eligible, 'followers' => $followers, 'qualified_views' => $views, 'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('reel_monetization_profiles')->where('creator_profile_id', $creator->id)->first();
    }

    private function present(?object $row): array
    {
        abort_unless($row, 404);
        $money = ConfigurationVersion::where('effective_at', '<=', now())->latest('version')->first()?->payload['money'] ?? [];

        return [
            'creator_profile_id' => (string) $row->creator_profile_id,
            'eligible' => $row->eligible === true || $row->eligible === 1 || $row->eligible === '1',
            'opted_in' => $row->opted_in === true || $row->opted_in === 1 || $row->opted_in === '1',
            'followers' => (int) $row->followers,
            'qualified_views' => (int) $row->qualified_views,
            'min_followers' => (int) ($money['reels_min_followers'] ?? 100),
            'min_views' => (int) ($money['reels_min_views'] ?? 1000),
            'evaluated_at' => $row->evaluated_at,
            'opted_in_at' => $row->opted_in_at,
        ];
    }
}
