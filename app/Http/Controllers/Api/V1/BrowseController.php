<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\HydrateCategoryShows;
use App\Actions\Catalog\SyncPodcastIndexCategories;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BrowseController extends Controller
{
    public function index(SyncPodcastIndexCategories $sync): JsonResponse
    {
        return ApiResponse::success(Cache::flexible('browse:index:v3', [60, 300], function () use ($sync): array {
            if (! DB::table('categories')->where('active', true)->exists()) {
                $sync->handle(invalidateCache: false);
            }

            return DB::table('categories')
                ->where('active', true)
                ->orderBy('position')
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'name', 'slug'])
                ->map(fn (object $category): array => [
                    'id' => (string) $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->values()
                ->all();
        }));
    }

    public function categories(SyncPodcastIndexCategories $sync): JsonResponse
    {
        return $this->index($sync);
    }

    public function show(string $slug, Request $request, HydrateCategoryShows $hydrate): JsonResponse
    {
        $category = DB::table('categories')->where('slug', $slug)->where('active', true)->first();
        if (! $category) {
            // Cold start: try syncing taxonomy once, then re-resolve.
            app(SyncPodcastIndexCategories::class)->handle();
            $category = DB::table('categories')->where('slug', $slug)->where('active', true)->first();
        }
        if (! $category) {
            return ApiResponse::error('NOT_FOUND', 'Category not found.', 404);
        }

        $limit = min(max($request->integer('limit', 20), 1), 50);
        $linked = DB::table('category_show')->where('category_id', $category->id)->count();
        if ($linked < 8) {
            $hydrate->handle($category, max($limit, 24));
        }

        $shows = DB::table('category_show')
            ->join('shows', 'shows.id', '=', 'category_show.show_id')
            ->where('category_show.category_id', $category->id)
            ->where('shows.status', 'active')
            ->select('shows.id', 'shows.title', 'shows.artwork_url', 'shows.author')
            ->orderBy('shows.title')
            ->orderBy('shows.id')
            ->cursorPaginate($limit);

        return ApiResponse::success([
            'category' => [
                'id' => (string) $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'shows' => collect($shows->items())->map(fn (object $show): array => [
                'id' => $show->id,
                'title' => $show->title,
                'author' => $show->author,
                'artwork_url' => $show->artwork_url,
            ])->values()->all(),
        ], ['cursor' => $shows->nextCursor()?->encode(), 'has_more' => $shows->hasMorePages()]);
    }
}
