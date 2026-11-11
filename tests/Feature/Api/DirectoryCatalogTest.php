<?php

namespace Tests\Feature\Api;

use App\Integrations\Rss\FeedUrlGuard;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DirectoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public function test_name_search_merges_apple_and_podcast_index_and_skips_dead_feeds(): void
    {
        config()->set('services.apple_podcasts.enabled', true);
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');

        $existing = Show::create([
            'rss_url' => 'https://example.com/talk.xml',
            'title' => 'Talk Tech',
            'author' => 'Ada',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://itunes.apple.com/search*' => Http::response([
                'results' => [[
                    'collectionId' => 55,
                    'collectionName' => 'Talk Tech',
                    'feedUrl' => 'https://example.com/talk.xml?utm_source=apple',
                    'artistName' => 'Ada',
                    'artworkUrl600' => 'https://cdn.example.com/talk.jpg',
                    'trackCount' => 12,
                ]],
            ]),
            'https://api.podcastindex.org/api/1.0/search/byterm*' => Http::response([
                'feeds' => [
                    [
                        'id' => 9,
                        'title' => 'Talk Tech',
                        'url' => 'http://example.com/talk.xml',
                        'author' => 'Ada',
                        'episodeCount' => 40,
                        'dead' => 0,
                    ],
                    [
                        'id' => 10,
                        'title' => 'Gone Show',
                        'url' => 'https://example.com/gone.xml',
                        'dead' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/directory/search?q=Talk%20Tech')
            ->assertOk()
            ->assertJsonPath('data.candidates.0.title', 'Talk Tech')
            ->assertJsonPath('data.candidates.0.source', 'apple')
            ->assertJsonPath('data.candidates.0.in_catalog', true)
            ->assertJsonPath('data.candidates.0.show_id', $existing->id)
            ->assertJsonPath('data.candidates.0.feed_url', 'https://example.com/talk.xml')
            ->assertJsonPath('data.candidates.0.episode_count', 40)
            ->assertJsonMissingPath('data.candidates.1');

        $this->assertCount(1, $response->json('data.candidates'));
        $this->assertDatabaseCount('shows', 1);
    }

    public function test_subscribe_persists_once_and_queues_hydration(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        config()->set('services.podcast_index.enabled', false);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/fresh.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>Fresh Show</title>
                    <itunes:author>Ada</itunes:author>
                    <itunes:image href="https://cdn.example.com/fresh.jpg"/>
                    <item><title>Later</title></item>
                  </channel>
                </rss>
                XML, 200),
        ]);

        $user = User::factory()->create();
        $payload = [
            'source' => 'apple',
            'external_id' => '55',
            'feed_url' => 'https://example.com/fresh.xml',
            'title' => 'Fresh Show',
            'author' => 'Ada',
        ];

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/directory/subscribe', $payload)
            ->assertOk()
            ->assertJsonPath('data.already_in_catalog', false);

        $showId = $first->json('data.show_id');
        $this->assertDatabaseCount('shows', 1);
        $this->assertDatabaseHas('shows', ['id' => $showId, 'rss_url' => 'https://example.com/fresh.xml', 'title' => 'Fresh Show']);
        $this->assertDatabaseHas('show_external_ids', ['show_id' => $showId, 'provider' => 'apple', 'external_id' => '55']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $showId, 'state' => 'pending']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/directory/subscribe', [
                'source' => 'podcast_index',
                'external_id' => '9',
                'feed_url' => 'http://example.com/fresh.xml/',
                'title' => 'Fresh Show',
            ])
            ->assertOk()
            ->assertJsonPath('data.show_id', $showId)
            ->assertJsonPath('data.already_in_catalog', true);

        $this->assertDatabaseCount('shows', 1);
        $this->assertDatabaseHas('show_external_ids', ['show_id' => $showId, 'provider' => 'podcast_index', 'external_id' => '9']);
        Bus::assertDispatchedAfterResponseTimes(HydrateRssFeed::class, 1);
    }

    public function test_dead_feed_and_private_host_are_refused(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/gone.xml' => Http::response('Not Found', 404),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/directory/search?q=https://example.com/gone.xml')
            ->assertOk()
            ->assertJsonPath('data.candidates', [])
            ->assertJsonPath('data.unavailable_message', 'This podcast feed is unavailable, so it can’t be added.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/directory/subscribe', [
                'source' => 'rss',
                'feed_url' => 'https://example.com/gone.xml',
                'title' => 'Gone Show',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEED_UNAVAILABLE');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/directory/subscribe', [
                'source' => 'rss',
                'feed_url' => 'http://127.0.0.1/secret.xml',
                'title' => 'Local',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEED_UNAVAILABLE');

        $this->assertDatabaseCount('shows', 0);
        Bus::assertDispatchedAfterResponseTimes(HydrateRssFeed::class, 0);
    }

    public function test_pasted_rss_url_search_adds_the_show_to_the_catalog(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        config()->set('services.podcast_index.enabled', false);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/pasted.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0">
                  <channel>
                    <title>Pasted Show</title>
                    <item><title>One</title></item>
                  </channel>
                </rss>
                XML, 200),
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/search?q=https://example.com/pasted.xml&preview=1&type=show')
            ->assertOk()
            ->assertJsonPath('data.shows.0.title', 'Pasted Show');

        $this->assertDatabaseHas('shows', [
            'title' => 'Pasted Show',
            'rss_url' => 'https://example.com/pasted.xml',
        ]);
        Bus::assertDispatchedAfterResponse(HydrateRssFeed::class);
    }

    public function test_directory_search_rate_limit_stops_a_second_upstream_lookup(): void
    {
        config()->set('services.apple_podcasts.enabled', true);
        config()->set('services.podcast_index.enabled', false);
        config()->set('services.directory.apple_per_minute', 1);
        Http::preventStrayRequests();
        Http::fake([
            'https://itunes.apple.com/search*' => Http::response(['results' => []]),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/directory/search?q=alpha%20show')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/directory/search?q=beta%20show')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
