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
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/queue')->assertOk()->assertJsonPath('data.items.0.id', $second->id)->assertJsonPath('data.items.0.show_id', $show->id)->assertJsonPath('data.items.0.audio_url', 'https://example.com/two.mp3')->assertJsonPath('data.items.1.id', $first->id);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue', ['version' => 0, 'episode_ids' => [$first->id]])->assertConflict();
    }

    public function test_queue_reorder_moves_play_order_without_replacing_items(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $first = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'One', 'audio_url' => 'https://example.com/one.mp3']);
        $second = Episode::create(['show_id' => $show->id, 'guid' => 'two', 'title' => 'Two', 'audio_url' => 'https://example.com/two.mp3']);
        $third = Episode::create(['show_id' => $show->id, 'guid' => 'three', 'title' => 'Three', 'audio_url' => 'https://example.com/three.mp3']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue', [
            'version' => 0,
            'episode_ids' => [$first->id, $second->id, $third->id],
        ])->assertOk();

        $before = $this->actingAs($user, 'sanctum')->getJson('/api/v1/queue')->assertOk()->json('data');
        $firstItemId = $before['items'][0]['queue_item_id'];

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue/reorder', [
            'version' => $before['version'],
            'episode_ids' => [$third->id, $first->id, $second->id],
        ])->assertOk()->assertJsonPath('data.version', $before['version'] + 1);

        $after = $this->actingAs($user, 'sanctum')->getJson('/api/v1/queue')->assertOk()->json('data');
        $this->assertSame([$third->id, $first->id, $second->id], array_column($after['items'], 'id'));
        $this->assertSame($firstItemId, $after['items'][1]['queue_item_id']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue/reorder', [
            'version' => $before['version'],
            'episode_ids' => [$third->id, $first->id, $second->id],
        ])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/queue/reorder', [
            'version' => $after['version'],
            'episode_ids' => [$first->id, $second->id],
        ])->assertConflict()->assertJsonPath('error.code', 'QUEUE_MISMATCH');
    }

    public function test_playlists_are_public_by_default_and_items_can_be_reordered_and_removed(): void
    {
        config()->set('services.podcast_index.enabled', false);
        $owner = User::factory()->create(['name' => 'Amina']);
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/feed.xml', 'title' => 'Show']);
        $first = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'One', 'audio_url' => 'https://example.com/one.mp3']);
        $second = Episode::create(['show_id' => $show->id, 'guid' => 'two', 'title' => 'Two', 'audio_url' => 'https://example.com/two.mp3']);

        $playlist = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/playlists', ['name' => 'Morning Money'])->assertCreated()->json('data');
        $this->assertTrue($playlist['is_public']);
        $this->assertTrue($playlist['is_owner']);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/playlists/'.$playlist['id'].'/items', [
            'episode_id' => $first->id,
            'version' => $playlist['version'],
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/playlists/'.$playlist['id'].'/items', [
            'episode_id' => $second->id,
            'version' => 2,
        ])->assertOk();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/playlists/'.$playlist['id'].'/items/reorder', [
            'version' => 3,
            'episode_ids' => [$second->id, $first->id],
        ])->assertOk()->assertJsonPath('data.version', 4);

        $detail = $this->actingAs($other, 'sanctum')->getJson('/api/v1/playlists/'.$playlist['id'])->assertOk()->json('data');
        $this->assertSame([$second->id, $first->id], array_column($detail['items'], 'id'));
        $this->assertFalse($detail['is_owner']);
        $this->assertSame('Amina', $detail['owner_name']);

        $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/playlists/'.$playlist['id'].'/items/'.$first->id, [
            'version' => 4,
        ])->assertNotFound();

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/v1/playlists/'.$playlist['id'].'/items/'.$first->id, [
            'version' => 4,
        ])->assertOk()->assertJsonPath('data.item_count', 1);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/search?q=Morning')->assertOk()
            ->assertJsonPath('data.playlists.0.id', $playlist['id'])
            ->assertJsonPath('data.playlists.0.source', 'user')
            ->assertJsonPath('data.playlists.0.title', 'Morning Money');

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/playlists/'.$playlist['id'], [
            'name' => 'Morning Money',
            'description' => null,
            'is_public' => false,
            'version' => 5,
        ])->assertOk();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/playlists/'.$playlist['id'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/search?q=Morning')->assertOk()->assertJsonPath('data.playlists', []);
    }

    public function test_playlist_cover_art_is_reencoded_for_the_owner(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $playlist = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/playlists', ['name' => 'Covered'])->assertCreated()->json('data');

        $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/playlists/'.$playlist['id'].'/artwork', [
            'artwork' => \Illuminate\Http\UploadedFile::fake()->image('cover.png', 80, 80),
        ], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('data.artwork_url');
        $this->assertIsString($url);
        $this->assertNotSame('', $url);
        $this->actingAs($other, 'sanctum')->post('/api/v1/playlists/'.$playlist['id'].'/artwork', [
            'artwork' => \Illuminate\Http\UploadedFile::fake()->image('cover.png', 80, 80),
        ], ['Accept' => 'application/json'])->assertNotFound();
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
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/playlists/'.$playlist['id'], [
            'name' => 'Mindset & Growth',
            'description' => null,
            'is_public' => true,
            'version' => 2,
        ])->assertOk()->assertJsonPath('data.is_public', true);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/playlists/'.$playlist['id'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mindset & Growth')
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.items.0.title', 'Mindset & Growth Ep');
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/playlists?scope=others')
            ->assertOk()
            ->assertJsonPath('data.0.id', $playlist['id'])
            ->assertJsonPath('data.0.is_owner', false);
        $collection = $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', ['name' => 'Sunday Reset'])->assertCreated()->json('data');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/collections/'.$collection['id'])->assertOk()->assertJsonPath('data.name', 'Sunday Reset')->assertJsonPath('data.items', []);
    }
}
