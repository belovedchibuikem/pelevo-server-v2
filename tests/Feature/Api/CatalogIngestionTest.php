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

    public function test_poll_command_backfills_missing_feed_state_and_dispatches_due_show(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        $show = Show::create(['rss_url' => 'https://example.com/missing-state.xml', 'title' => 'Missing State']);

        $this->artisan('catalog:poll-feeds')->assertSuccessful();

        $this->assertDatabaseHas('show_feed_states', ['show_id' => $show->id, 'state' => 'pending']);
        Bus::assertDispatched(HydrateRssFeed::class, fn (HydrateRssFeed $job): bool => $job->showId === $show->id);
    }
}
