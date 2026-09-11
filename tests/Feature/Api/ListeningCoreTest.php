<?php

namespace Tests\Feature\Api;

use App\Jobs\HydrateRssFeed;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ListeningCoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_follow_rate_and_read_show_episodes(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Useful Show']);
        Episode::create(['show_id' => $show->id, 'guid' => 'episode-one', 'title' => 'Episode One', 'audio_url' => 'https://example.com/one.mp3', 'published_at' => now()]);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/shows/{$show->id}/follow")->assertOk()->assertJsonPath('data.following', true);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/shows/{$show->id}/rating", ['rating' => 5])->assertOk()->assertJsonPath('data.rating', 5);
        $detail = $this->actingAs($user, 'sanctum')->getJson("/api/v1/shows/{$show->id}")->assertOk()
            ->assertJsonPath('data.id', $show->id)->assertJsonPath('data.title', 'Useful Show')
            ->assertJsonPath('data.following', true)->assertJsonPath('data.follower_count', 1)
            ->assertJsonPath('data.rating_average', '5.0')->assertJsonPath('data.rating_count', 1)
            ->assertJsonMissing(['rss_url']);
        $this->assertSame(['id', 'title', 'author', 'artwork_url', 'description', 'language', 'country_code', 'explicit', 'episodes_count', 'follower_count', 'rating_average', 'rating_count', 'following', 'notifications_enabled', 'claimed_by_viewer', 'categories'], array_keys($detail->json('data')));
        $listed = $this->actingAs($user, 'sanctum')->getJson("/api/v1/shows/{$show->id}/episodes")->assertOk()->assertJsonPath('data.0.title', 'Episode One');
        $this->assertSame(['id', 'show_id', 'title', 'description', 'artwork_url', 'duration_seconds', 'published_at', 'show_title', 'show_author'], array_keys($listed->json('data.0')));
        $this->assertArrayNotHasKey('audio_url', $listed->json('data.0'));
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/episodes/{$listed->json('data.0.id')}")->assertOk()->assertJsonPath('data.audio_url', 'https://example.com/one.mp3')->assertJsonMissing(['guid']);
    }

    public function test_playback_rejects_stale_version(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'One', 'audio_url' => 'https://example.com/one.mp3']);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/playback/{$episode->id}", ['position_seconds' => 10, 'completed' => false, 'version' => 0])->assertOk();
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/playback/{$episode->id}", ['position_seconds' => 20, 'completed' => false, 'version' => 0])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
    }

    public function test_search_imports_provider_result_and_never_exposes_raw_payload(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['api.podcastindex.org/api/1.0/search/byterm*' => Http::response(['feeds' => [['id' => 999, 'url' => 'https://example.com/provider.xml', 'title' => 'Provider Show', 'description' => '<b>Safe description</b>', 'unknown_secret_field' => 'must-not-leak']]])]);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search?q=Provider');

        $response->assertOk()->assertJsonMissingPath('data.provider_candidates')->assertJsonMissing(['id' => 999])->assertJsonPath('data.shows.0.title', 'Provider Show')->assertJsonPath('meta.freshness', 'fresh');
        $this->assertDatabaseHas('show_external_ids', ['provider' => 'podcast_index', 'external_id' => '999']);
        $this->assertDatabaseHas('show_feed_states', ['state' => 'pending']);
        Bus::assertDispatched(HydrateRssFeed::class);
        Http::assertSentCount(1);
    }

    public function test_search_returns_discovered_shows_even_when_title_does_not_like_match_query(): void
    {
        Bus::fake([HydrateRssFeed::class]);
        config()->set('services.podcast_index.enabled', true);
        config()->set('services.podcast_index.api_key', 'key');
        config()->set('services.podcast_index.api_secret', 'secret');
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['api.podcastindex.org/api/1.0/search/byterm*' => Http::response(['feeds' => [['id' => 1001, 'url' => 'https://example.com/memo.xml', 'title' => 'Closet Chronicles', 'description' => 'Style notes']]])]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/search?q='.urlencode('wardrobe memo'))
            ->assertOk()
            ->assertJsonPath('data.shows.0.title', 'Closet Chronicles')
            ->assertJsonPath('meta.freshness', 'fresh');
    }
}
