<?php

namespace Tests\Feature\Api;

use App\Actions\Catalog\BuildHomeFeed;
use App\Jobs\MaterializeHomeFeed;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DiscoveryAndHomeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_home_feed_is_display_ready_and_isolated_to_the_listener(): void
    {
        Cache::flush();
        $listener = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/home.xml', 'title' => 'Home Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'home-one', 'title' => 'Resume Me', 'audio_url' => 'https://example.com/home.mp3', 'published_at' => now()]);
        DB::table('follows')->insert(['user_id' => $listener->id, 'show_id' => $show->id, 'notifications_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('playback_progress')->insert(['user_id' => $listener->id, 'episode_id' => $episode->id, 'position_seconds' => 45, 'completed' => false, 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('playback_progress')->insert(['user_id' => $other->id, 'episode_id' => $episode->id, 'position_seconds' => 90, 'completed' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($listener, 'sanctum')->getJson('/api/v1/home/feed')->assertOk()->assertJsonPath('data.schema_version', 2)->assertJsonPath('data.rails.2.key', 'continue_listening')->assertJsonPath('data.rails.2.items.0.position_seconds', 45)->assertJsonPath('data.rails.7.items.0.title', 'Resume Me');
    }

    public function test_browse_related_reviews_and_notification_controls_are_consistent(): void
    {
        Cache::flush();
        $listener = User::factory()->create(['name' => 'Reviewer']);
        $show = Show::create(['rss_url' => 'https://example.com/main.xml', 'title' => 'Main Show']);
        $related = Show::create(['rss_url' => 'https://example.com/related.xml', 'title' => 'Related Show']);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Technology', 'slug' => 'technology', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_show')->insert([['category_id' => $categoryId, 'show_id' => $show->id], ['category_id' => $categoryId, 'show_id' => $related->id]]);

        $client = $this->actingAs($listener, 'sanctum');
        $client->getJson('/api/v1/browse/categories')->assertOk()->assertJsonPath('data.0.slug', 'technology')->assertJsonPath('data.0.name', 'Technology');
        $client->getJson('/api/v1/browse/technology')->assertOk()->assertJsonCount(2, 'data.shows')->assertJsonPath('data.category.name', 'Technology')->assertJsonMissingPath('data.shows.0.rss_url');
        $client->getJson("/api/v1/shows/{$show->id}/related")->assertOk()->assertJsonPath('data.0.id', $related->id);
        $client->putJson("/api/v1/shows/{$show->id}/notifications", ['enabled' => false])->assertConflict()->assertJsonPath('error.code', 'NOT_FOLLOWING');
        $client->postJson("/api/v1/shows/{$show->id}/follow");
        $client->putJson("/api/v1/shows/{$show->id}/notifications", ['enabled' => false])->assertOk()->assertJsonPath('data.notifications_enabled', false);
        $client->postJson("/api/v1/shows/{$show->id}/reviews", ['rating' => 4, 'body' => 'Thoughtful and useful.'])->assertCreated();
        $client->getJson("/api/v1/shows/{$show->id}/reviews")->assertOk()->assertJsonPath('data.0.reviewer_name', 'Reviewer')->assertJsonPath('data.0.rating', 4);
    }

    public function test_search_history_deduplicates_and_voice_search_uses_the_same_catalog_contract(): void
    {
        config()->set('services.podcast_index.enabled', false);
        $listener = User::factory()->create();
        Show::create(['rss_url' => 'https://example.com/voice.xml', 'title' => 'Voice Technology']);
        $client = $this->actingAs($listener, 'sanctum');

        $client->getJson('/api/v1/search?q=zzz&preview=1')->assertOk()->assertJsonPath('data.query', 'zzz')->assertJsonPath('data.shows', [])->assertJsonPath('data.episodes', [])->assertJsonPath('data.playlists', []);
        $this->assertSame(0, DB::table('search_history')->where('user_id', $listener->id)->count());
        $client->getJson('/api/v1/search?q=Technology')->assertOk()->assertJsonMissingPath('data.shows.0.rss_url')->assertJsonPath('data.query', 'Technology')->assertJsonPath('data.shows.0.title', 'Voice Technology')->assertJsonPath('data.episodes', [])->assertJsonPath('data.playlists', []);
        $client->postJson('/api/v1/search/voice', ['transcript' => 'Technology'])->assertOk()->assertJsonPath('data.shows.0.title', 'Voice Technology');
        $client->getJson('/api/v1/search/recent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.query', 'Technology');
        $this->assertSame(1, DB::table('search_history')->where('user_id', $listener->id)->count());
        $recentId = $client->getJson('/api/v1/search/recent')->json('data.0.id');
        $client->deleteJson("/api/v1/search/recent/{$recentId}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertSame(0, DB::table('search_history')->where('user_id', $listener->id)->count());
        $client->getJson('/api/v1/search?q=Technology')->assertOk();
        $client->deleteJson('/api/v1/search/recent')->assertOk();
        $this->assertDatabaseMissing('search_history', ['user_id' => $listener->id]);
    }

    public function test_editorial_playlists_and_charts_are_bounded_public_rails(): void
    {
        Cache::flush();
        $listener = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/chart.xml', 'title' => 'Chart Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'chart-one', 'title' => 'Chart Episode', 'audio_url' => 'https://example.com/chart.mp3']);
        $playlistId = (string) Str::ulid();
        DB::table('editorial_playlists')->insert(['id' => $playlistId, 'title' => 'Editors Choice', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('editorial_playlist_items')->insert(['id' => (string) Str::ulid(), 'editorial_playlist_id' => $playlistId, 'episode_id' => $episode->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $client = $this->actingAs($listener, 'sanctum');
        $client->getJson('/api/v1/home/playlists')->assertOk()->assertJsonPath('data.editorial.0.items.0.title', 'Chart Episode');
        $client->getJson('/api/v1/home/charts')->assertOk()->assertJsonPath('data.podcasts.0.title', 'Chart Show');
        $client->getJson('/api/v1/home/discover')->assertOk()->assertJsonStructure(['data' => ['editors_picks', 'trending', 'new_episodes', 'categories']]);
    }

    public function test_materialized_home_snapshot_is_served_and_playback_mutation_invalidates_it(): void
    {
        Cache::flush();
        $listener = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/materialized.xml', 'title' => 'Materialized Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'materialized', 'title' => 'Materialized Episode', 'audio_url' => 'https://example.com/materialized.mp3']);
        (new MaterializeHomeFeed($listener->id))->handle(app(BuildHomeFeed::class));

        $client = $this->actingAs($listener, 'sanctum');
        $client->getJson('/api/v1/home/feed')->assertOk()->assertJsonPath('meta.freshness', 'materialized')->assertJsonPath('meta.snapshot_version', 1);
        $client->putJson("/api/v1/playback/{$episode->id}", ['position_seconds' => 30, 'completed' => false, 'version' => 0])->assertOk();
        $this->assertDatabaseMissing('home_feed_snapshots', ['user_id' => $listener->id]);
    }

    public function test_try_something_new_is_personal_and_outside_the_listeners_categories(): void
    {
        Cache::flush();
        config()->set('services.podcast_index.enabled', false);

        app(\App\Actions\Catalog\SyncPodcastIndexCategories::class)->handle(invalidateCache: false);

        $comedyListener = User::factory()->create();
        $scienceListener = User::factory()->create();
        $comedyShow = Show::create(['rss_url' => 'https://example.com/comedy.xml', 'title' => 'Night Laughs']);
        $scienceShow = Show::create(['rss_url' => 'https://example.com/science.xml', 'title' => 'Lab Notes']);
        $comedyEpisode = Episode::create(['show_id' => $comedyShow->id, 'guid' => 'comedy-ep', 'title' => 'Set One', 'audio_url' => 'https://example.com/comedy.mp3', 'published_at' => now()]);
        $scienceEpisode = Episode::create(['show_id' => $scienceShow->id, 'guid' => 'science-ep', 'title' => 'Atoms', 'audio_url' => 'https://example.com/science.mp3', 'published_at' => now()]);

        $comedyId = DB::table('categories')->where('slug', 'comedy')->value('id');
        $scienceId = DB::table('categories')->where('slug', 'science')->value('id');
        $this->assertNotNull($comedyId);
        $this->assertNotNull($scienceId);

        DB::table('category_show')->insert([
            ['category_id' => $comedyId, 'show_id' => $comedyShow->id],
            ['category_id' => $scienceId, 'show_id' => $scienceShow->id],
        ]);
        DB::table('playback_progress')->insert([
            ['user_id' => $comedyListener->id, 'episode_id' => $comedyEpisode->id, 'position_seconds' => 40, 'completed' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $scienceListener->id, 'episode_id' => $scienceEpisode->id, 'position_seconds' => 40, 'completed' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $comedyItems = collect($this->actingAs($comedyListener, 'sanctum')->getJson('/api/v1/home/feed')->assertOk()->json('data.rails'))
            ->firstWhere('key', 'try_something_new')['items'];
        $scienceItems = collect($this->actingAs($scienceListener, 'sanctum')->getJson('/api/v1/home/feed')->assertOk()->json('data.rails'))
            ->firstWhere('key', 'try_something_new')['items'];

        $comedySlugs = collect($comedyItems)->pluck('slug')->all();
        $scienceSlugs = collect($scienceItems)->pluck('slug')->all();

        $this->assertNotEmpty($comedySlugs);
        $this->assertNotEmpty($scienceSlugs);
        $this->assertGreaterThanOrEqual(8, count($comedySlugs));
        $this->assertNotContains('comedy', $comedySlugs);
        $this->assertNotContains('improv', $comedySlugs);
        $this->assertNotContains('science', $scienceSlugs);
        $this->assertNotSame($comedySlugs, $scienceSlugs);
    }
}
