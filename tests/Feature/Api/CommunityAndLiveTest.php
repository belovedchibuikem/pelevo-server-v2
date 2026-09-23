<?php

namespace Tests\Feature\Api;

use App\Mail\PelevoNotice;
use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommunityAndLiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reel_engagement_is_idempotent_and_view_requires_evidence(): void
    {
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Creator']);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'published', 'duration_ms' => 10000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/reels/$reel/engagement", ['action' => 'like'])->assertOk()->assertJsonPath('data.liked', 1);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/reels/$reel/engagement", ['action' => 'like'])->assertOk();
        $session = (string) Str::uuid();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/reels/$reel/view-heartbeat", ['session_id' => $session, 'watched_ms' => 2999])->assertOk()->assertJsonPath('data.qualified', 0);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/reels/$reel/view-heartbeat", ['session_id' => $session, 'watched_ms' => 3000])->assertOk()->assertJsonPath('data.qualified', 1);
        $this->assertDatabaseCount('reel_engagements', 1);
        $this->assertDatabaseCount('reel_views', 1);
    }

    public function test_comment_thread_integrity_likes_deletion_and_reporting(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/community.xml', 'title' => 'Community']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'community', 'title' => 'Episode', 'audio_url' => 'https://example.com/audio.mp3']);
        $comment = $this->actingAs($user, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'episode', 'commentable_id' => $episode->id, 'body' => 'Useful'])->assertCreated()->json('data');
        $this->assertSame('Useful', $comment['body']);
        $this->assertSame($user->name, $comment['author_name']);
        $this->assertTrue($comment['is_mine']);
        $this->assertFalse($comment['is_creator']);
        $this->assertSame(0, $comment['like_count']);
        $this->assertArrayNotHasKey('user_id', $comment);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/comments?commentable_type=episode&commentable_id='.$episode->id.'&sort=wrong')->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/comments/{$comment['id']}/like")->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/reports', ['reportable_type' => 'comment', 'reportable_id' => $comment['id'], 'reason' => 'spam'])->assertCreated();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/comments/{$comment['id']}")->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/comments?commentable_type=episode&commentable_id='.$episode->id)->assertOk()->assertJsonCount(0, 'data');
        $listed = $this->actingAs($user, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'episode', 'commentable_id' => $episode->id, 'body' => 'Again'])->assertCreated()->json('data');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/comments?commentable_type=episode&commentable_id='.$episode->id.'&sort=newest')->assertOk()->assertJsonPath('data.0.id', $listed['id'])->assertJsonPath('data.0.author_name', $user->name)->assertJsonPath('data.0.like_count', 0)->assertJsonPath('data.0.replies_count', 0);
    }

    public function test_comments_and_replies_notify_owners_and_parent_authors(): void
    {
        Mail::fake();
        $creatorUser = User::factory()->create(['name' => 'Host Ada']);
        $listener = User::factory()->create(['name' => 'Listener Ben']);
        $replier = User::factory()->create(['name' => 'Reply Chi']);
        $optedOut = User::factory()->create(['name' => 'Quiet']);
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Host Ada']);
        $show = Show::create(['rss_url' => 'https://example.com/notify-comments.xml', 'title' => 'Morning Africa']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'notify-ep', 'title' => 'Episode one', 'audio_url' => 'https://example.com/a.mp3']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'verified_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_preferences')->insert(['user_id' => $optedOut->id, 'new_episodes' => true, 'push_enabled' => true, 'timezone' => 'UTC', 'mobile_options' => json_encode(['email_enabled' => false, 'types' => ['mentions']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($listener, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'episode', 'commentable_id' => $episode->id, 'body' => 'Loved this episode.'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $creatorUser->id, 'type' => 'comment']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $listener->id]);
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($creatorUser->email) && $mail->heading === 'New comment on Morning Africa');
        Mail::assertNotQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($listener->email));

        $parent = $this->actingAs($optedOut, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'episode', 'commentable_id' => $episode->id, 'body' => 'My take.'])->assertCreated()->json('data.id');
        $this->actingAs($replier, 'sanctum')->postJson('/api/v1/comments', ['commentable_type' => 'episode', 'commentable_id' => $episode->id, 'parent_id' => $parent, 'body' => 'I agree with you.'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $optedOut->id, 'type' => 'reply']);
        Mail::assertNotQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($optedOut->email));
    }

    public function test_creator_controls_valid_live_session_transitions(): void
    {
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Live Host']);
        $show = Show::create(['rss_url' => 'https://example.com/live-claim.xml', 'title' => 'Live Claim']);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        $session = $this->actingAs($user, 'sanctum')->postJson('/api/v1/live-sessions', ['title' => 'Launch'])->assertCreated()->json('data');

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/live-sessions/{$session['id']}/state", ['state' => 'ended'])->assertConflict();
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/live-sessions/{$session['id']}/state", ['state' => 'live'])->assertOk();
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/live-sessions/{$session['id']}/state", ['state' => 'ended'])->assertOk();
    }
}
