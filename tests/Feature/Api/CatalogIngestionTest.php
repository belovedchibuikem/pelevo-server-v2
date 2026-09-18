<?php

namespace Tests\Feature\Api;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Actions\Catalog\PersistDiscoveredShow;
use App\Integrations\Rss\RssFeedFetcher;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CatalogIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovered_show_is_persisted_idempotently_without_eager_hydration(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $feed = ['id' => 101, 'url' => 'https://example.com/show.xml', 'title' => 'A New Voice', 'description' => '<b>Clean</b>'];

        $first = app(PersistDiscoveredShow::class)->handle($feed);
        $second = app(PersistDiscoveredShow::class)->handle($feed);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('shows', 1);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $first?->id, 'state' => 'pending']);
        $this->assertDatabaseHas('show_external_ids', ['show_id' => $first?->id, 'provider' => 'podcast_index', 'external_id' => '101']);
        Bus::assertNotDispatched(HydrateRssFeed::class);
    }

    public function test_rss_hydration_records_the_run_and_normalizes_episode_duration(): void
    {
        Event::fake();
        $show = Show::create(['rss_url' => 'https://example.com/show.xml', 'title' => 'RSS Show']);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['https://example.com/show.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
              <channel>
                <title>RSS Show</title>
                <item>
                  <guid>episode-1</guid>
                  <title>First episode</title>
                  <description>Useful audio</description>
                  <enclosure url="https://cdn.example.com/episode-1.mp3" type="audio/mpeg"/>
                  <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                  <itunes:duration>01:02:03</itunes:duration>
                </item>
              </channel>
            </rss>
            XML, 200, ['ETag' => '"feed-v1"'])]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'episode-1', 'duration_seconds' => 3723]);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy', 'consecutive_failures' => 0]);
        $this->assertDatabaseHas('feed_sync_runs', ['show_id' => $show->id, 'state' => 'completed', 'http_status' => 200, 'new_episode_count' => 1]);
    }

    public function test_rss_hydration_persists_permanent_redirects_and_itunes_new_feed_url(): void
    {
        Event::fake();
        $show = Show::create([
            'rss_url' => 'https://example.com/legacy.xml',
            'title' => 'RSS Show',
            'description' => 'Old description',
            'artwork_url' => 'https://cdn.example.com/old.jpg',
        ]);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/legacy.xml' => Http::response('', 301, ['Location' => 'https://example.com/moved.xml']),
            'https://example.com/moved.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>RSS Show</title>
                    <itunes:new-feed-url>https://example.com/canonical.xml</itunes:new-feed-url>
                    <item>
                      <guid>bridge</guid>
                      <title>Bridge</title>
                      <enclosure url="https://cdn.example.com/bridge.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                    </item>
                  </channel>
                </rss>
                XML, 200),
            'https://example.com/canonical.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>Relocated Show</title>
                    <itunes:title>Relocated Show</itunes:title>
                    <description>Fresh show notes</description>
                    <itunes:image href="https://cdn.example.com/cover.jpg"/>
                    <item>
                      <guid>episode-1</guid>
                      <title>First episode</title>
                      <enclosure url="https://cdn.example.com/episode-1.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                    </item>
                  </channel>
                </rss>
                XML, 200, ['ETag' => '"canonical-v1"']),
        ]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'rss_url' => 'https://example.com/canonical.xml',
            'title' => 'Relocated Show',
            'description' => 'Fresh show notes',
            'artwork_url' => 'https://cdn.example.com/cover.jpg',
        ]);
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'episode-1']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'rss_url', 'new_value' => 'https://example.com/canonical.xml']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'title', 'old_value' => 'RSS Show', 'new_value' => 'Relocated Show']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'artwork_url', 'new_value' => 'https://cdn.example.com/cover.jpg']);
    }

    public function test_rss_hydration_does_not_persist_temporary_redirects(): void
    {
        Event::fake();
        $show = Show::create(['rss_url' => 'https://example.com/show.xml', 'title' => 'RSS Show']);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.com/show.xml' => Http::response('', 302, ['Location' => 'https://example.com/tmp.xml']),
            'https://example.com/tmp.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0">
                  <channel>
                    <title>RSS Show</title>
                    <item>
                      <guid>tmp-1</guid>
                      <title>Temporary hop</title>
                      <enclosure url="https://cdn.example.com/tmp-1.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                    </item>
                  </channel>
                </rss>
                XML, 200),
        ]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('shows', ['id' => $show->id, 'rss_url' => 'https://example.com/show.xml']);
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'tmp-1']);
    }

    public function test_rss_hydration_refreshes_channel_artwork_from_rss_image(): void
    {
        Event::fake();
        $show = Show::create([
            'rss_url' => 'https://example.com/show.xml',
            'title' => 'RSS Show',
            'description' => 'Stale copy',
            'artwork_url' => 'https://cdn.example.com/stale.png',
        ]);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['https://example.com/show.xml' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
              <channel>
                <title>RSS Show</title>
                <description>Updated copy</description>
                <image><url>https://cdn.example.com/rss-image.png</url></image>
                <item>
                  <guid>episode-1</guid>
                  <title>First episode</title>
                  <enclosure url="https://cdn.example.com/episode-1.mp3" type="audio/mpeg"/>
                  <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                </item>
              </channel>
            </rss>
            XML, 200)]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'description' => 'Updated copy',
            'artwork_url' => 'https://cdn.example.com/rss-image.png',
        ]);
    }

    public function test_rss_hydration_tracks_url_title_and_artwork_after_301_even_when_etag_would_304(): void
    {
        Event::fake();
        $show = Show::create([
            'rss_url' => 'https://example.com/legacy.xml',
            'title' => 'Old Host Title',
            'artwork_url' => 'https://cdn.example.com/old.jpg',
        ]);
        $show->feedState()->create([
            'state' => 'healthy',
            'etag' => '"old-host-etag"',
            'last_modified' => 'Wed, 01 Jan 2020 00:00:00 GMT',
            'next_poll_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() === 'https://example.com/legacy.xml') {
                return Http::response('', 301, ['Location' => 'https://example.com/moved.xml']);
            }
            if ($request->hasHeader('If-None-Match') || $request->hasHeader('If-Modified-Since')) {
                return Http::response('', 304);
            }

            return Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>New Host Title</title>
                    <itunes:image href="https://cdn.example.com/new-cover.jpg"/>
                    <item>
                      <guid>moved-1</guid>
                      <title>Still here</title>
                      <enclosure url="https://cdn.example.com/moved-1.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                    </item>
                  </channel>
                </rss>
                XML, 200);
        });

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'rss_url' => 'https://example.com/moved.xml',
            'title' => 'New Host Title',
            'artwork_url' => 'https://cdn.example.com/new-cover.jpg',
        ]);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'rss_url', 'new_value' => 'https://example.com/moved.xml']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'title', 'new_value' => 'New Host Title']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'artwork_url', 'new_value' => 'https://cdn.example.com/new-cover.jpg']);
    }

    public function test_rss_hydration_refreshes_channel_metadata_when_304_and_channel_sync_is_due(): void
    {
        Event::fake();
        $show = Show::create([
            'rss_url' => 'https://example.com/show.xml',
            'title' => 'Stale Title',
            'artwork_url' => 'https://cdn.example.com/stale.png',
        ]);
        $show->feedState()->create([
            'state' => 'healthy',
            'etag' => '"unchanged"',
            'channel_synced_at' => now()->subDays(2),
            'next_poll_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->hasHeader('If-None-Match')) {
                return Http::response('', 304);
            }

            return Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="https://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>Refreshed Title</title>
                    <itunes:image href="https://cdn.example.com/refreshed.png"/>
                    <itunes:new-feed-url>https://example.com/canonical.xml</itunes:new-feed-url>
                    <item>
                      <guid>refresh-1</guid>
                      <title>Same episode</title>
                      <enclosure url="https://cdn.example.com/refresh-1.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                    </item>
                  </channel>
                </rss>
                XML, 200);
        });

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'rss_url' => 'https://example.com/canonical.xml',
            'title' => 'Refreshed Title',
            'artwork_url' => 'https://cdn.example.com/refreshed.png',
        ]);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'title', 'new_value' => 'Refreshed Title']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $show->id, 'field' => 'rss_url', 'new_value' => 'https://example.com/canonical.xml']);
    }

    public function test_poll_command_backfills_missing_feed_state_and_dispatches_due_show(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $show = Show::create(['rss_url' => 'https://example.com/missing-state.xml', 'title' => 'Missing State']);

        $this->artisan('catalog:poll-feeds')->assertSuccessful();

        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'pending']);
        Bus::assertDispatched(HydrateRssFeed::class, fn (HydrateRssFeed $job): bool => $job->showId === $show->id);
    }

    public function test_rss_hydration_accepts_long_percent_encoded_guids(): void
    {
        Event::fake();
        $guid = 'https://yitzchoklowy.com/uncategorized/%d7%a1%d7%93%d7%a8-%d7%94%d7%aa%d7%a4%d7%9c%d7%95%d7%aa-%d7%97-%d7%91%d7%a8%d7%9b%d7%aa-%d7%94%d7%9e%d7%96%d7%95%d7%9f-%d7%95%d7%94%d7%a4%d7%98%d7%a8%d7%94/';
        $show = Show::create(['rss_url' => 'https://example.com/hebrew.xml', 'title' => 'Hebrew Show']);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['https://example.com/hebrew.xml' => Http::response(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Hebrew Show</title>
                <item>
                  <guid>{$guid}</guid>
                  <title>סדר התפלות ח – ברכת המזון והפטרה</title>
                  <enclosure url="https://cdn.example.com/ep.mp3" type="audio/mpeg"/>
                  <pubDate>Wed, 09 Sep 2026 12:00:00 GMT</pubDate>
                </item>
              </channel>
            </rss>
            XML, 200)]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertGreaterThan(191, strlen($guid));
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => $guid]);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
    }

    public function test_missing_rss_feed_is_marked_failed_without_retry_throw(): void
    {
        Event::fake();
        $show = Show::create(['rss_url' => 'https://example.com/gone.xml', 'title' => 'Gone Show']);
        $show->feedState()->create(['state' => 'pending', 'next_poll_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['https://example.com/gone.xml' => Http::response('Not Found', 404)]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'failed']);
        $this->assertDatabaseHas('feed_sync_runs', ['show_id' => $show->id, 'state' => 'failed', 'error' => 'RSS feed returned HTTP 404.']);
    }
}
