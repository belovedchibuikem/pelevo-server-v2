<?php

namespace App\Jobs;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Events\NewEpisodePublished;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Integrations\Rss\RssFeedFetcher;
use App\Models\Episode;
use App\Models\FeedSyncRun;
use App\Models\Show;
use App\Support\ArtworkUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class HydrateRssFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 180;

    public int $uniqueFor = 600;

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

        try {
            // Seed a modest Podcast Index page first so the detail screen can
            // populate while RSS continues for full archives (including 2K+).
            $seeded = $this->seedFromPodcastIndex($show, $podcastIndex);

            $originalRssUrl = $show->rss_url;
            $result = $this->fetchFollowingFeedMoves($show, $fetcher);
            if ($result['not_modified']) {
                $urlChanged = false;
                DB::transaction(function () use ($show, $syncRun, $result, $seeded, &$urlChanged): void {
                    $urlChanged = $this->persistCanonicalFeedUrl($show, $result);
                    $show->feedState()->updateOrCreate([], [
                        'last_success_at' => now(),
                        'consecutive_failures' => 0,
                        'state' => 'healthy',
                        'next_poll_at' => now()->addHour(),
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
                if ($urlChanged || $show->rss_url !== $originalRssUrl) {
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
                    'consecutive_failures' => 0,
                    'state' => 'healthy',
                    'next_poll_at' => now()->addHour(),
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

            foreach ($outcome['episodes'] as $episode) {
                NewEpisodePublished::dispatch($episode);
            }
            if ($outcome['episodes'] !== [] || $seeded > 0 || $outcome['catalog_changed']) {
                $cache->show($show->id);
            }
        } catch (Throwable $exception) {
            $syncRun->update([
                'state' => 'failed',
                'error' => substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
            Log::warning('catalog.rss.hydrate_failed', [
                'show_id' => $this->showId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Page through Podcast Index episodes with a small max and optional `since`
     * to stay under rate limits. PI hard-caps at 1000 newest items and has no
     * older-than cursor, so RSS remains the source for full archives.
     */
    private function seedFromPodcastIndex(Show $show, PodcastIndexClient $client): int
    {
        if (! config('services.podcast_index.enabled')) {
            return 0;
        }

        $feedId = DB::table('show_external_ids')
            ->where('show_id', $show->id)
            ->where('provider', 'podcast_index')
            ->value('external_id');

        if (! is_string($feedId) || $feedId === '') {
            return 0;
        }

        $pageSize = max(1, min((int) config('services.podcast_index.episode_page_size', 100), 1000));
        $since = null;
        if ($show->episodes()->exists() && $show->feedState?->last_success_at) {
            $since = $show->feedState->last_success_at->getTimestamp();
        }

        $imported = 0;

        try {
            // One modest page for seed / incremental `since` updates. PI hard-caps
            // at 1000 and has no older-than cursor; RSS fills the full archive.
            $payload = $client->episodesByFeedId($feedId, $pageSize, $since, useCache: false);
            $items = $payload['items'] ?? [];
            if (! is_array($items) || $items === []) {
                return 0;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $guid = $this->podcastIndexGuid($item);
                if ($guid === '') {
                    continue;
                }
                if ($this->upsertPodcastIndexEpisode($show, $item, $guid)) {
                    $imported++;
                }
            }
        } catch (PodcastIndexException $exception) {
            Log::info('catalog.podcast_index.episode_seed_skipped', [
                'show_id' => $show->id,
                'feed_id' => $feedId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $imported;
    }

    private function podcastIndexGuid(array $item): string
    {
        $guid = trim((string) ($item['guid'] ?? ''));
        if ($guid !== '') {
            return $guid;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            return 'podcastindex:'.$id;
        }

        return hash('sha256', trim((string) ($item['title'] ?? '')).'|'.trim((string) ($item['enclosureUrl'] ?? '')).'|'.trim((string) ($item['datePublished'] ?? '')));
    }

    private function upsertPodcastIndexEpisode(Show $show, array $item, string $guid): bool
    {
        $audioUrl = filter_var($item['enclosureUrl'] ?? null, FILTER_VALIDATE_URL);
        if (! is_string($audioUrl) || ! str_starts_with($audioUrl, 'https://')) {
            return false;
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
                'external_id' => isset($item['id']) ? (string) $item['id'] : null,
                'title' => strip_tags((string) ($item['title'] ?? 'Untitled episode')),
                'description' => strip_tags((string) ($item['description'] ?? '')),
                'audio_url' => $audioUrl,
                'duration_seconds' => $duration,
                'published_at' => $published,
                'availability' => 'available',
            ]
        );

        return $episode->wasRecentlyCreated;
    }

    private function upsertRssItems(Show $show, \SimpleXMLElement $xml): array
    {
        $channel = $xml->channel ?? $xml;
        $chunkSize = max(1, (int) config('rss.upsert_chunk', 100));
        $buffer = [];
        $newEpisodes = [];

        foreach ($channel->item ?? [] as $item) {
            $guid = trim((string) ($item->guid ?? '')) ?: hash('sha256', trim((string) $item->title).'|'.trim((string) ($item->enclosure['url'] ?? '')).'|'.trim((string) $item->pubDate));
            $audioUrl = $this->enclosureUrl($item);
            if (! $audioUrl || ! str_starts_with($audioUrl, 'https://')) {
                continue;
            }
            $buffer[] = [
                'guid' => $guid,
                'title' => strip_tags((string) $item->title),
                'description' => strip_tags((string) ($item->description ?? '')),
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

    private function declaredNewFeedUrl(\SimpleXMLElement $xml, RssFeedFetcher $fetcher): ?string
    {
        $channel = $xml->channel ?? $xml;
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $candidate = trim((string) ($itunes->{'new-feed-url'} ?? $channel->{'new-feed-url'} ?? ''));
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
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $updates = [];

        $description = $this->channelDescription($channel, $itunes);
        if ($description !== null && $description !== (string) $show->description) {
            $this->recordMetadataChange($show, 'description', $show->description, $description);
            $updates['description'] = $description;
        }

        $artwork = $this->channelArtworkUrl($channel, $itunes);
        if ($artwork !== null && $artwork !== $show->artwork_url) {
            $this->recordMetadataChange($show, 'artwork_url', $show->artwork_url, $artwork);
            $updates['artwork_url'] = $artwork;
        }

        $title = trim(strip_tags((string) ($channel->title ?? '')));
        if ($title !== '' && $title !== (string) $show->title) {
            $this->recordMetadataChange($show, 'title', $show->title, $title);
            $updates['title'] = $title;
        }

        if ($updates === []) {
            return false;
        }

        $show->update($updates);

        return true;
    }

    private function channelDescription(\SimpleXMLElement $channel, \SimpleXMLElement $itunes): ?string
    {
        $raw = trim((string) ($itunes->summary ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($channel->description ?? ''));
        }
        if ($raw === '') {
            return null;
        }

        return strip_tags($raw);
    }

    private function channelArtworkUrl(\SimpleXMLElement $channel, \SimpleXMLElement $itunes): ?string
    {
        $candidates = [];
        if (isset($itunes->image)) {
            foreach ($itunes->image as $image) {
                $attributes = $image->attributes() ?: [];
                $candidates[] = (string) ($attributes['href'] ?? '');
                $candidates[] = (string) ($image['href'] ?? '');
                $candidates[] = (string) ($image->url ?? '');
            }
        }
        $channel->registerXPathNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        foreach ($channel->xpath('.//itunes:image/@href') ?: [] as $href) {
            $candidates[] = (string) $href;
        }
        $candidates[] = (string) ($channel->image->url ?? '');
        $candidates[] = (string) ($channel->image['href'] ?? '');
        foreach ($candidates as $candidate) {
            $sanitized = ArtworkUrl::sanitize(trim($candidate));
            if ($sanitized !== null) {
                return $sanitized;
            }
        }

        return null;
    }

    private function recordMetadataChange(Show $show, string $field, ?string $old, ?string $new): void
    {
        DB::table('show_metadata_changes')->insert([
            'id' => (string) Str::ulid(),
            'show_id' => $show->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
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

        DB::table('show_metadata_changes')->insert([
            'id' => (string) Str::ulid(),
            'show_id' => $show->id,
            'field' => $conflict ? 'rss_url_redirect_conflict' : 'rss_url',
            'old_value' => $show->rss_url,
            'new_value' => $resolvedUrl,
            'detected_at' => now(),
        ]);

        if ($conflict) {
            return false;
        }

        $show->update(['rss_url' => $resolvedUrl]);

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
        $state = $show->feedState()->firstOrCreate([], ['state' => 'failed']);
        $failures = $state->consecutive_failures + 1;
        $state->update([
            'last_failure_at' => now(),
            'consecutive_failures' => $failures,
            'state' => $failures >= config('rss.failure_stale_threshold') ? 'stale' : 'failed',
            'next_poll_at' => $failures >= config('rss.failure_stale_threshold') ? now()->addWeek() : now()->addHours(min(24, 2 ** $failures)),
            'last_error' => substr((string) $exception?->getMessage(), 0, 1000),
        ]);
    }
}
