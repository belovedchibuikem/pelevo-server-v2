<?php

namespace App\Actions\Catalog;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SyncPodcastIndexCategories
{
    public function __construct(
        private readonly PodcastIndexClient $client,
        private readonly InvalidateDiscoveryCache $cache,
    ) {}

    /**
     * Upsert Podcast Index taxonomy into local `categories`.
     *
     * @return int Number of categories upserted
     */
    public function handle(bool $invalidateCache = true): int
    {
        $feeds = $this->fetchFeeds();
        if ($feeds === []) {
            return 0;
        }

        $upserted = 0;
        $position = 0;
        foreach ($feeds as $feed) {
            if (! is_array($feed)) {
                continue;
            }
            $piId = isset($feed['id']) ? (int) $feed['id'] : 0;
            $name = trim(strip_tags((string) ($feed['name'] ?? '')));
            if ($piId <= 0 || $name === '') {
                continue;
            }
            $slug = Str::slug($name);
            if ($slug === '') {
                $slug = 'category-'.$piId;
            }
            $position++;

            $existing = DB::table('categories')
                ->where(function ($query) use ($piId, $slug): void {
                    $query->where('podcast_index_id', $piId)->orWhere('slug', $slug);
                })
                ->orderByRaw('case when podcast_index_id is null then 1 else 0 end')
                ->first();

            if ($existing) {
                DB::table('categories')->where('id', $existing->id)->update([
                    'name' => $name,
                    'slug' => $slug,
                    'podcast_index_id' => $piId,
                    'position' => $position,
                    'active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('categories')->insert([
                    'name' => $name,
                    'slug' => $slug,
                    'podcast_index_id' => $piId,
                    'position' => $position,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $upserted++;
        }

        if ($invalidateCache && $upserted > 0) {
            $this->cache->public();
        }

        Log::info('browse.categories.synced', ['count' => $upserted]);

        return $upserted;
    }

    /**
     * @return list<array{id?: int|string, name?: string}>
     */
    private function fetchFeeds(): array
    {
        if (config('services.podcast_index.enabled')) {
            try {
                $payload = $this->client->categoriesList(fast: true);
                $feeds = $payload['feeds'] ?? [];
                if (is_array($feeds) && $feeds !== []) {
                    return array_values($feeds);
                }
            } catch (PodcastIndexException $exception) {
                Log::warning('browse.categories.sync_failed', ['message' => $exception->getMessage()]);
            }
        }

        /** @var list<array{id: int, name: string}> $fallback */
        $fallback = require app_path('Support/podcast_index_categories.php');

        return $fallback;
    }
}
