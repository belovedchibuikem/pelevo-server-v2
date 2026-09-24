<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use App\Support\PublishedReelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CreatorPublicController extends Controller
{
    public function show(CreatorProfile $creator, Request $request): JsonResponse
    {
        if ($creator->status !== 'active') {
            return ApiResponse::error('NOT_FOUND', 'Creator not found.', 404);
        }
        $user = DB::table('users')->where('id', $creator->user_id)->first();
        $profile = DB::table('user_profiles')->where('user_id', $creator->user_id)->first();
        $shows = DB::table('verified_show_claims')
            ->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')
            ->join('shows', 'shows.id', '=', 'verified_show_claims.show_id')
            ->where('show_claims.creator_profile_id', $creator->id)
            ->select('shows.id', 'shows.title', 'shows.author', 'shows.artwork_url')
            ->orderBy('shows.title')
            ->get();
        $viewerId = $request->user()->id;
        $host = rtrim($request->getSchemeAndHttpHost(), '/');
        $avatar = is_string($profile?->avatar_url) ? $profile->avatar_url : null;
        if (is_string($avatar) && $avatar !== '' && ! str_starts_with($avatar, 'http')) {
            $avatar = $host.'/'.ltrim($avatar, '/');
        }

        return ApiResponse::success([
            'id' => $creator->id,
            'display_name' => $creator->display_name,
            'handle' => is_string($user?->handle) ? $user->handle : '',
            'bio' => is_string($profile?->bio) ? $profile->bio : null,
            'avatar_url' => $avatar,
            'verified' => $shows->isNotEmpty(),
            'is_self' => $creator->user_id === $viewerId,
            'following' => DB::table('creator_followers')->where('creator_profile_id', $creator->id)->where('user_id', $viewerId)->exists(),
            'stats' => [
                'followers' => DB::table('creator_followers')->where('creator_profile_id', $creator->id)->count(),
                'following' => DB::table('follows')->where('user_id', $creator->user_id)->count(),
                'reels' => DB::table('reels')->where('creator_profile_id', $creator->id)->where('state', 'published')->count(),
                'podcasts' => $shows->count(),
            ],
            'shows' => $shows->map(fn (object $show): array => [
                'id' => (string) $show->id,
                'title' => $show->title,
                'author' => $show->author,
                'artwork_url' => $show->artwork_url,
            ])->values()->all(),
        ]);
    }

    public function reels(CreatorProfile $creator, Request $request, PublishedReelPresenter $presenter): JsonResponse
    {
        if ($creator->status !== 'active') {
            return ApiResponse::error('NOT_FOUND', 'Creator not found.', 404);
        }
        $items = DB::table('reels')
            ->where('creator_profile_id', $creator->id)
            ->where('state', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate(min($request->integer('limit', 24), 50));

        return ApiResponse::success(
            $presenter->present($items->items(), $request->user()->id, $request),
            ['cursor' => $items->nextCursor()?->encode(), 'has_more' => $items->hasMorePages()],
        );
    }
}
