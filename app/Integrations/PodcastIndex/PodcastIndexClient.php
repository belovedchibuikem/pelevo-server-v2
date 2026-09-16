<?php

namespace App\Integrations\PodcastIndex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Podcast Index HTTP client.
 *
 * Official contract (https://podcastindex-org.github.io/docs-api/):
 * - Every request: User-Agent, X-Auth-Key, X-Auth-Date, Authorization = sha1(key+secret+date)
 * - search/byterm: q, max, similar, clean, fulltext, aponly, val — not cat/lang
 * - podcasts/trending: max, since, lang, cat, notcat (cat is name or numeric id)
 * - categories/list: no query
 * - episodes/byfeedid: id, max (≤1000), since
 * - 429: stop and surface; do not tight-loop retries
 */
final class PodcastIndexClient
{
    public function __construct(private readonly PodcastIndexAuthenticator $authenticator) {}

    public function searchByTerm(string $query, int $limit, bool $fast = false): array
    {
        return $this->get('search/byterm', [
            'q' => $query,
            'max' => max(1, min($limit, $fast ? 20 : 50)),
            'similar' => 'true',
        ], timeoutSeconds: $fast ? 8 : null, retries: $fast ? 0 : 1);
    }

    public function trending(?string $category = null, int $limit = 20, ?string $language = null, bool $fast = false): array
    {
        return $this->get('podcasts/trending', [
            'max' => max(1, min($limit, $fast ? 24 : 50)),
            'lang' => $language,
            'cat' => $category,
            // PI’s default window is a few days and often returns zero feeds per category.
            'since' => -2592000,
        ], timeoutSeconds: $fast ? 8 : null, retries: $fast ? 0 : 1);
    }

    /**
     * @return array{status?: string, feeds?: list<array{id?: int|string, name?: string}>, count?: int}
     */
    public function categoriesList(bool $fast = false): array
    {
        return $this->get('categories/list', [], timeoutSeconds: $fast ? 8 : null, retries: $fast ? 0 : 1);
    }

    /**
     * Fetch one page of episodes for a Podcast Index feed id.
     *
     * Best practice: keep max modest (default 100, hard cap 1000), use `since`
     * for incremental sync instead of refetching the whole window, cache when
     * safe, and back off on HTTP 429. PI has no older-than cursor, so full
     * archives beyond the newest window still come from RSS.
     */
    public function episodesByFeedId(string $feedId, int $max = 100, ?int $since = null, bool $useCache = true): array
    {
        return $this->get('episodes/byfeedid', [
            'id' => $feedId,
            'max' => max(1, min($max, 1000)),
            'since' => $since,
        ], $useCache);
    }

    private function get(string $path, array $query, bool $useCache = true, ?int $timeoutSeconds = null, int $retries = 1): array
    {
        if (! config('services.podcast_index.enabled')) {
            throw new PodcastIndexException('Podcast Index is disabled.');
        }
        $query = array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');
        ksort($query);
        $cacheKey = 'podcast-index:'.hash('sha256', $path.'?'.http_build_query($query));
        $resolver = fn (): array => $this->request($path, $query, $timeoutSeconds, $retries);

        try {
            if (! $useCache) {
                return $resolver();
            }

            return Cache::remember($cacheKey, now()->addMinutes(5)->addSeconds(random_int(0, 30)), $resolver);
        } catch (ConnectionException $exception) {
            throw new PodcastIndexException('Podcast Index is unavailable.', previous: $exception);
        }
    }

    private function request(string $path, array $query, ?int $timeoutSeconds = null, int $retries = 1): array
    {
        $base = rtrim((string) config('services.podcast_index.base_url'), '/');
        $url = $base.'/'.ltrim($path, '/');
        $userAgent = trim((string) config('services.podcast_index.user_agent'));
        if ($userAgent === '') {
            $userAgent = 'Pelevo/1.3 +https://pelevo.com';
        }

        $pending = Http::withHeaders($this->authenticator->headers())
            ->withUserAgent($userAgent)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeoutSeconds ?? max(8, (int) config('services.podcast_index.timeout', 10)));
        if ($retries > 0) {
            $pending = $pending->retry($retries, 250, function ($exception, $request): bool {
                return $exception instanceof ConnectionException;
            }, throw: false);
        }
        $response = $pending->get($url, $query);

        if ($response->status() === 429) {
            throw new PodcastIndexException('Podcast Index rate limited (429).');
        }

        if (! $response->successful()) {
            $detail = trim(Str::limit(strip_tags((string) $response->body()), 180, ''));
            throw new PodcastIndexException(
                'Podcast Index request failed with status '.$response->status()
                .($detail !== '' ? ': '.$detail : '')
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new PodcastIndexException('Podcast Index returned malformed JSON.');
        }

        return $payload;
    }
}
