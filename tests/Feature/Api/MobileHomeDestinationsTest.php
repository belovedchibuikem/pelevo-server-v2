<?php

namespace Tests\Feature\Api;

use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileHomeDestinationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_discover_charts_and_playlists_require_authentication(): void
    {
        $this->getJson('/api/v1/home/discover')->assertUnauthorized();
        $this->getJson('/api/v1/home/charts')->assertUnauthorized();
        $this->getJson('/api/v1/home/playlists')->assertUnauthorized();
        $this->getJson('/api/v1/home/rails/quick_listen')->assertUnauthorized();
    }

    public function test_discover_exposes_editorial_trending_and_new_episodes_without_invented_rows(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.invalid/discover.xml', 'title' => 'Discover Show', 'status' => 'active']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'discover-one', 'title' => 'Fresh episode', 'audio_url' => 'https://example.invalid/a.mp3', 'duration_seconds' => 400, 'published_at' => now(), 'availability' => 'available']);
        $playlistId = (string) Str::ulid();
        DB::table('editorial_playlists')->insert(['id' => $playlistId, 'title' => 'Voices desk', 'description' => 'Editor selected', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('editorial_playlist_items')->insert(['id' => (string) Str::ulid(), 'editorial_playlist_id' => $playlistId, 'episode_id' => $episode->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/discover')->assertOk()
            ->assertJsonPath('data.editors_picks.0.id', $playlistId)
            ->assertJsonPath('data.editors_picks.0.title', 'Voices desk')
            ->assertJsonPath('data.new_episodes.0.title', 'Fresh episode')
            ->assertJsonPath('data.trending.0.id', $episode->id);
        $this->assertSame(1, count($this->actingAs($user, 'sanctum')->getJson('/api/v1/home/discover')->json('data.editors_picks')));
    }

    public function test_charts_keep_empty_music_and_audiobook_segments_until_catalog_has_them(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.invalid/chart.xml', 'title' => 'Ranked Show', 'author' => 'Ada', 'status' => 'active']);
        $music = Show::create(['rss_url' => 'https://example.invalid/music.xml', 'title' => 'Lagos Mix', 'author' => 'DJ', 'status' => 'active']);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Music', 'slug' => 'music', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_show')->insert(['category_id' => $categoryId, 'show_id' => $music->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/charts')->assertOk()
            ->assertJsonPath('data.podcasts.0.id', $show->id)
            ->assertJsonPath('data.podcasts.0.subtitle', 'Ada')
            ->assertJsonPath('data.music.0.id', $music->id)
            ->assertJsonPath('data.audiobooks', []);
    }

    public function test_playlists_more_is_account_scoped_and_includes_live_without_seeded_hosts(): void
    {
        Cache::flush();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerPlaylist = (string) Str::ulid();
        $otherPlaylist = (string) Str::ulid();
        DB::table('playlists')->insert([
            ['id' => $ownerPlaylist, 'user_id' => $owner->id, 'name' => 'Morning desk', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $otherPlaylist, 'user_id' => $other->id, 'name' => 'Hidden list', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $show = Show::create(['rss_url' => 'https://example.invalid/live.xml', 'title' => 'Live show', 'status' => 'active']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'live-ep', 'title' => 'Live ep', 'audio_url' => 'https://example.invalid/l.mp3', 'availability' => 'available']);
        DB::table('playlist_items')->insert(['id' => (string) Str::ulid(), 'playlist_id' => $ownerPlaylist, 'episode_id' => $episode->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $creator = CreatorProfile::create(['user_id' => $owner->id, 'display_name' => 'Layi']);
        DB::table('live_sessions')->insert([
            'id' => (string) Str::ulid(),
            'creator_profile_id' => $creator->id,
            'show_id' => $show->id,
            'title' => 'The Midday desk',
            'state' => 'live',
            'viewer_count' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/home/playlists')->assertOk()
            ->assertJsonPath('data.yours.0.id', $ownerPlaylist)
            ->assertJsonPath('data.yours.0.item_count', 1)
            ->assertJsonPath('data.live.0.title', 'The Midday desk')
            ->assertJsonPath('data.live.0.subtitle', 'Layi')
            ->assertJsonPath('data.live.0.viewer_count', 12);
        $this->assertSame(1, count($this->actingAs($owner, 'sanctum')->getJson('/api/v1/home/playlists')->json('data.yours')));
    }

    public function test_rail_filters_are_server_authoritative(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $nigeria = Show::create(['rss_url' => 'https://example.invalid/ng.xml', 'title' => 'NG Show', 'country_code' => 'NG', 'status' => 'active']);
        $ghana = Show::create(['rss_url' => 'https://example.invalid/gh.xml', 'title' => 'GH Show', 'country_code' => 'GH', 'status' => 'active']);
        Episode::create(['show_id' => $nigeria->id, 'guid' => 'short', 'title' => 'Short NG', 'audio_url' => 'https://example.invalid/s.mp3', 'duration_seconds' => 400, 'published_at' => now(), 'availability' => 'available']);
        Episode::create(['show_id' => $nigeria->id, 'guid' => 'long', 'title' => 'Long NG', 'audio_url' => 'https://example.invalid/l.mp3', 'duration_seconds' => 2400, 'published_at' => now(), 'availability' => 'available']);
        Episode::create(['show_id' => $ghana->id, 'guid' => 'ghana', 'title' => 'GH episode', 'audio_url' => 'https://example.invalid/g.mp3', 'duration_seconds' => 2400, 'published_at' => now(), 'availability' => 'available']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/quick_listen?min_duration=300&max_duration=600')->assertOk()
            ->assertJsonPath('data.rail.items.0.title', 'Short NG');
        $this->assertSame(['Short NG'], collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/quick_listen?min_duration=300&max_duration=600')->json('data.rail.items'))->pluck('title')->all());
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/african_voices?country=GH')->assertOk()
            ->assertJsonPath('data.rail.items.0.title', 'GH episode');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/african_voices?country=XX')->assertUnprocessable();
    }
}
