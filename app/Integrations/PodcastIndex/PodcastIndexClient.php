<?php

namespace App\Integrations\PodcastIndex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class PodcastIndexClient
{
    public function __construct(private readonly PodcastIndexAuthenticator $authenticator) {}

    public function searchByTerm(string $query, int $limit, ?string $language = null, ?string $category = null): array
    {
        return $this->get('search/byterm', [
            'q' => $query,
            'max' => min($limit, 100),
            'lang' => $language,
            'cat' => $category,
        ]);
    }

    public function trending(?string $category = null, int $limit = 20, ?string $language = null): array
    {
        return $this->get('podcasts/trending', [
            'max' => min($limit, 100),
            'lang' => $language,
            'cat' => $category,
        ]);
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
                $response = Http::baseUrl(rtrim((string) config('services.podcast_index.base_url'), '/'))
                    ->withHeaders($this->authenticator->headers())
                    ->acceptJson()
                    ->connectTimeout(2)
                    ->timeout((int) config('services.podcast_index.timeout', 5))
                    ->retry(2, 100, function ($exception, $request): bool {
                        return $exception instanceof ConnectionException;
                    }, throw: false)
                    ->get($path, $query);

                if (! $response->successful()) {
                    $detail = trim(Str::limit(strip_tags((string) $response->body()), 180, ''));
                    throw new PodcastIndexException(
                        'Podcast Index request failed with status '.$response->status()
                        .($detail !== '' ? ': '.$detail : '')
                    );
                }

                return $response->json() ?? throw new PodcastIndexException('Podcast Index returned malformed JSON.');
            });
        } catch (ConnectionException $exception) {
            throw new PodcastIndexException('Podcast Index is unavailable.', previous: $exception);
        }
    }
}
