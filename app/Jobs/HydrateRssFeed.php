<?php

namespace App\Jobs;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Actions\Catalog\PersistDiscoveredShow;
use App\Events\NewEpisodePublished;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Integrations\Rss\RssFeedFetcher;
use App\Integrations\Rss\UnsafeFeedUrlException;
use App\Models\Episode;
use App\Models\FeedSyncRun;
use App\Models\Show;
use App\Support\ArtworkUrl;
use App\Support\CatalogText;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class HydrateRssFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 180;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $showId)
    {
        $this->onQueue('rss');
    }

    public function uniqueId(): string
    {
        return $this->showId;
    }

    public function handle(RssFeedFetcher $fetcher, InvalidateDiscoveryCache $cache, PodcastIndexClient $podcastIndex): void
    {
        $show = Show::with('feedState')->findOrFail($this->showId);
        $show->feedState()->updateOrCreate([], [
            'state' => 'running',
            'last_error' => null,
        ]);

        $syncRun = FeedSyncRun::create([
            'show_id' => $show->id,
            'state' => 'running',
            'started_at' => now(),
        ]);

        $seededEpisodes = [];
        try {
            // Seed a modest Podcast Index page first so the detail screen can
            // populate while RSS continues for full archives (including 2K+).
            $seededEpisodes = $this->seedFromPodcastIndex($show, $podcastIndex);
            $seeded = count($seededEpisodes);

            $originalRssUrl = $show->rss_url;
            try {
                $result = $this->fetchFollowingFeedMoves($show, $fetcher);
            } catch (Throwable $exception) {
                if (! $this->isPermanentFeedFailure($exception) || ! $this->refreshFeedUrlFromPodcastIndex($show, $podcastIndex)) {
                    throw $exception;
                }
                $show->refresh();
                $show->load('feedState');
                $originalRssUrl = $show->rss_url;
                $result = $this->fetchFollowingFeedMoves($show, $fetcher);
            }
            if ($result['not_modified']) {
                $urlChanged = false;
                DB::transaction(function () use ($show, $syncRun, $result, $seeded, &$urlChanged): void {
                    $urlChanged = $this->persistCanonicalFeedUrl($show, $result);
                    $show->feedState()->updateOrCreate([], [
                        'last_success_at' => now(),
                        'consecutive_failures' => 0,
                        'state' => 'healthy',
                        'next_poll_at' => $this->nextPollAt($show),
                        'last_error' => null,
                    ]);
                    $syncRun->update([
                        'state' => 'not_modified',
                        'resolved_url' => $result['resolved_url'],
                        'http_status' => 304,
                        'new_episode_count' => $seeded,
                        'finished_at' => now(),
                    ]);
                });
                foreach ($seededEpisodes as $episode) {
                    NewEpisodePublished::dispatch($episode);
                }
                if ($urlChanged || $show->rss_url !== $originalRssUrl || $seededEpisodes !== []) {
                    $cache->show($show->id);
                }

                return;
            }

            $outcome = DB::transaction(function () use ($show, $result, $syncRun, $originalRssUrl): array {
                $urlChanged = $this->persistCanonicalFeedUrl($show, $result);
                $metadataChanged = $this->syncChannelMetadata($show, $result['xml']);
                $newEpisodes = $this->upsertRssItems($show, $result['xml']);
                $show->feedState()->updateOrCreate([], [
                    'etag' => $result['etag'],
                    'last_modified' => $result['last_modified'],
                    'content_hash' => $result['content_hash'],
                    'last_success_at' => now(),
                    'channel_synced_at' => now(),
                    'consecutive_failures' => 0,
                    'state' => 'healthy',
                    'next_poll_at' => $this->nextPollAt($show),
                    'last_error' => null,
                ]);
                $syncRun->update([
                    'state' => 'completed',
                    'resolved_url' => $result['resolved_url'],
                    'http_status' => $result['status'],
                    'new_episode_count' => count($newEpisodes),
                    'finished_at' => now(),
                ]);

                return [
                    'episodes' => $newEpisodes,
                    'catalog_changed' => $urlChanged || $metadataChanged || $show->rss_url !== $originalRssUrl,
                ];
            });

            foreach (array_merge($seededEpisodes, $outcome['episodes']) as $episode) {
                NewEpisodePublished::dispatch($episode);
            }
            if ($outcome['episodes'] !== [] || $seeded > 0 || $outcome['catalog_changed']) {
                $cache->show($show->id);
            }
        } catch (Throwable $exception) {
            Log::warning('catalog.rss.hydrate_failed', [
                'show_id' => $this->showId,
                'message' => CatalogText::utf8($exception->getMessage(), 400),
            ]);
            foreach ($seededEpisodes as $episode) {
                NewEpisodePublished::dispatch($episode);
            }
            if ($seededEpisodes !== []) {
                $cache->show($show->id);
                $this->persistIndexOnlySuccess($show, $syncRun, count($seededEpisodes), $exception);

                return;
            }
            $this->persistFailure($show, $syncRun, $exception);
        }
    }

    /**
     * Page through Podcast Index episodes with a small max and optional `since`
     * to stay under rate limits. PI hard-caps at 1000 newest items and has no
     * older-than cursor, so RSS fills the full archive.
     *
     * @return list<Episode>
     */
    private function seedFromPodcastIndex(Show $show, PodcastIndexClient $client): array
    {
        if (! config('services.podcast_index.enabled')) {
            return [];
        }

        $feedId = DB::table('show_external_ids')
            ->where('show_id', $show->id)
            ->where('provider', 'podcast_index')
            ->value('external_id');

        if (! is_string($feedId) || $feedId === '') {
            return [];
        }

        $pageSize = max(1, min((int) config('services.podcast_index.episode_page_size', 100), 1000));
        $since = null;
        if ($show->episodes()->exists() && $show->feedState?->last_success_at) {
            $since = $show->feedState->last_success_at->getTimestamp();
        }

        $created = [];

        try {
            // One modest page for seed / incremental `since` updates. PI hard-caps
            // at 1000 and has no older-than cursor; RSS fills the full archive.
            $payload = $client->episodesByFeedId($feedId, $pageSize, $since, useCache: false);
            $items = $payload['items'] ?? [];
            if (! is_array($items) || $items === []) {
                return [];
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $guid = $this->podcastIndexGuid($item);
                if ($guid === '') {
                    continue;
                }
                try {
                    $episode = $this->upsertPodcastIndexEpisode($show, $item, $guid);
                    if ($episode !== null) {
                        $created[] = $episode;
                    }
                } catch (Throwable $exception) {
                    Log::warning('catalog.rss.episode_upsert_failed', [
                        'show_id' => $show->id,
                        'guid' => CatalogText::utf8($guid, 180),
                        'message' => CatalogText::utf8($exception->getMessage(), 400),
                    ]);
                }
            }
        } catch (PodcastIndexException $exception) {
            Log::info('catalog.podcast_index.episode_seed_skipped', [
                'show_id' => $show->id,
                'feed_id' => $feedId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $created;
    }

    private function podcastIndexGuid(array $item): string
    {
        $guid = CatalogText::utf8(trim((string) ($item['guid'] ?? '')), 2000);
        if ($guid !== '') {
            return $guid;
        }
        $id = CatalogText::utf8(trim((string) ($item['id'] ?? '')), 64);
        if ($id !== '') {
            return 'podcastindex:'.$id;
        }

        return hash('sha256', CatalogText::utf8(($item['title'] ?? '').'|'.($item['enclosureUrl'] ?? '').'|'.($item['datePublished'] ?? '')));
    }

    private function upsertPodcastIndexEpisode(Show $show, array $item, string $guid): ?Episode
    {
        $audioUrl = filter_var($item['enclosureUrl'] ?? null, FILTER_VALIDATE_URL);
        if (! is_string($audioUrl) || ! str_starts_with($audioUrl, 'https://')) {
            return null;
        }

        $published = ((int) ($item['datePublished'] ?? 0)) > 0
            ? date(DATE_ATOM, (int) $item['datePublished'])
            : null;
        $duration = isset($item['duration']) && is_numeric($item['duration'])
            ? max(0, (int) $item['duration'])
            : null;

        $episode = Episode::updateOrCreate(
            ['show_id' => $show->id, 'guid' => $guid],
            [
                'external_id' => isset($item['id']) ? CatalogText::utf8((string) $item['id'], 191) : null,
                'title' => CatalogText::utf8(strip_tags((string) ($item['title'] ?? 'Untitled episode')), 500) ?: 'Untitled episode',
                'description' => CatalogText::utf8(strip_tags((string) ($item['description'] ?? ''))),
                'audio_url' => $audioUrl,
                'duration_seconds' => $duration,
                'published_at' => $published,
                'availability' => 'available',
            ]
        );

        return $episode->wasRecentlyCreated ? $episode : null;
    }

    private function upsertRssItems(Show $show, \SimpleXMLElement $xml): array
    {
        $channel = $xml->channel ?? $xml;
        $chunkSize = max(1, (int) config('rss.upsert_chunk', 100));
        $buffer = [];
        $newEpisodes = [];

        foreach ($channel->item ?? [] as $item) {
            $rawGuid = trim((string) ($item->guid ?? ''));
            $guid = CatalogText::utf8($rawGuid, 2000) ?: hash('sha256', CatalogText::utf8((string) $item->title.'|'.(string) ($item->enclosure['url'] ?? '').'|'.(string) $item->pubDate));
            $audioUrl = $this->enclosureUrl($item);
            if (! $audioUrl || ! str_starts_with($audioUrl, 'https://')) {
                continue;
            }
            $buffer[] = [
                'guid' => $guid,
                'title' => CatalogText::utf8(strip_tags((string) $item->title), 500) ?: 'Untitled episode',
                'description' => CatalogText::utf8(strip_tags((string) ($item->description ?? ''))),
                'audio_url' => $audioUrl,
                'duration_seconds' => $this->durationSeconds($item),
                'published_at' => ($date = strtotime((string) $item->pubDate)) ? date(DATE_ATOM, $date) : null,
            ];
            if (count($buffer) >= $chunkSize) {
                array_push($newEpisodes, ...$this->flushEpisodeChunk($show, $buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            array_push($newEpisodes, ...$this->flushEpisodeChunk($show, $buffer));
        }

        return $newEpisodes;
    }

    private function flushEpisodeChunk(Show $show, array $rows): array
    {
        $created = [];
        foreach ($rows as $row) {
            try {
                $episode = Episode::updateOrCreate(
                    ['show_id' => $show->id, 'guid' => $row['guid']],
                    [
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'audio_url' => $row['audio_url'],
                        'duration_seconds' => $row['duration_seconds'],
                        'published_at' => $row['published_at'],
                        'availability' => 'available',
                    ]
                );
            } catch (Throwable $exception) {
                Log::warning('catalog.rss.episode_upsert_failed', [
                    'show_id' => $show->id,
                    'guid' => CatalogText::utf8((string) $row['guid'], 180),
                    'message' => CatalogText::utf8($exception->getMessage(), 400),
                ]);

                continue;
            }
            if ($episode->wasRecentlyCreated) {
                $created[] = $episode;
            }
        }

        return $created;
    }

    /**
     * Follow HTTP 301/308 into the stored feed URL, then honour itunes:new-feed-url
     * once so the next poll (and this run's metadata) use the publisher's new host.
     *
     * @return array<string, mixed>
     */
    private function fetchFollowingFeedMoves(Show $show, RssFeedFetcher $fetcher): array
    {
        $result = $fetcher->fetch($show);
        if ($result['not_modified'] && $this->channelRefreshDue($show)) {
            $result = $fetcher->fetch($show, bypassCache: true);
        }
        if ($result['not_modified']) {
            return $result;
        }

        $declared = $this->declaredNewFeedUrl($result['xml'], $fetcher);
        if ($declared === null || $declared === $show->rss_url) {
            return $result;
        }

        if (! $this->applyResolvedUrl($show, $declared)) {
            return $result;
        }

        $show->refresh();
        $show->load('feedState');

        return $fetcher->fetch($show, bypassCache: true);
    }

    private function channelRefreshDue(Show $show): bool
    {
        $hours = max(1, (int) config('rss.channel_refresh_hours', 1));
        $synced = $show->feedState?->channel_synced_at;

        return $synced === null || $synced->lte(now()->subHours($hours));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistCanonicalFeedUrl(Show $show, array $result): bool
    {
        if (! ($result['permanent_redirect'] ?? false)) {
            return false;
        }

        $canonical = is_string($result['canonical_url'] ?? null) ? $result['canonical_url'] : null;
        if ($canonical === null || $canonical === '') {
            return false;
        }

        return $this->applyResolvedUrl($show, $canonical);
    }

    private function refreshFeedUrlFromPodcastIndex(Show $show, PodcastIndexClient $client): bool
    {
        if (! config('services.podcast_index.enabled')) {
            return false;
        }

        $feedId = DB::table('show_external_ids')
            ->where('show_id', $show->id)
            ->where('provider', 'podcast_index')
            ->value('external_id');

        try {
            $feed = $this->podcastIndexFeedRecord($client, $show, is_string($feedId) ? $feedId : null);
        } catch (PodcastIndexException $exception) {
            Log::info('catalog.podcast_index.feed_url_refresh_skipped', [
                'show_id' => $show->id,
                'feed_id' => $feedId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! is_array($feed)) {
            return false;
        }

        $before = $show->rss_url;
        $persisted = app(PersistDiscoveredShow::class)->handle($feed);
        if ($persisted === null || $persisted->rss_url === $before) {
            return false;
        }

        Log::info('catalog.rss.feed_url_refreshed_from_index', [
            'show_id' => $show->id,
            'from' => $before,
            'to' => $persisted->rss_url,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function podcastIndexFeedRecord(PodcastIndexClient $client, Show $show, ?string $feedId): ?array
    {
        $feed = null;
        if (is_string($feedId) && $feedId !== '') {
            $payload = $client->podcastByFeedId($feedId, useCache: false);
            $candidate = $payload['feed'] ?? null;
            if (is_array($candidate)) {
                $feed = $candidate;
            }
        }

        $newUrl = is_array($feed) ? ($feed['url'] ?? $feed['originalUrl'] ?? null) : null;
        if (is_string($newUrl) && $newUrl !== '' && $newUrl !== $show->rss_url) {
            return $feed;
        }

        $payload = $client->podcastByFeedUrl($show->rss_url, useCache: false);
        $candidate = $payload['feed'] ?? null;

        return is_array($candidate) ? $candidate : $feed;
    }

    private function declaredNewFeedUrl(\SimpleXMLElement $xml, RssFeedFetcher $fetcher): ?string
    {
        $channel = $xml->channel ?? $xml;
        foreach ($this->itunesChildren($channel) as $itunes) {
            $candidate = trim((string) ($itunes->{'new-feed-url'} ?? ''));
            if ($candidate !== '') {
                return $this->normalizedSafeFeedUrl($candidate, $fetcher);
            }
        }
        $candidate = trim((string) ($channel->{'new-feed-url'} ?? ''));
        if ($candidate === '') {
            return null;
        }

        return $this->normalizedSafeFeedUrl($candidate, $fetcher);
    }

    private function normalizedSafeFeedUrl(string $url, RssFeedFetcher $fetcher): ?string
    {
        $url = trim($url);
        if (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, strlen('http://'));
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            return null;
        }
        if (! $fetcher->isSafeFeedUrl($url)) {
            Log::info('catalog.rss.rejected_feed_move', [
                'show_id' => $this->showId,
                'url' => $url,
            ]);

            return null;
        }

        return $url;
    }

    private function syncChannelMetadata(Show $show, \SimpleXMLElement $xml): bool
    {
        $channel = $xml->channel ?? $xml;
        $updates = [];

        $title = $this->channelTitle($channel);
        if ($title !== '' && $title !== (string) $show->title) {
            $this->recordMetadataChange($show, 'title', $show->title, $title);
            $updates['title'] = $title;
        }

        $artwork = $this->channelArtworkUrl($channel);
        if ($artwork !== null && $artwork !== $show->artwork_url) {
            $this->recordMetadataChange($show, 'artwork_url', $show->artwork_url, $artwork);
            $updates['artwork_url'] = $artwork;
        }

        $description = $this->channelDescription($channel);
        if ($description !== null && $description !== (string) $show->description) {
            $this->recordMetadataChange($show, 'description', $show->description, $description);
            $updates['description'] = $description;
        }

        if ($updates === []) {
            return false;
        }

        $show->update($updates);
        Log::info('catalog.rss.channel_metadata_updated', [
            'show_id' => $show->id,
            'fields' => array_keys($updates),
        ]);

        return true;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function itunesChildren(\SimpleXMLElement $channel): array
    {
        $uris = [
            'http://www.itunes.com/dtds/podcast-1.0.dtd',
            'https://www.itunes.com/dtds/podcast-1.0.dtd',
            'http://www.itunes.com/DTDs/Podcast-1.0.dtd',
        ];
        foreach ($channel->getDocNamespaces(true) as $uri) {
            if (is_string($uri) && stripos($uri, 'itunes.com') !== false) {
                $uris[] = $uri;
            }
        }

        $nodes = [];
        foreach (array_unique($uris) as $uri) {
            $children = $channel->children($uri);
            if ($children !== null && $children->count() > 0) {
                $nodes[] = $children;
            }
        }

        return $nodes;
    }

    private function channelTitle(\SimpleXMLElement $channel): string
    {
        foreach ($this->itunesChildren($channel) as $itunes) {
            $title = $this->cleanText((string) ($itunes->title ?? ''), 255);
            if ($title !== '') {
                return $title;
            }
        }
        $title = $this->cleanText((string) ($channel->title ?? ''), 255);
        if ($title !== '') {
            return $title;
        }

        return $this->cleanText((string) ($channel->children('http://www.w3.org/2005/Atom')->title ?? ''), 255);
    }

    private function channelDescription(\SimpleXMLElement $channel): ?string
    {
        $raw = '';
        foreach ($this->itunesChildren($channel) as $itunes) {
            $raw = (string) ($itunes->summary ?? '');
            if (trim($raw) === '') {
                $raw = (string) ($itunes->subtitle ?? '');
            }
            if (trim($raw) !== '') {
                break;
            }
        }
        if (trim($raw) === '') {
            $raw = (string) ($channel->description ?? '');
        }
        if (trim($raw) === '') {
            $raw = (string) ($channel->children('http://www.w3.org/2005/Atom')->subtitle ?? '');
        }
        $description = $this->cleanText($raw);
        if ($description === '') {
            return null;
        }

        return $description;
    }

    private function channelArtworkUrl(\SimpleXMLElement $channel): ?string
    {
        $candidates = [];
        foreach ($this->itunesChildren($channel) as $itunes) {
            if (! isset($itunes->image)) {
                continue;
            }
            foreach ($itunes->image as $image) {
                $attributes = $image->attributes() ?: [];
                $candidates[] = (string) ($attributes['href'] ?? '');
                $candidates[] = (string) ($image['href'] ?? '');
                $candidates[] = (string) ($image->url ?? '');
            }
        }
        foreach ($channel->getDocNamespaces(true) as $prefix => $uri) {
            if (! is_string($uri) || stripos($uri, 'itunes.com') === false) {
                continue;
            }
            $alias = ($prefix === '' || $prefix === 'itunes') ? 'itunes' : $prefix;
            $channel->registerXPathNamespace($alias, $uri);
            foreach ($channel->xpath('./'.$alias.':image/@href') ?: [] as $href) {
                $candidates[] = (string) $href;
            }
        }
        $candidates[] = (string) ($channel->image->url ?? '');
        $candidates[] = (string) ($channel->image['href'] ?? '');
        $atom = $channel->children('http://www.w3.org/2005/Atom');
        $candidates[] = (string) ($atom->logo ?? '');
        $candidates[] = (string) ($atom->icon ?? '');
        foreach ($candidates as $candidate) {
            $sanitized = ArtworkUrl::sanitize(trim($candidate));
            if ($sanitized === null) {
                continue;
            }
            if (str_starts_with($sanitized, 'http://')) {
                $https = ArtworkUrl::sanitize('https://'.substr($sanitized, strlen('http://')));
                if ($https !== null) {
                    return $https;
                }
            }

            return $sanitized;
        }

        return null;
    }

    private function cleanText(string $value, int $maxChars = 10000): string
    {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return CatalogText::utf8(trim($text), $maxChars);
    }

    private function recordMetadataChange(Show $show, string $field, ?string $old, ?string $new): void
    {
        DB::table('show_metadata_changes')->insert([
            'id' => (string) Str::ulid(),
            'show_id' => $show->id,
            'field' => $field,
            'old_value' => $old === null ? null : CatalogText::utf8($old, 65000),
            'new_value' => $new === null ? null : CatalogText::utf8($new, 65000),
            'detected_at' => now(),
        ]);
    }

    private function applyResolvedUrl(Show $show, string $resolvedUrl): bool
    {
        if ($resolvedUrl === $show->rss_url) {
            return false;
        }

        $conflict = Show::query()
            ->where('rss_url_hash', hash('sha256', $resolvedUrl))
            ->whereKeyNot($show->id)
            ->exists();

        $this->recordMetadataChange(
            $show,
            $conflict ? 'rss_url_redirect_conflict' : 'rss_url',
            $show->rss_url,
            $resolvedUrl,
        );

        if ($conflict) {
            Log::info('catalog.rss.feed_move_conflict', [
                'show_id' => $show->id,
                'from' => $show->rss_url,
                'to' => $resolvedUrl,
            ]);

            return false;
        }

        $from = $show->rss_url;
        $show->update(['rss_url' => $resolvedUrl]);
        Log::info('catalog.rss.feed_moved', [
            'show_id' => $show->id,
            'from' => $from,
            'to' => $resolvedUrl,
        ]);

        return true;
    }

    private function enclosureUrl(\SimpleXMLElement $item): ?string
    {
        $candidates = [];
        if (isset($item->enclosure)) {
            foreach ($item->enclosure as $enclosure) {
                $attributes = $enclosure->attributes();
                $candidates[] = trim((string) ($attributes['url'] ?? $enclosure['url'] ?? ''));
            }
        }
        $media = $item->children('http://search.yahoo.com/mrss/', false);
        if (isset($media->content)) {
            foreach ($media->content as $content) {
                $attributes = $content->attributes();
                $candidates[] = trim((string) ($attributes['url'] ?? ''));
            }
        }
        foreach ($candidates as $candidate) {
            $url = filter_var($candidate, FILTER_VALIDATE_URL);
            if (is_string($url) && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return null;
    }

    private function durationSeconds(\SimpleXMLElement $item): ?int
    {
        $duration = trim((string) $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->duration);
        if ($duration === '') {
            return null;
        }
        if (ctype_digit($duration)) {
            return (int) $duration;
        }

        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        $show = Show::find($this->showId);
        if (! $show) {
            return;
        }
        $this->persistFailure($show, null, $exception);
    }

    private function persistIndexOnlySuccess(Show $show, FeedSyncRun $syncRun, int $seeded, Throwable $rssError): void
    {
        try {
            $show->feedState()->updateOrCreate([], [
                'last_success_at' => now(),
                'consecutive_failures' => 0,
                'state' => 'healthy',
                'next_poll_at' => $this->nextPollAt($show),
                'last_error' => null,
            ]);
            $syncRun->update([
                'state' => 'completed',
                'new_episode_count' => $seeded,
                'error' => CatalogText::utf8('rss_deferred: '.$rssError->getMessage(), 400),
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            //
        }
        Log::info('catalog.rss.deferred_after_index', [
            'show_id' => $show->id,
            'seeded' => $seeded,
            'rss_error' => CatalogText::utf8($rssError->getMessage(), 200),
        ]);
    }

    private function nextPollAt(Show $show, bool $failed = false): \Carbon\CarbonInterface
    {
        $followed = DB::table('follows')->where('show_id', $show->id)->exists();
        if ($followed) {
            return now()->addMinutes(max(5, (int) config('rss.followed_poll_minutes', 10)));
        }
        if ($failed) {
            return now()->addHour();
        }

        return now()->addMinutes(max(15, (int) config('rss.unfollowed_poll_minutes', 60)));
    }

    private function persistFailure(Show $show, ?FeedSyncRun $syncRun, ?Throwable $exception): void
    {
        $message = $this->safeFailureMessage($exception);
        try {
            $syncRun?->update([
                'state' => 'failed',
                'error' => $message,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let diagnostics take down the worker.
        }

        try {
            $state = $show->feedState()->firstOrCreate([], ['state' => 'failed']);
            $failures = $state->consecutive_failures + 1;
            $deadHost = $this->isDeadFeedHost($exception);
            $state->update([
                'last_failure_at' => now(),
                'consecutive_failures' => $failures,
                'state' => (! $deadHost && $failures >= config('rss.failure_stale_threshold')) ? 'stale' : 'failed',
                'next_poll_at' => $deadHost
                    ? $this->nextPollAt($show, failed: true)
                    : ($failures >= config('rss.failure_stale_threshold') ? now()->addWeek() : now()->addHours(min(24, 2 ** $failures))),
                'last_error' => $message,
            ]);
        } catch (Throwable) {
            //
        }
    }

    private function isPermanentFeedFailure(Throwable $exception): bool
    {
        if ($this->isDeadFeedHost($exception)) {
            return true;
        }
        if (! $exception instanceof RequestException) {
            return false;
        }

        return in_array($exception->response?->status(), [404, 410, 403], true);
    }

    private function isDeadFeedHost(?Throwable $exception): bool
    {
        if ($exception instanceof UnsafeFeedUrlException || $exception instanceof ConnectionException) {
            return true;
        }
        if ($exception instanceof RequestException && in_array($exception->response?->status(), [404, 410, 403], true)) {
            return true;
        }
        $message = strtolower((string) $exception?->getMessage());
        foreach ([
            'could not resolve host',
            'could not be resolved',
            'curl error 6',
            'curl error 7',
            'name or service not known',
            'nodename nor servname',
            'getaddrinfo',
            'failed to connect',
            'connection refused',
            'no such host',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function safeFailureMessage(?Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();
            if (in_array($status, [404, 410], true)) {
                return 'RSS feed returned HTTP '.$status.'.';
            }
            if ($status) {
                return CatalogText::utf8('RSS feed returned HTTP '.$status.'.', 500);
            }
        }
        $raw = CatalogText::utf8((string) $exception?->getMessage(), 400);
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;

        return $raw !== '' ? $raw : 'RSS hydration failed.';
    }
}
