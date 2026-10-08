<?php

namespace Tests\Feature\Api;

use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudioEpisodeScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_claimed_episode_lists_stay_on_the_selected_show(): void
    {
        $owner = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $owner->id, 'display_name' => 'Owner']);
        $first = Show::create(['rss_url' => 'https://example.com/one.xml', 'title' => 'Podcast One']);
        $second = Show::create(['rss_url' => 'https://example.com/two.xml', 'title' => 'Podcast Two']);
        $one = Episode::create(['show_id' => $first->id, 'guid' => 'one', 'title' => 'One Episode', 'audio_url' => 'https://example.com/one.mp3', 'availability' => 'available', 'published_at' => now()]);
        $two = Episode::create(['show_id' => $second->id, 'guid' => 'two', 'title' => 'Two Episode', 'audio_url' => 'https://example.com/two.mp3', 'availability' => 'available', 'published_at' => now()]);
        foreach ([$first, $second] as $show) {
            $claim = (string) Str::ulid();
            DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = $this->actingAs($owner, 'sanctum');

        $client->getJson('/api/v1/studio/episodes?show_id='.$first->id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $one->id)
            ->assertJsonPath('data.0.show_title', 'Podcast One');
        $client->getJson('/api/v1/studio/episodes?show_id='.$second->id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $two->id);
        $client->getJson('/api/v1/studio/episodes')->assertOk()->assertJsonCount(2, 'data');
        $client->getJson('/api/v1/studio/episodes?show_id=01J00000000000000000000000')->assertStatus(422)->assertJsonPath('error.code', 'UNPROCESSABLE');
    }
}
