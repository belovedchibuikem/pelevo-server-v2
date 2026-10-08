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

final class DownloadPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_free_accounts_stop_at_ten_episode_downloads_and_auto_download_lists_new_followed_episodes(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/downloads.xml', 'title' => 'Download Show']);
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'download-phone', 'name' => 'Phone']);
        $episodes = collect(range(1, 11))->map(fn (int $index) => Episode::create([
            'show_id' => $show->id,
            'guid' => 'download-'.$index,
            'title' => 'Episode '.$index,
            'audio_url' => 'https://example.com/'.$index.'.mp3',
            'availability' => 'available',
            'published_at' => now()->subHours($index),
        ]));
        foreach ($episodes->take(10) as $episode) {
            DB::table('downloads')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'episode_id' => $episode->id,
                'device_id' => $device->id,
                'authorized_until' => now()->addMinutes(15),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $blocked = $episodes->last();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier);

        $client->postJson("/api/v1/episodes/{$blocked->id}/downloads")->assertForbidden()->assertJsonPath('error.code', 'DOWNLOAD_LIMIT');
        $client->postJson("/api/v1/episodes/{$episodes->first()->id}/downloads")->assertOk()->assertJsonPath('data.episode_id', $episodes->first()->id);

        $client->getJson('/api/v1/downloads/settings')->assertOk()->assertJsonPath('data.auto_download_new', false)->assertJsonPath('data.wifi_only', true);
        $client->patchJson('/api/v1/downloads/settings', [
            'wifi_only' => true,
            'auto_download_new' => true,
            'auto_delete_completed' => false,
            'max_storage_mb' => 2048,
            'version' => 0,
        ])->assertOk()->assertJsonPath('data.auto_download_new', true)->assertJsonPath('data.version', 1);
        DB::table('follows')->insert(['user_id' => $user->id, 'show_id' => $show->id, 'created_at' => now(), 'updated_at' => now()]);

        $client->getJson('/api/v1/downloads/new-episodes')->assertOk()->assertJsonCount(0, 'data');
    }
}
