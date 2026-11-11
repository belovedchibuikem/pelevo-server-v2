<?php

namespace App\Integrations\Apple;

use App\Actions\Catalog\DirectoryRateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Apple Podcasts directory via the public iTunes Search API.
 * No API secret. Results carry the publisher feed URL.
 */
final class ApplePodcastsClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        if (! config('services.apple_podcasts.enabled')) {
            return [];
        }

        $base = rtrim((string) config('services.apple_podcasts.base_url', 'https://itunes.apple.com'), '/');

        try {
            $response = Http::acceptJson()
                ->withUserAgent('Pelevo/1.3 +https://pelevo.com')
                ->connectTimeout(5)
                ->timeout(max(4, (int) config('services.apple_podcasts.timeout', 8)))
                ->get($base.'/search', [
                    'term' => $term,
                    'media' => 'podcast',
                    'entity' => 'podcast',
                    'limit' => max(1, min($limit, 20)),
                ]);
        } catch (ConnectionException) {
            return [];
        }

        if ($response->status() === 429) {
            throw new DirectoryRateLimited('Apple Podcasts rate limited (429).');
        }

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results');

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }
}
