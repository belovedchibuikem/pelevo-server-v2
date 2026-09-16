<?php

namespace App\Actions\Catalog;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class HydrateCategoryShows
{
    public function __construct(
        private readonly PodcastIndexClient $client,
        private readonly PersistDiscoveredShow $persist,
        private readonly InvalidateDiscoveryCache $cache,
    ) {}

    /**
     * Pull trending Podcast Index feeds for a category and link them locally.
     *
     * @return int Number of shows linked
     */
    public function handle(object $category, int $limit = 24): int
    {
        if (! config('services.podcast_index.enabled')) {
            $linked = $this->attachLocalShows($category, min($limit, 8));
            if ($linked > 0) {
                $this->cache->public();
            }

            return $linked;
        }

        $cat = $category->podcast_index_id
            ? (string) $category->podcast_index_id
            : (string) $category->name;

        $feeds = [];
        try {
            $payload = $this->client->trending($cat, $limit, fast: true);
            $feeds = $payload['feeds'] ?? [];
            if ((! is_array($feeds) || $feeds === []) && is_string($category->name ?? null)) {
                $feeds = $this->client->searchByTerm((string) $category->name, $limit, fast: true)['feeds'] ?? [];
            }
        } catch (PodcastIndexException $exception) {
            Log::warning('browse.category.hydrate_failed', [
                'slug' => $category->slug ?? null,
                'message' => $exception->getMessage(),
            ]);
        }

        if (! is_array($feeds)) {
            $feeds = [];
        }

        $linked = 0;
        foreach ($feeds as $feed) {
            if (! is_array($feed)) {
                continue;
            }
            try {
                $show = $this->persist->handle($feed);
            } catch (\Throwable $exception) {
                Log::warning('browse.category.persist_failed', [
                    'slug' => $category->slug ?? null,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }
            if (! $show) {
                continue;
            }
            $inserted = DB::table('category_show')->insertOrIgnore([
                'category_id' => $category->id,
                'show_id' => $show->id,
            ]);
            if ($inserted) {
                $linked++;
            }
            // Also attach other PI categories declared on the feed.
            $this->linkFeedCategories($show->id, $feed['categories'] ?? null);
        }

        if ($linked < 8) {
            $linked += $this->attachLocalShows($category, 8 - $linked);
        }

        if ($linked > 0) {
            $this->cache->public();
        }

        Log::info('browse.category.hydrated', [
            'slug' => $category->slug ?? null,
            'linked' => $linked,
        ]);

        return $linked;
    }

    private function attachLocalShows(object $category, int $need): int
    {
        if ($need <= 0) {
            return 0;
        }

        $name = trim((string) ($category->name ?? ''));
        $already = DB::table('category_show')->where('category_id', $category->id)->pluck('show_id');
        $query = DB::table('shows')->where('status', 'active');
        if ($already->isNotEmpty()) {
            $query->whereNotIn('id', $already);
        }
        if ($name !== '') {
            $like = '%'.$name.'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('author', 'like', $like);
            });
        }

        $ids = $query->orderBy('title')->orderBy('id')->limit($need)->pluck('id');
        $linked = 0;
        foreach ($ids as $showId) {
            $inserted = DB::table('category_show')->insertOrIgnore([
                'category_id' => $category->id,
                'show_id' => $showId,
            ]);
            if ($inserted) {
                $linked++;
            }
        }

        return $linked;
    }

    private function linkFeedCategories(string $showId, mixed $categories): void
    {
        if (! is_array($categories) || $categories === []) {
            return;
        }

        foreach ($categories as $piId => $name) {
            $id = is_numeric($piId) ? (int) $piId : 0;
            if ($id <= 0) {
                continue;
            }
            $categoryId = DB::table('categories')->where('podcast_index_id', $id)->value('id');
            if (! $categoryId) {
                continue;
            }
            DB::table('category_show')->insertOrIgnore([
                'category_id' => $categoryId,
                'show_id' => $showId,
            ]);
        }
    }
}
