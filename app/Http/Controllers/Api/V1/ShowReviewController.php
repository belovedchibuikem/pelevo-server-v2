<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShowReviewController extends Controller
{
    public function index(Show $show, Request $request): JsonResponse
    {
        $reviews = DB::table('show_reviews')->join('users', 'users.id', '=', 'show_reviews.user_id')->where('show_reviews.show_id', $show->id)->where('show_reviews.state', 'published')->select('show_reviews.id', 'show_reviews.rating', 'show_reviews.body', 'show_reviews.created_at', 'users.name as reviewer_name')->orderByDesc('show_reviews.created_at')->orderByDesc('show_reviews.id')->cursorPaginate(min($request->integer('limit', 20), 50));

        return ApiResponse::success(collect($reviews->items())->map(fn (object $row): array => [
            'id' => $row->id,
            'rating' => (int) $row->rating,
            'body' => $row->body,
            'reviewer_name' => $row->reviewer_name,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values(), ['cursor' => $reviews->nextCursor()?->encode(), 'has_more' => $reviews->hasMorePages()]);
    }

    public function store(Show $show, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($show, $request, $data): void {
            $request->user()->ratedShows()->syncWithoutDetaching([$show->id => ['rating' => $data['rating']]]);
            $review = DB::table('show_reviews')->where('show_id', $show->id)->where('user_id', $request->user()->id)->first();
            $values = ['rating' => $data['rating'], 'body' => $data['body'] ?? null, 'state' => 'published', 'updated_at' => now()];
            $review ? DB::table('show_reviews')->where('id', $review->id)->update($values) : DB::table('show_reviews')->insert(['id' => (string) Str::ulid(), 'show_id' => $show->id, 'user_id' => $request->user()->id, ...$values, 'created_at' => now()]);
        });
        $cache->public();

        return ApiResponse::success(['reviewed' => true], status: 201);
    }

    public function update(string $review, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000']]);
        $row = DB::table('show_reviews')->where('id', $review)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Review not found.', 404);
        }
        DB::transaction(function () use ($row, $data, $request): void {
            DB::table('show_reviews')->where('id', $row->id)->update(['rating' => $data['rating'], 'body' => $data['body'] ?? null, 'updated_at' => now()]);
            DB::table('show_ratings')->updateOrInsert(['show_id' => $row->show_id, 'user_id' => $request->user()->id], ['rating' => $data['rating'], 'created_at' => now(), 'updated_at' => now()]);
        });
        $cache->public();

        return ApiResponse::success(['reviewed' => true]);
    }

    public function destroy(string $review, Request $request, InvalidateDiscoveryCache $cache): JsonResponse
    {
        $row = DB::table('show_reviews')->where('id', $review)->where('user_id', $request->user()->id)->first();
        if (! $row) {
            return ApiResponse::error('NOT_FOUND', 'Review not found.', 404);
        }
        DB::transaction(function () use ($row, $request): void {
            DB::table('show_reviews')->where('id', $row->id)->delete();
            DB::table('show_ratings')->where('show_id', $row->show_id)->where('user_id', $request->user()->id)->delete();
        });
        $cache->public();

        return ApiResponse::success(['deleted' => true]);
    }
}
