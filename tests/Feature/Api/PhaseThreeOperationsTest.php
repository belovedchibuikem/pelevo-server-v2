<?php

namespace Tests\Feature\Api;

use App\Models\ConfigurationVersion;
use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PhaseThreeOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creator_follow_related_show_engagement_aliases_and_monetization_opt_in(): void
    {
        ConfigurationVersion::create(['version' => 99, 'payload' => ['money' => ['reels_min_followers' => 1, 'reels_min_views' => 1]], 'reason' => 'test', 'effective_at' => now()]);
        [$owner, $creator, $show] = $this->verifiedCreator();
        $viewer = User::factory()->create();
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'related', 'title' => 'Related', 'audio_url' => 'https://example.com/related.mp3']);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'show_id' => $show->id, 'episode_id' => $episode->id, 'state' => 'published', 'duration_ms' => 10000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $view = (string) Str::ulid();
        DB::table('reel_views')->insert(['id' => $view, 'reel_id' => $reel, 'user_id' => $viewer->id, 'session_id' => (string) Str::uuid(), 'watched_ms' => 5000, 'qualified' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_view_credits')->insert(['reel_id' => $reel, 'user_id' => $viewer->id, 'window_key' => '1', 'ordinal' => 1, 'reel_view_id' => $view, 'created_at' => now()]);

        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/creators/{$creator->id}/follow")->assertCreated();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/reels/{$reel}/related-show")->assertOk()->assertJsonPath('data.id', $show->id);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/reels/{$reel}/like")->assertOk()->assertJsonPath('data.liked', 1);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/reels/{$reel}/save")->assertOk()->assertJsonPath('data.saved', 1);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reels/monetization')->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.min_followers', 1)
            ->assertJsonPath('data.min_views', 1)
            ->assertJsonPath('data.followers', 1)
            ->assertJsonPath('data.qualified_views', 1);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reels/monetization/opt-in')->assertOk()->assertJsonPath('data.opted_in', true);
    }

    public function test_following_feed_includes_reels_from_followed_creators(): void
    {
        [, $creator, $show] = $this->verifiedCreator();
        $viewer = User::factory()->create();
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'show_id' => $show->id, 'state' => 'published', 'duration_ms' => 10000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/creators/{$creator->id}/follow")->assertCreated();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/following')->assertOk()->assertJsonPath('data.0.id', $reel);
    }

    public function test_published_feed_presents_creator_and_viewer_fields(): void
    {
        [$owner, $creator, $show] = $this->verifiedCreator();
        $owner->forceFill(['handle' => 'ada_creator'])->save();
        $viewer = User::factory()->create();
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'feed-ep', 'title' => 'Feed episode', 'audio_url' => 'https://example.com/feed.mp3']);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'show_id' => $show->id, 'episode_id' => $episode->id, 'caption' => 'Published reel caption.', 'state' => 'published', 'duration_ms' => 10000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_engagements')->insert(['reel_id' => $reel, 'user_id' => $viewer->id, 'liked' => true, 'saved' => false, 'not_interested' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('follows')->insert(['user_id' => $viewer->id, 'show_id' => $show->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/for-you')->assertOk()
            ->assertJsonPath('data.0.id', $reel)
            ->assertJsonPath('data.0.creator_name', 'Creator')
            ->assertJsonPath('data.0.creator_handle', 'ada_creator')
            ->assertJsonPath('data.0.caption', 'Published reel caption.')
            ->assertJsonPath('data.0.episode_title', 'Feed episode')
            ->assertJsonPath('data.0.likes_count', 1)
            ->assertJsonPath('data.0.liked', true)
            ->assertJsonPath('data.0.saved', false)
            ->assertJsonMissingPath('data.0.qualified_views');
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/following')->assertOk()->assertJsonPath('data.0.id', $reel);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/trending')->assertOk()->assertJsonPath('data.0.creator_profile_id', $creator->id);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reels/for-you')->assertOk()->assertJsonPath('data.0.liked', false);
        $hidden = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $hidden, 'creator_profile_id' => $creator->id, 'state' => 'published', 'duration_ms' => 8000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_engagements')->insert(['reel_id' => $hidden, 'user_id' => $viewer->id, 'liked' => false, 'saved' => false, 'not_interested' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/reels/{$reel}/save")->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/saved')->assertOk()
            ->assertJsonPath('data.0.id', $reel)
            ->assertJsonPath('data.0.saved', true)
            ->assertJsonPath('data.0.episode_title', 'Feed episode');
    }

    public function test_for_you_ranks_engaged_reels_ahead_of_new_low_view_reels_but_still_includes_them(): void
    {
        [, $creator] = $this->verifiedCreator();
        $viewer = User::factory()->create();
        $popular = [];
        for ($i = 0; $i < 5; $i++) {
            $id = (string) Str::ulid();
            $popular[] = $id;
            DB::table('reels')->insert(['id' => $id, 'creator_profile_id' => $creator->id, 'state' => 'published', 'duration_ms' => 8000, 'published_at' => now()->subDays(20 - $i), 'created_at' => now()->subDays(20 - $i), 'updated_at' => now()]);
            for ($like = 0; $like < 6; $like++) {
                $fan = User::factory()->create();
                DB::table('reel_engagements')->insert(['reel_id' => $id, 'user_id' => $fan->id, 'liked' => true, 'saved' => $like === 0, 'not_interested' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $fresh = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $fresh, 'creator_profile_id' => $creator->id, 'state' => 'published', 'duration_ms' => 8000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $ids = $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/reels/for-you?limit=5')->assertOk()->json('data');
        $ids = array_column($ids, 'id');
        $this->assertCount(5, $ids);
        $this->assertContains($fresh, $ids);
        $this->assertSame($fresh, $ids[4]);
        $this->assertSame(array_slice(array_reverse($popular), 0, 4), array_slice($ids, 0, 4));
    }

    public function test_rejected_creator_content_can_be_appealed_once(): void
    {
        [$owner, $creator] = $this->verifiedCreator();
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'rejected', 'duration_ms' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['subject_type' => 'reel', 'subject_id' => $reel, 'reason' => 'The content complies with the stated policy.'];
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/appeals', $payload)->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/appeals', $payload)->assertConflict();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/appeals')->assertOk()->assertJsonCount(1, 'data');
    }

    private function verifiedCreator(): array
    {
        $owner = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $owner->id, 'display_name' => 'Creator']);
        $show = Show::create(['rss_url' => 'https://example.com/'.Str::random(8).'.xml', 'title' => 'Creator Show']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $creator, $show];
    }
}
