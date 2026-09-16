<?php

namespace Tests\Unit\Integrations;

use App\Integrations\PodcastIndex\PodcastIndexClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PodcastIndexClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set([
            'services.podcast_index.enabled' => true,
            'services.podcast_index.api_key' => 'key',
            'services.podcast_index.api_secret' => 'secret',
            'services.podcast_index.base_url' => 'https://api.podcastindex.org/api/1.0',
            'services.podcast_index.user_agent' => 'Pelevo/1.3 +https://pelevo.com',
        ]);
    }

    public function test_search_byterm_follows_official_query_and_auth_headers(): void
    {
        Http::fake([
            'api.podcastindex.org/api/1.0/search/byterm*' => Http::response(['status' => 'true', 'feeds' => []], 200),
        ]);

        app(PodcastIndexClient::class)->searchByTerm('batman university', 20, fast: true);

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, 'https://api.podcastindex.org/api/1.0/search/byterm')
                && str_contains($url, 'q=batman')
                && str_contains($url, 'similar=true')
                && ! str_contains($url, 'cat=')
                && ! str_contains($url, 'lang=')
                && $request->hasHeader('X-Auth-Key', 'key')
                && $request->hasHeader('User-Agent', 'Pelevo/1.3 +https://pelevo.com')
                && $request->hasHeader('Authorization');
        });
    }

    public function test_trending_uses_category_id_and_a_30_day_since_window(): void
    {
        Http::fake([
            'api.podcastindex.org/api/1.0/podcasts/trending*' => Http::response(['status' => 'true', 'feeds' => []], 200),
        ]);

        app(PodcastIndexClient::class)->trending('55', 24, 'en', fast: true);

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, '/api/1.0/podcasts/trending')
                && str_contains($url, 'cat=55')
                && str_contains($url, 'lang=en')
                && str_contains($url, 'since=-2592000');
        });
    }
}
