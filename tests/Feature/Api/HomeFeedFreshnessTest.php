<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HomeFeedFreshnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_expired_snapshot_from_the_last_six_hours_is_returned_and_refreshed_later(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        DB::table('home_feed_snapshots')->insert([
            'user_id' => $user->id,
            'rails' => json_encode([[
                'id' => 'home.trending',
                'key' => 'trending',
                'type' => 'shows',
                'title' => 'Morning Trending',
                'items' => [],
            ]], JSON_THROW_ON_ERROR),
            'version' => 4,
            'generated_at' => now()->subHours(2),
            'expires_at' => now()->subMinutes(20),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/home/feed')
            ->assertOk()
            ->assertJsonPath('data.rails.0.title', 'Morning Trending')
            ->assertJsonPath('meta.freshness', 'stale');
    }

    public function test_a_snapshot_older_than_six_hours_is_not_served(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        DB::table('home_feed_snapshots')->insert([
            'user_id' => $user->id,
            'rails' => json_encode([[
                'id' => 'home.trending',
                'key' => 'trending',
                'type' => 'shows',
                'title' => 'Week Old Trending',
                'items' => [],
            ]], JSON_THROW_ON_ERROR),
            'version' => 1,
            'generated_at' => now()->subDays(3),
            'expires_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/home/feed')->assertOk();
        $this->assertNotSame('stale', $response->json('meta.freshness'));
        $titles = collect($response->json('data.rails'))->pluck('title');
        $this->assertFalse($titles->contains('Week Old Trending'));
    }
}
