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
            return 0;
        }

        $cat = $category->podcast_index_id
            ? (string) $category->podcast_index_id
            : (string) $category->name;

        try {
            $payload = $this->client->trending($cat, $limit);
        } catch (PodcastIndexException $exception) {
            Log::warning('browse.category.hydrate_failed', [
                'slug' => $category->slug ?? null,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        $feeds = $payload['feeds'] ?? [];
        if (! is_array($feeds) || $feeds === []) {
            return 0;
        }

        $linked = 0;
        foreach ($feeds as $feed) {
            if (! is_array($feed)) {
                continue;
            }
            $show = $this->persist->handle($feed);
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

        if ($linked > 0) {
            $this->cache->public();
        }

        Log::info('browse.category.hydrated', [
            'slug' => $category->slug ?? null,
            'linked' => $linked,
        ]);

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
