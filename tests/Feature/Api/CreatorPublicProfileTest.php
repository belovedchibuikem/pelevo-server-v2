<?php

namespace Tests\Feature\Api;

use App\Models\CreatorProfile;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreatorPublicProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_viewers_can_open_a_creator_profile_and_their_published_reels(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create(['handle' => 'ada_creator', 'name' => 'Ada Creator']);
        $creator = CreatorProfile::create(['user_id' => $owner->id, 'display_name' => 'Ada Creator']);
        DB::table('user_profiles')->insert(['user_id' => $owner->id, 'bio' => 'African stories on the go.', 'avatar_url' => '/storage/avatars/ada.jpg', 'locale' => 'en', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $show = Show::create(['rss_url' => 'https://example.com/ada.xml', 'title' => 'Ada Daily', 'author' => 'Ada']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'rss', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'caption' => 'Morning reel', 'state' => 'published', 'duration_ms' => 12000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $hidden = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $hidden, 'creator_profile_id' => $creator->id, 'caption' => 'Not live', 'state' => 'removed', 'duration_ms' => 8000, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/creators/{$creator->id}")
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Ada Creator')
            ->assertJsonPath('data.handle', 'ada_creator')
            ->assertJsonPath('data.bio', 'African stories on the go.')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.following', false)
            ->assertJsonPath('data.stats.reels', 1)
            ->assertJsonPath('data.stats.podcasts', 1)
            ->assertJsonPath('data.shows.0.title', 'Ada Daily');

        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/creators/{$creator->id}/reels")
            ->assertOk()
            ->assertJsonPath('data.0.id', $reel)
            ->assertJsonPath('data.0.creator_name', 'Ada Creator')
            ->assertJsonMissing(['id' => $hidden]);
    }
}
