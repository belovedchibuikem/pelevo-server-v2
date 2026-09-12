<?php

namespace Tests\Feature\Api;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Integrations\PodcastIndex\PodcastIndexClient;
use App\Integrations\Rss\RssFeedFetcher;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PodcastIndexEpisodeSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydrate_seeds_podcast_index_page_before_rss(): void
    {
        Event::fake();
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        config()->set('services.podcast_index.episode_page_size', 100);

        $show = Show::create(['rss_url' => 'https://example.com/show.xml', 'title' => 'Indexed Show']);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        \Illuminate\Support\Facades\DB::table('show_external_ids')->insert([
            'show_id' => $show->id,
            'provider' => 'podcast_index',
            'external_id' => '75075',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api.podcastindex.org/api/1.0/episodes/byfeedid*' => Http::response([
                'items' => [[
                    'id' => 111,
                    'guid' => 'pi-episode-1',
                    'title' => 'Seeded from Index',
                    'description' => 'From Podcast Index',
                    'enclosureUrl' => 'https://cdn.example.com/pi-1.mp3',
                    'datePublished' => 1725000000,
                    'duration' => 600,
                ]],
            ]),
            'https://example.com/show.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>Indexed Show</title>
                    <item>
                      <guid>rss-episode-2</guid>
                      <title>From RSS</title>
                      <enclosure url="https://cdn.example.com/rss-2.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                      <itunes:duration>120</itunes:duration>
                    </item>
                  </channel>
                </rss>
                XML, 200, ['ETag' => '"feed-v1"']),
        ]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'pi-episode-1', 'title' => 'Seeded from Index']);
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'rss-episode-2', 'title' => 'From RSS']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'episodes/byfeedid')
            && str_contains($request->url(), 'max=100'));
    }
}
