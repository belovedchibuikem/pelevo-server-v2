<?php

namespace App\Integrations\PodcastIndex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PodcastIndexClient
{
    public function __construct(private readonly PodcastIndexAuthenticator $authenticator) {}

    public function searchByTerm(string $query, int $limit, ?string $language = null): array
    {
        return $this->get('search/byterm', ['q' => $query, 'max' => min($limit, 100), 'lang' => $language]);
    }

    private function get(string $path, array $query): array
    {
        if (! config('services.podcast_index.enabled')) {
            throw new PodcastIndexException('Podcast Index is disabled.');
        }
        $query = array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');
        ksort($query);
        $cacheKey = 'podcast-index:'.hash('sha256', $path.'?'.http_build_query($query));

        try {
            return Cache::remember($cacheKey, now()->addMinutes(5)->addSeconds(random_int(0, 30)), function () use ($path, $query): array {
                $response = Http::baseUrl(rtrim((string) config('services.podcast_index.base_url'), '/'))->withHeaders($this->authenticator->headers())->acceptJson()->connectTimeout(2)->timeout((int) config('services.podcast_index.timeout', 5))->retry([100, 300], throw: false)->get($path, $query);
                if (! $response->successful()) {
                    throw new PodcastIndexException('Podcast Index request failed with status '.$response->status());
                }

                return $response->json() ?? throw new PodcastIndexException('Podcast Index returned malformed JSON.');
            });
        } catch (ConnectionException $exception) {
            throw new PodcastIndexException('Podcast Index is unavailable.', previous: $exception);
        }
    }
}
