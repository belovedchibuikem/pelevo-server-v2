<?php

namespace Tests\Feature\Api;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HomeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_contract_contains_system_owned_modules_without_provider_requests(): void
    {
        Http::preventStrayRequests();
        config()->set('services.podcast_index.enabled', false);
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/africa.xml', 'title' => 'Africa Daily', 'country_code' => 'NG']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'home-contract', 'title' => 'A local episode', 'audio_url' => 'https://example.com/audio.mp3', 'duration_seconds' => 600, 'published_at' => now()]);
        DB::table('follows')->insert(['user_id' => $user->id, 'show_id' => $show->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('playback_progress')->insert(['user_id' => $user->id, 'episode_id' => $episode->id, 'position_seconds' => 30, 'completed' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/feed')->assertOk()->assertJsonPath('data.schema_version', 2);
        $keys = collect($response->json('data.rails'))->pluck('key')->all();

        $this->assertSame([
            'moods',
            'pick_for_today',
            'continue_listening',
            'made_for_you',
            'quick_listen',
            'because_you_listened',
            'trending',
            'new_from_following',
            'african_voices',
            'try_something_new',
            'explore_by_topic',
            'trending_shorts',
            'shorts_for_you',
            'browse_categories',
        ], $keys);
        $response->assertJsonPath('data.rails.0.items.0.type', 'mood');
        $response->assertJsonPath('data.rails.6.items.0.title', 'Africa Daily');
        $response->assertJsonPath('data.rails.6.items.0.type', 'show');
        $response->assertJsonPath('data.rails.8.items.0.title', 'Africa Daily');
        $response->assertJsonPath('data.rails.8.items.0.type', 'show');
        $response->assertJsonPath('data.rails.7.items.0.title', 'A local episode');
        $response->assertJsonPath('data.rails.13.items.0.title', 'Podcasts');
        $tryNew = collect($response->json('data.rails'))->firstWhere('key', 'try_something_new');
        $this->assertNotEmpty($tryNew['items']);
        $this->assertSame('category', $tryNew['items'][0]['type']);
        Http::assertNothingSent();
    }

    public function test_rail_endpoint_returns_the_same_canonical_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/rails/quick_listen')
            ->assertOk()
            ->assertJsonPath('data.schema_version', 2)
            ->assertJsonPath('data.rail.id', 'home.quick_listen')
            ->assertJsonPath('data.rail.type', 'quick_listen');
    }
}
