<?php

namespace Tests\Feature\Api;

use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiCatalogAskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ask_returns_a_named_catalog_show_ahead_of_listening_history(): void
    {
        Cache::flush();
        config()->set('services.ai.token', '');
        $listener = User::factory()->create();
        $named = Show::create([
            'rss_url' => 'https://example.com/closet.xml',
            'title' => 'Closet Therapy',
            'author' => 'Ikenna',
            'description' => 'Conversations about style and confidence.',
            'status' => 'active',
        ]);
        $history = Show::create([
            'rss_url' => 'https://example.com/history.xml',
            'title' => 'Unrelated History',
            'author' => 'Someone Else',
            'status' => 'active',
        ]);
        $episode = Episode::create([
            'show_id' => $history->id,
            'guid' => 'history-one',
            'title' => 'A recent episode',
            'audio_url' => 'https://example.com/history.mp3',
            'published_at' => now(),
        ]);
        DB::table('follows')->insert([
            'user_id' => $listener->id,
            'show_id' => $history->id,
            'notifications_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('playback_progress')->insert([
            'user_id' => $listener->id,
            'episode_id' => $episode->id,
            'position_seconds' => 12,
            'completed' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($listener, 'sanctum')
            ->postJson('/api/v1/ai/ask', [
                'message' => 'Can you play Closet Therapy for me tonight',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $named->id)
            ->assertJsonPath('data.items.0.title', 'Closet Therapy')
            ->assertJsonPath('data.items.0.show_id', $named->id);
        $titles = collect($response->json('data.items'))->pluck('title');
        $this->assertFalse($titles->contains('Unrelated History'));
    }

    public function test_ask_finds_the_show_when_the_listener_names_an_episode(): void
    {
        Cache::flush();
        config()->set('services.ai.token', '');
        $listener = User::factory()->create();
        $show = Show::create([
            'rss_url' => 'https://example.com/wardrobe.xml',
            'title' => 'Wardrobe Memo',
            'author' => 'Ikenna',
            'status' => 'active',
        ]);
        Episode::create([
            'show_id' => $show->id,
            'guid' => 'wardrobe-one',
            'title' => 'Dont Leave Your Customers To Influencers',
            'audio_url' => 'https://example.com/wardrobe.mp3',
            'published_at' => now(),
        ]);

        $this->actingAs($listener, 'sanctum')
            ->postJson('/api/v1/ai/ask', [
                'message' => 'Dont Leave Your Customers To Influencers',
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $show->id)
            ->assertJsonPath('data.items.0.title', 'Wardrobe Memo');
    }
}
