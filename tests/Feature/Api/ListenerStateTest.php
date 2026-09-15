<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ListenerStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_notes_and_settings_reject_stale_versions(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/state.xml', 'title' => 'State Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'state', 'title' => 'State Episode', 'audio_url' => 'https://example.com/state.mp3']);
        $client = $this->actingAs($user, 'sanctum');

        $client->patchJson('/api/v1/player/session', ['episode_id' => $episode->id, 'playing' => true, 'playback_rate' => 1.25, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $client->patchJson('/api/v1/player/session', ['episode_id' => $episode->id, 'playing' => false, 'playback_rate' => 1, 'version' => 0])->assertConflict();
        $client->putJson("/api/v1/episodes/{$episode->id}/notes", ['body' => 'Remember this point', 'position_seconds' => 42, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $client->putJson("/api/v1/episodes/{$episode->id}/notes", ['body' => 'Stale', 'version' => 0])->assertConflict();
        $client->patchJson('/api/v1/library/settings', ['hide_completed' => true, 'sort' => 'title', 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $client->patchJson('/api/v1/downloads/settings', ['wifi_only' => false, 'auto_delete_completed' => true, 'max_storage_mb' => 4096, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
    }

    public function test_reactions_populate_liked_library_and_stats_are_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/stats.xml', 'title' => 'Stats Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'stats', 'title' => 'Stats Episode', 'audio_url' => 'https://example.com/stats.mp3']);
        DB::table('playback_progress')->insert([['user_id' => $user->id, 'episode_id' => $episode->id, 'position_seconds' => 120, 'completed' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('follows')->insert(['user_id' => $user->id, 'show_id' => $show->id, 'created_at' => now(), 'updated_at' => now()]);
        $client = $this->actingAs($user, 'sanctum');

        $client->putJson("/api/v1/episodes/{$episode->id}/reaction", ['reaction' => 'love'])->assertOk();
        $client->getJson('/api/v1/library/liked')->assertOk()->assertJsonPath('data.0.id', $episode->id);
        $stats = $client->getJson('/api/v1/stats/me')->assertOk()->assertJsonPath('data.listening_seconds', 120)->assertJsonPath('data.episodes_completed', 1)->assertJsonPath('data.shows_followed', 1)->assertJsonPath('data.downloads_count', 0)->assertJsonPath('data.shows.0.title', 'Stats Show');
        $this->assertSame(['listening_seconds', 'episodes_started', 'episodes_completed', 'shows_followed', 'episodes_saved', 'reviews', 'downloads_count', 'daily', 'streak', 'achievements', 'shows', 'categories'], array_keys($stats->json('data')));
        $this->assertNotEmpty($stats->json('data.daily'));
        $this->assertSame([], $stats->json('data.achievements'));
        $this->assertGreaterThanOrEqual(1, $stats->json('data.streak.current_days'));
        $this->assertSame((string) $show->id, $stats->json('data.shows.0.id'));
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/stats/me')->assertOk()->assertJsonPath('data.listening_seconds', 0)->assertJsonPath('data.shows', []);
    }

    public function test_queue_item_add_remove_reorder_and_clear_all_require_current_version(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/queue-state.xml', 'title' => 'Queue Show']);
        $first = Episode::create(['show_id' => $show->id, 'guid' => 'queue-first', 'title' => 'First', 'audio_url' => 'https://example.com/first.mp3']);
        $second = Episode::create(['show_id' => $show->id, 'guid' => 'queue-second', 'title' => 'Second', 'audio_url' => 'https://example.com/second.mp3']);
        $client = $this->actingAs($user, 'sanctum');

        $client->postJson('/api/v1/queue/items', ['episode_id' => $first->id, 'version' => 0])->assertCreated()->assertJsonPath('data.version', 1);
        $client->postJson('/api/v1/queue/items', ['episode_id' => $second->id, 'version' => 0])->assertConflict();
        $client->postJson('/api/v1/queue/items', ['episode_id' => $second->id, 'version' => 1])->assertCreated()->assertJsonPath('data.version', 2);
        $item = DB::table('queue_items')->where('episode_id', $first->id)->value('id');
        $client->deleteJson("/api/v1/queue/items/{$item}", ['version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $client->putJson('/api/v1/queue/reorder', ['episode_ids' => [$first->id, $second->id], 'version' => 3])->assertOk()->assertJsonPath('data.version', 4);
        $client->deleteJson('/api/v1/queue', ['version' => 4])->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_owner_can_delete_an_authorized_download(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/download-state.xml', 'title' => 'Download Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'download-state', 'title' => 'Download Episode', 'audio_url' => 'https://example.com/download.mp3']);
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'download-phone', 'name' => 'Phone']);
        $id = (string) Str::ulid();
        DB::table('downloads')->insert(['id' => $id, 'user_id' => $user->id, 'episode_id' => $episode->id, 'device_id' => $device->id, 'authorized_until' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($other, 'sanctum')->deleteJson('/api/v1/downloads/'.$id)->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/downloads/'.$id)->assertOk()->assertJsonPath('data.deleted', true);
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/downloads/'.$id)->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/storage')->assertOk()->assertJsonPath('data.offline_items', 0);
    }
}
