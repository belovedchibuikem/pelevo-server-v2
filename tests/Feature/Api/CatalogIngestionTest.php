<?php

namespace Tests\Feature\Api;

use App\Actions\Catalog\InvalidateDiscoveryCache;
use App\Actions\Catalog\PersistDiscoveredShow;
use App\Events\NewEpisodePublished;
use App\Integrations\Rss\FeedUrlGuard;
use App\Integrations\Rss\RssFeedFetcher;
use App\Jobs\HydrateRssFeed;
use App\Models\Show;
use App\Models\User;
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

    public function test_discovered_show_updates_existing_url_title_and_artwork(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $first = app(PersistDiscoveredShow::class)->handle([
            'id' => 101,
            'url' => 'https://example.com/legacy.xml',
            'title' => 'Talk Tech Nigeria',
            'description' => 'Old copy',
            'image' => 'https://cdn.example.com/old.jpg',
        ]);

        $second = app(PersistDiscoveredShow::class)->handle([
            'id' => 101,
            'url' => 'https://anchor.fm/s/a2f0864/podcast/rss',
            'originalUrl' => 'https://anchor.fm/s/a2f0864/podcast/rss',
            'title' => 'Talk Tech Africa | TTAF',
            'description' => 'Continent-wide mission',
            'artwork' => 'https://cdn.example.com/ttaf.jpg',
        ]);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('shows', 1);
        $this->assertDatabaseHas('shows', [
            'id' => $first?->id,
            'title' => 'Talk Tech Africa | TTAF',
            'rss_url' => 'https://anchor.fm/s/a2f0864/podcast/rss',
            'artwork_url' => 'https://cdn.example.com/ttaf.jpg',
            'description' => 'Continent-wide mission',
        ]);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $first?->id, 'field' => 'title', 'new_value' => 'Talk Tech Africa | TTAF']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $first?->id, 'field' => 'rss_url', 'new_value' => 'https://anchor.fm/s/a2f0864/podcast/rss']);
        $this->assertDatabaseHas('show_metadata_changes', ['show_id' => $first?->id, 'field' => 'artwork_url', 'new_value' => 'https://cdn.example.com/ttaf.jpg']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $first?->id, 'state' => 'pending']);
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

    public function test_rss_hydration_refreshes_dead_feed_url_from_podcast_index(): void
    {
        Event::fake();
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');

        $show = Show::create([
            'rss_url' => 'https://example.com/gone.xml',
            'title' => 'Talk Tech Nigeria',
            'artwork_url' => 'https://cdn.example.com/old.jpg',
        ]);
        $show->feedState()->create(['state' => 'failed', 'consecutive_failures' => 3, 'next_poll_at' => now()]);
        \Illuminate\Support\Facades\DB::table('show_external_ids')->insert([
            'show_id' => $show->id,
            'provider' => 'podcast_index',
            'external_id' => '75075',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.podcastindex.org/api/1.0/episodes/byfeedid*' => Http::response(['items' => []]),
            'api.podcastindex.org/api/1.0/podcasts/byfeedid*' => Http::response([
                'status' => 'true',
                'feed' => [
                    'id' => 75075,
                    'url' => 'https://example.com/ttaf.xml',
                    'title' => 'Talk Tech Africa | TTAF',
                    'description' => 'Continent-wide mission',
                    'artwork' => 'https://cdn.example.com/ttaf.jpg',
                    'image' => 'https://cdn.example.com/ttaf.jpg',
                ],
            ]),
            'https://example.com/gone.xml' => Http::response('Gone', 404),
            'https://example.com/ttaf.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                  <channel>
                    <title>Talk Tech Africa | TTAF</title>
                    <itunes:image href="https://cdn.example.com/ttaf.jpg"/>
                    <item>
                      <guid>ttaf-1</guid>
                      <title>New episode</title>
                      <enclosure url="https://cdn.example.com/ttaf-1.mp3" type="audio/mpeg"/>
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

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'title' => 'Talk Tech Africa | TTAF',
            'rss_url' => 'https://example.com/ttaf.xml',
            'artwork_url' => 'https://cdn.example.com/ttaf.jpg',
        ]);
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'ttaf-1']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
    }

    public function test_missing_rss_feed_is_marked_failed_without_retry_throw(): void
    {
        Event::fake();
        config()->set('services.podcast_index.enabled', false);
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

    public function test_unresolved_host_refreshes_feed_url_from_podcast_index(): void
    {
        Event::fake();
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return str_contains($host, 'vanished-host.example') ? [] : ['93.184.216.34'];
            }
        });

        $show = Show::create([
            'rss_url' => 'https://vanished-host.example/feed.xml',
            'title' => 'Moved Show',
        ]);
        $show->feedState()->create(['state' => 'failed', 'consecutive_failures' => 4, 'next_poll_at' => now(), 'last_error' => 'Feed host could not be resolved.']);
        \Illuminate\Support\Facades\DB::table('show_external_ids')->insert([
            'show_id' => $show->id,
            'provider' => 'podcast_index',
            'external_id' => '88088',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.podcastindex.org/api/1.0/episodes/byfeedid*' => Http::response(['items' => []]),
            'api.podcastindex.org/api/1.0/podcasts/byfeedid*' => Http::response([
                'status' => 'true',
                'feed' => [
                    'id' => 88088,
                    'url' => 'https://example.com/relocated.xml',
                    'title' => 'Moved Show',
                    'description' => 'Now on a new host',
                    'artwork' => 'https://cdn.example.com/cover.jpg',
                    'image' => 'https://cdn.example.com/cover.jpg',
                ],
            ]),
            'https://example.com/relocated.xml' => Http::response(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0">
                  <channel>
                    <title>Moved Show</title>
                    <item>
                      <guid>relocated-1</guid>
                      <title>Episode after the move</title>
                      <enclosure url="https://cdn.example.com/relocated-1.mp3" type="audio/mpeg"/>
                      <pubDate>Wed, 16 Sep 2026 12:00:00 GMT</pubDate>
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

        $this->assertDatabaseHas('shows', [
            'id' => $show->id,
            'rss_url' => 'https://example.com/relocated.xml',
        ]);
        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'relocated-1']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
        Event::assertDispatched(NewEpisodePublished::class);
    }

    public function test_unresolved_host_still_notifies_podcast_index_seeded_episodes(): void
    {
        Event::fake([NewEpisodePublished::class]);
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        $this->app->instance(FeedUrlGuard::class, new class extends FeedUrlGuard
        {
            protected function resolve(string $host): array
            {
                return [];
            }
        });

        $show = Show::create([
            'rss_url' => 'https://vanished-host.example/feed.xml',
            'title' => 'Still Dead Show',
        ]);
        $show->feedState()->create(['state' => 'failed', 'consecutive_failures' => 1, 'next_poll_at' => now()]);
        \Illuminate\Support\Facades\DB::table('show_external_ids')->insert([
            'show_id' => $show->id,
            'provider' => 'podcast_index',
            'external_id' => '99099',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.podcastindex.org/api/1.0/episodes/byfeedid*' => Http::response([
                'items' => [[
                    'id' => 44,
                    'guid' => 'pi-seeded-ep',
                    'title' => 'Already on other apps',
                    'enclosureUrl' => 'https://cdn.example.com/seeded.mp3',
                    'datePublished' => 1727000000,
                    'duration' => 120,
                ]],
            ]),
            'api.podcastindex.org/api/1.0/podcasts/byfeedid*' => Http::response([
                'status' => 'true',
                'feed' => [
                    'id' => 99099,
                    'url' => 'https://vanished-host.example/feed.xml',
                    'title' => 'Still Dead Show',
                ],
            ]),
            'api.podcastindex.org/api/1.0/podcasts/byfeedurl*' => Http::response([
                'status' => 'true',
                'feed' => [
                    'id' => 99099,
                    'url' => 'https://vanished-host.example/feed.xml',
                    'title' => 'Still Dead Show',
                ],
            ]),
        ]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'pi-seeded-ep']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
        $this->assertTrue($show->feedState()->first()->next_poll_at->lte(now()->addHours(2)));
        Event::assertDispatched(NewEpisodePublished::class);
    }

    public function test_poll_command_requeues_unresolved_host_feeds_stuck_in_backoff(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $show = Show::create(['rss_url' => 'https://vanished-host.example/feed.xml', 'title' => 'Dead Host Show']);
        $show->feedState()->create([
            'state' => 'stale',
            'consecutive_failures' => 8,
            'last_error' => 'Feed host could not be resolved.',
            'next_poll_at' => now()->addWeek(),
            'last_failure_at' => now()->subDays(2),
        ]);

        $this->artisan('catalog:poll-feeds')->assertSuccessful();

        $this->assertTrue($show->fresh()->feedState->next_poll_at->lte(now()->addMinute()));
        Bus::assertDispatched(HydrateRssFeed::class, fn (HydrateRssFeed $job): bool => $job->showId === $show->id);
    }

    public function test_catalog_status_prints_failed_shows(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://vanished-host.example/feed.xml', 'title' => 'Dead Host Show']);
        $show->feedState()->create([
            'state' => 'failed',
            'consecutive_failures' => 3,
            'last_error' => 'Feed host could not be resolved.',
            'next_poll_at' => now()->addWeek(),
        ]);
        \Illuminate\Support\Facades\DB::table('follows')->insert([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'notifications_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('pelevo:catalog-status')
            ->expectsOutputToContain('Dead Host Show')
            ->expectsOutputToContain('could not be resolved');
    }

    public function test_oversized_rss_still_hydrates_newest_complete_items(): void
    {
        Event::fake();
        config()->set('services.podcast_index.enabled', false);
        $head = '<?xml version="1.0"?><rss version="2.0"><channel><title>The Daily</title><item><guid>newest</guid><title>Newest episode</title><enclosure url="https://cdn.example.com/newest.mp3" type="audio/mpeg"/></item>';
        $xml = $head.'<item><guid>old</guid><title>'.str_repeat('archive ', 4000).'</title><enclosure url="https://cdn.example.com/old.mp3" type="audio/mpeg"/></item></channel></rss>';
        config()->set('rss.max_bytes', strlen($head) + 80);
        $show = Show::create(['rss_url' => 'https://example.com/the-daily.xml', 'title' => 'The Daily']);
        $show->feedState()->create(['state' => 'stale', 'consecutive_failures' => 5, 'next_poll_at' => now(), 'last_error' => 'RSS document exceeds the configured limit.']);
        Http::preventStrayRequests();
        Http::fake(['https://example.com/the-daily.xml' => Http::response($xml, 200)]);

        (new HydrateRssFeed($show->id))->handle(
            app(RssFeedFetcher::class),
            app(InvalidateDiscoveryCache::class),
            app(\App\Integrations\PodcastIndex\PodcastIndexClient::class),
        );

        $this->assertDatabaseHas('episodes', ['show_id' => $show->id, 'guid' => 'newest', 'title' => 'Newest episode']);
        $this->assertDatabaseMissing('episodes', ['show_id' => $show->id, 'guid' => 'old']);
        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'healthy']);
    }

    public function test_poll_command_requeues_followed_show_stuck_in_week_backoff(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://feeds.simplecast.com/54nAGcIl', 'title' => 'The Daily']);
        $show->feedState()->create([
            'state' => 'stale',
            'consecutive_failures' => 5,
            'last_error' => 'RSS document exceeds the configured limit.',
            'next_poll_at' => now()->addWeek(),
        ]);
        \Illuminate\Support\Facades\DB::table('follows')->insert([
            'user_id' => $user->id,
            'show_id' => $show->id,
            'notifications_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('catalog:poll-feeds')->assertSuccessful();

        $this->assertTrue($show->fresh()->feedState->next_poll_at->lte(now()->addMinute()));
        Bus::assertDispatched(HydrateRssFeed::class, fn (HydrateRssFeed $job): bool => $job->showId === $show->id);
    }

    public function test_poll_command_releases_stuck_running_feeds(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $show = Show::create(['rss_url' => 'https://example.com/stuck.xml', 'title' => 'Stuck Running']);
        $show->feedState()->create(['state' => 'running', 'consecutive_failures' => 0, 'next_poll_at' => now()]);
        \Illuminate\Support\Facades\DB::table('show_feed_states')->where('show_id', $show->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('catalog:poll-feeds')->assertSuccessful();

        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'pending']);
        Bus::assertDispatched(HydrateRssFeed::class, fn (HydrateRssFeed $job): bool => $job->showId === $show->id);
    }
}
