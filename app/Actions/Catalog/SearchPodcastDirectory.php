<?php

namespace App\Actions\Catalog;

use App\Integrations\Apple\ApplePodcastsClient;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\PodcastIndex\PodcastIndexException;
use App\Integrations\Rss\FeedAddress;
use App\Integrations\Rss\FeedProbe;
use App\Models\Show;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Looks up a podcast by name (Apple and Podcast Index) or by a pasted feed
 * URL. Dead feeds are reported and not offered. Matching feeds collapse to
 * one row. Nothing is written here — subscribing persists the chosen feed.
 */
final class SearchPodcastDirectory
{
    public function __construct(
        private readonly ApplePodcastsClient $apple,
        private readonly PodcastIndexClient $podcastIndex,
        private readonly FeedProbe $probe,
        private readonly DirectoryRateGate $gate,
    ) {}

    /**
     * @return array{query: string, candidates: list<array<string, mixed>>, unavailable_message: ?string}
     */
    public function handle(string $query): array
    {
        $query = trim($query);
        $key = 'directory-search:'.hash('sha256', mb_strtolower($query));

        $result = Cache::remember($key, now()->addSeconds(45), function () use ($query): array {
            if (FeedAddress::looksLikeUrl($query)) {
                return $this->resolveUrl($query);
            }

            if (mb_strlen($query) < 3) {
                return $this->payload($query, [], null);
            }

            return $this->searchByName($query);
        });

        $result['candidates'] = array_map(
            fn (array $candidate): array => $this->withCatalog($candidate),
            $result['candidates'],
        );

        return $result;
    }

    /**
     * @return array{query: string, candidates: list<array<string, mixed>>, unavailable_message: ?string}
     */
    private function resolveUrl(string $query): array
    {
        $address = FeedAddress::tryParse($query);
        if ($address === null) {
            return $this->payload($query, [], 'That feed address can’t be added.');
        }

        $fromIndex = $this->podcastIndexByUrl($address);
        if ($fromIndex !== null) {
            return $fromIndex['dead']
                ? $this->payload($query, [], 'This podcast feed is unavailable, so it can’t be added.')
                : $this->payload($query, [$fromIndex['candidate']], null);
        }

        if (! $this->gate->acquire('probe')) {
            throw $this->gate->blocked();
        }

        $probed = $this->probe->inspect($address->httpsUrl());
        if ($probed['health'] !== 'alive') {
            return $this->payload($query, [], $probed['message'] ?? 'This podcast feed is unavailable, so it can’t be added.');
        }

        $title = $probed['title'] ?: 'Podcast';
        $externalId = substr(hash('sha256', $address->key), 0, 32);

        return $this->payload($query, [[
            'id' => 'rss:'.$externalId,
            'title' => $title,
            'source' => 'rss',
            'author' => $probed['author'],
            'artwork_url' => $probed['artwork'],
            'feed_url' => $address->httpsUrl(),
            'external_id' => $externalId,
            'episode_count' => null,
            'feed_state' => 'alive',
        ]], null);
    }

    /**
     * @return array{dead: bool, candidate: array<string, mixed>}|null
     */
    private function podcastIndexByUrl(FeedAddress $address): ?array
    {
        if (! config('services.podcast_index.enabled')) {
            return null;
        }
        if (! $this->gate->acquire('podcast_index')) {
            return null;
        }

        try {
            $payload = $this->podcastIndex->podcastByFeedUrl($address->httpsUrl());
        } catch (PodcastIndexException $exception) {
            Log::info('directory.search.feed_url_index_failed', ['message' => $exception->getMessage()]);

            return null;
        }

        $feed = $payload['feed'] ?? null;
        if (! is_array($feed) || $feed === []) {
            return null;
        }

        if ($this->isDead($feed)) {
            return ['dead' => true, 'candidate' => []];
        }

        $candidate = $this->fromPodcastIndex($feed);

        return $candidate === null ? null : ['dead' => false, 'candidate' => $candidate];
    }

    /**
     * @return array{query: string, candidates: list<array<string, mixed>>, unavailable_message: ?string}
     */
    private function searchByName(string $query): array
    {
        $rows = [];
        $attempted = 0;
        $blocked = 0;
        $sawDead = false;

        if (config('services.apple_podcasts.enabled')) {
            $attempted++;
            if (! $this->gate->acquire('apple')) {
                $blocked++;
            } else {
                try {
                    foreach ($this->apple->search($query) as $row) {
                        $candidate = $this->fromApple($row);
                        if ($candidate !== null) {
                            $rows[] = $candidate;
                        }
                    }
                } catch (DirectoryRateLimited) {
                    $blocked++;
                } catch (\Throwable $exception) {
                    Log::warning('directory.search.apple_failed', ['query' => $query, 'message' => $exception->getMessage()]);
                }
            }
        }

        if (config('services.podcast_index.enabled')) {
            $attempted++;
            if (! $this->gate->acquire('podcast_index')) {
                $blocked++;
            } else {
                try {
                    $feeds = $this->podcastIndex->searchByTerm($query, 20, fast: true)['feeds'] ?? [];
                    if (! is_array($feeds)) {
                        $feeds = [];
                    }
                    foreach ($feeds as $feed) {
                        if (! is_array($feed)) {
                            continue;
                        }
                        if ($this->isDead($feed)) {
                            $sawDead = true;

                            continue;
                        }
                        $candidate = $this->fromPodcastIndex($feed);
                        if ($candidate !== null) {
                            $rows[] = $candidate;
                        }
                    }
                } catch (PodcastIndexException $exception) {
                    Log::info('directory.search.podcast_index_failed', ['query' => $query, 'message' => $exception->getMessage()]);
                }
            }
        }

        if ($rows === [] && $attempted > 0 && $blocked === $attempted) {
            throw $this->gate->blocked();
        }

        $merged = $this->merge($rows);

        $message = $merged === [] && $sawDead
            ? 'This podcast feed is unavailable, so it can’t be added.'
            : null;

        return $this->payload($query, $merged, $message);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fromApple(array $row): ?array
    {
        $externalId = trim((string) ($row['collectionId'] ?? ''));
        $title = trim((string) ($row['collectionName'] ?? ''));
        $address = FeedAddress::tryParse((string) ($row['feedUrl'] ?? ''));
        if ($externalId === '' || $title === '' || $address === null) {
            return null;
        }

        $artwork = $row['artworkUrl600'] ?? $row['artworkUrl100'] ?? null;

        return [
            'id' => 'apple:'.$externalId,
            'title' => $title,
            'source' => 'apple',
            'author' => $this->nullableString($row['artistName'] ?? null),
            'artwork_url' => is_string($artwork) ? $artwork : null,
            'feed_url' => $address->httpsUrl(),
            'external_id' => $externalId,
            'episode_count' => $this->count($row['trackCount'] ?? null),
            'feed_state' => 'alive',
        ];
    }

    /**
     * @param  array<string, mixed>  $feed
     * @return array<string, mixed>|null
     */
    private function fromPodcastIndex(array $feed): ?array
    {
        if ($this->isDead($feed)) {
            return null;
        }

        $externalId = trim((string) ($feed['id'] ?? ''));
        $title = trim((string) ($feed['title'] ?? ''));
        $address = FeedAddress::tryParse((string) ($feed['url'] ?? $feed['originalUrl'] ?? ''));
        if ($externalId === '' || $title === '' || $address === null) {
            return null;
        }

        $artwork = $feed['artwork'] ?? $feed['image'] ?? null;

        return [
            'id' => 'podcastindex:'.$externalId,
            'title' => $title,
            'source' => 'podcast_index',
            'author' => $this->nullableString($feed['author'] ?? null),
            'artwork_url' => is_string($artwork) ? $artwork : null,
            'feed_url' => $address->httpsUrl(),
            'external_id' => $externalId,
            'episode_count' => $this->count($feed['episodeCount'] ?? null),
            'feed_state' => 'alive',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function merge(array $rows): array
    {
        $order = [];
        $byKey = [];
        foreach ($rows as $row) {
            $address = FeedAddress::tryParse((string) ($row['feed_url'] ?? ''));
            $key = $address?->key ?? (string) $row['id'];
            if (! isset($byKey[$key])) {
                $order[] = $key;
                $byKey[$key] = $row;

                continue;
            }
            $byKey[$key] = $this->prefer($byKey[$key], $row);
        }

        $merged = [];
        foreach ($order as $key) {
            $merged[] = $byKey[$key];
        }

        return $merged;
    }

    /**
     * Keeps the first source (Apple, then Podcast Index) and fills gaps
     * from the duplicate row. The Podcast Index id is kept when present so
     * subscribe can still dedupe on that provider.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function prefer(array $current, array $extra): array
    {
        foreach (['author', 'artwork_url', 'feed_url', 'external_id'] as $field) {
            if (($current[$field] ?? null) === null || $current[$field] === '') {
                $current[$field] = $extra[$field] ?? $current[$field] ?? null;
            }
        }
        $left = $current['episode_count'] ?? null;
        $right = $extra['episode_count'] ?? null;
        if (is_int($right) && (! is_int($left) || $right > $left)) {
            $current['episode_count'] = $right;
        }
        if (($current['source'] ?? '') !== 'podcast_index' && ($extra['source'] ?? '') === 'podcast_index') {
            $current['podcast_index_id'] = $extra['external_id'];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function withCatalog(array $candidate): array
    {
        $showId = $this->catalogShowId($candidate);
        $candidate['show_id'] = $showId;
        $candidate['in_catalog'] = $showId !== null;
        unset($candidate['podcast_index_id']);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function catalogShowId(array $candidate): ?string
    {
        $externalId = trim((string) ($candidate['external_id'] ?? ''));
        $source = (string) ($candidate['source'] ?? '');
        if ($externalId !== '' && $source !== '') {
            $showId = DB::table('show_external_ids')
                ->where('provider', $source)
                ->where('external_id', $externalId)
                ->value('show_id');
            if (is_string($showId) && $showId !== '') {
                return $showId;
            }
        }

        $indexId = trim((string) ($candidate['podcast_index_id'] ?? ''));
        if ($indexId !== '') {
            $showId = DB::table('show_external_ids')
                ->where('provider', 'podcast_index')
                ->where('external_id', $indexId)
                ->value('show_id');
            if (is_string($showId) && $showId !== '') {
                return $showId;
            }
        }

        $address = FeedAddress::tryParse((string) ($candidate['feed_url'] ?? ''));
        if ($address === null) {
            return null;
        }

        $showId = Show::query()->where('rss_url_hash', hash('sha256', $address->httpsUrl()))->value('id');

        return is_string($showId) && $showId !== '' ? $showId : null;
    }

    /**
     * @param  array<string, mixed>  $feed
     */
    private function isDead(array $feed): bool
    {
        return filter_var($feed['dead'] ?? false, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array{query: string, candidates: list<array<string, mixed>>, unavailable_message: ?string}
     */
    private function payload(string $query, array $candidates, ?string $message): array
    {
        return [
            'query' => $query,
            'candidates' => $candidates,
            'unavailable_message' => $message,
        ];
    }
}
