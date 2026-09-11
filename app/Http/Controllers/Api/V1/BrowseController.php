<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BrowseController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Cache::flexible('browse:index:v2', [60, 300], fn () => DB::table('categories')->where('active', true)->orderBy('position')->orderBy('id')->limit(100)->get(['id', 'name', 'slug'])->map(fn (object $category): array => [
            'id' => (string) $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ])->values()->all()));
    }

    public function categories(): JsonResponse
    {
        return $this->index();
    }

    public function show(string $slug, Request $request): JsonResponse
    {
        $category = DB::table('categories')->where('slug', $slug)->where('active', true)->first();
        if (! $category) {
            return ApiResponse::error('NOT_FOUND', 'Category not found.', 404);
        }
        $shows = DB::table('category_show')->join('shows', 'shows.id', '=', 'category_show.show_id')->where('category_show.category_id', $category->id)->where('shows.status', 'active')->select('shows.id', 'shows.title', 'shows.artwork_url', 'shows.author')->orderBy('shows.title')->orderBy('shows.id')->cursorPaginate(min($request->integer('limit', 20), 50));

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
