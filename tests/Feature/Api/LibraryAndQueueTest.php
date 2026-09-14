<?php

namespace Tests\Feature\Api;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LibraryAndQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_playlist_updates_require_current_version_and_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $playlist = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/playlists', ['name' => 'Morning'])->assertCreated()->json('data');

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/playlists/'.$playlist['id'], ['name' => 'Morning focus', 'description' => null, 'is_public' => false, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/playlists/'.$playlist['id'], ['name' => 'Stale', 'description' => null, 'is_public' => false, 'version' => 1])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/playlists/'.$playlist['id'])->assertNotFound();
    }

    public function test_queue_replacement_is_ordered_and_version_protected(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $first = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'One', 'audio_url' => 'https://example.com/one.mp3']);
        $second = Episode::create(['show_id' => $show->id, 'guid' => 'two', 'title' => 'Two', 'audio_url' => 'https://example.com/two.mp3']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue', ['version' => 0, 'episode_ids' => [$second->id, $first->id]])->assertOk()->assertJsonPath('data.version', 1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/queue')->assertOk()->assertJsonPath('data.items.0.id', $second->id)->assertJsonPath('data.items.0.show_id', $show->id)->assertJsonPath('data.items.1.id', $first->id);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue', ['version' => 0, 'episode_ids' => [$first->id]])->assertConflict();
    }

    public function test_library_overview_and_playlist_detail_stay_public(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/secret-feed.xml', 'title' => 'Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'secret-guid', 'title' => 'Mindset & Growth Ep', 'audio_url' => 'https://example.com/secret.mp3']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/episodes/'.$episode->id.'/save')->assertOk();
        $playlist = $this->actingAs($user, 'sanctum')->postJson('/api/v1/playlists', ['name' => 'Mindset & Growth'])->assertCreated()->json('data');
        $this->assertSame(0, $playlist['item_count']);
        $this->assertArrayNotHasKey('rss_url', $this->actingAs($user, 'sanctum')->getJson('/api/v1/library')->assertOk()->json('data'));
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/library')->assertOk()->assertJsonPath('data.recent_count', 0);

        DB::table('playback_progress')->insert([
            'user_id' => $user->id,
            'episode_id' => $episode->id,
            'position_seconds' => 90,
            'completed' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/library')->assertOk()->assertJsonPath('data.recent_count', 1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/library/recent')
            ->assertOk()
            ->assertJsonPath('data.0.id', $episode->id)
            ->assertJsonPath('data.0.title', 'Mindset & Growth Ep')
            ->assertJsonPath('data.0.position_seconds', 90)
            ->assertJsonMissingPath('data.0.audio_url')
            ->assertJsonMissingPath('data.0.guid');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/library/saved')->assertOk()->assertJsonPath('data.0.title', 'Mindset & Growth Ep')->assertJsonMissingPath('data.0.audio_url')->assertJsonMissingPath('data.0.guid');
        $detail = $this->actingAs($user, 'sanctum')->getJson('/api/v1/playlists/'.$playlist['id'])->assertOk()->json('data');
        $this->assertSame('Mindset & Growth', $detail['name']);
        $this->assertSame([], $detail['items']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/playlists/'.$playlist['id'].'/items', [
            'episode_id' => $episode->id,
            'version' => $playlist['version'],
        ])->assertOk()->assertJsonPath('data.item_count', 1)->assertJsonPath('data.version', 2);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/playlists/'.$playlist['id'].'/items', [
            'episode_id' => $episode->id,
            'version' => 1,
        ])->assertNotFound();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/playlists/'.$playlist['id'])->assertNotFound();
        $collection = $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', ['name' => 'Sunday Reset'])->assertCreated()->json('data');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/collections/'.$collection['id'])->assertOk()->assertJsonPath('data.name', 'Sunday Reset')->assertJsonPath('data.items', []);
    }
}
