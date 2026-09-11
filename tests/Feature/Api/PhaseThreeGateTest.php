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

final class PhaseThreeGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reel_drafts_are_crud_resources_isolated_to_creator(): void
    {
        $creatorUser = User::factory()->create();
        CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Creator']);
        $other = User::factory()->create();
        CreatorProfile::create(['user_id' => $other->id, 'display_name' => 'Other']);
        $draft = $this->actingAs($creatorUser, 'sanctum')->postJson('/api/v1/reels/drafts', ['caption' => 'First'])->assertCreated()->json('data');
        $this->actingAs($creatorUser, 'sanctum')->putJson("/api/v1/reels/drafts/{$draft['id']}", ['caption' => 'Updated'])->assertOk()->assertJsonPath('data.caption', 'Updated');
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/reels/drafts/{$draft['id']}")->assertNotFound();
        $this->actingAs($creatorUser, 'sanctum')->deleteJson("/api/v1/reels/drafts/{$draft['id']}")->assertOk();
    }

    public function test_creator_pin_is_unique_and_creator_can_hide_comment(): void
    {
        [$creatorUser, $reel] = $this->creatorReel('published');
        $fan = User::factory()->create();
        $first = $this->actingAs($fan, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'reel', 'commentable_id' => $reel, 'body' => 'First'])->assertCreated()->json('data.id');
        $second = $this->actingAs($fan, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'reel', 'commentable_id' => $reel, 'body' => 'Second'])->assertCreated()->json('data.id');
        $this->actingAs($creatorUser, 'sanctum')->putJson("/api/v1/comments/$first/pin")->assertOk();
        $this->actingAs($creatorUser, 'sanctum')->putJson("/api/v1/comments/$second/pin")->assertOk();
        $this->assertDatabaseHas('comments', ['id' => $first, 'is_pinned' => 0]);
        $this->assertDatabaseHas('comments', ['id' => $second, 'is_pinned' => 1]);
        $this->assertDatabaseCount('comment_pins', 1);
        $this->actingAs($creatorUser, 'sanctum')->putJson("/api/v1/comments/$second/hide")->assertOk();
        $this->assertDatabaseHas('comments', ['id' => $second, 'is_pinned' => 0]);
    }

    public function test_qualified_views_are_capped_per_user_window(): void
    {
        [, $reel] = $this->creatorReel('published');
        $fan = User::factory()->create();
        $first = $this->actingAs($fan, 'sanctum')->postJson("/api/v1/reels/$reel/view-heartbeat", ['session_id' => (string) Str::uuid(), 'watched_ms' => 3000])->assertOk();
        $second = $this->actingAs($fan, 'sanctum')->postJson("/api/v1/reels/$reel/view-heartbeat", ['session_id' => (string) Str::uuid(), 'watched_ms' => 3000])->assertOk();
        $first->assertJsonPath('data.counted', true);
        $second->assertJsonPath('data.counted', false);
        $this->assertDatabaseCount('reel_view_credits', 1);
    }

    public function test_live_status_is_public_and_transitions_persist_events(): void
    {
        $creatorUser = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Host']);
        $show = Show::create(['rss_url' => 'https://example.com/live-host.xml', 'title' => 'Live Host Show']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        $live = $this->actingAs($creatorUser, 'sanctum')->postJson('/api/v1/live-sessions', ['title' => 'Live now'])->assertCreated()->json('data');
        $this->actingAs($creatorUser, 'sanctum')->putJson("/api/v1/live-sessions/{$live['id']}/state", ['state' => 'live'])->assertOk();
        $this->getJson("/api/v1/live/{$live['id']}")->assertOk()->assertJsonPath('data.state', 'live')->assertJsonPath('data.events.0.type', 'live');
        $this->assertDatabaseHas('live_events', ['live_session_id' => $live['id'], 'type' => 'live']);
    }

    public function test_following_feed_and_creator_episode_link_are_scoped(): void
    {
        [$creatorUser, $reel] = $this->creatorReel('published');
        $show = Show::create(['rss_url' => 'https://example.com/reels.xml', 'title' => 'Reel show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'linked', 'title' => 'Linked', 'audio_url' => 'https://example.com/linked.mp3']);
        DB::table('reels')->where('id', $reel)->update(['show_id' => $show->id]);
        $fan = User::factory()->create();
        DB::table('follows')->insert(['user_id' => $fan->id, 'show_id' => $show->id, 'notifications_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($creatorUser, 'sanctum')->postJson("/api/v1/reels/$reel/episode-links", ['episode_id' => $episode->id])->assertOk();
        $this->actingAs($fan, 'sanctum')->getJson('/api/v1/reels/following')->assertOk()->assertJsonPath('data.0.id', $reel);
        $this->actingAs($fan, 'sanctum')->getJson("/api/v1/reels/$reel")->assertOk()->assertJsonPath('data.episode_links.0', $episode->id);
    }

    private function creatorReel(string $state): array
    {
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => $state, 'duration_ms' => 10000, 'published_at' => $state === 'published' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $reel];
    }
}
