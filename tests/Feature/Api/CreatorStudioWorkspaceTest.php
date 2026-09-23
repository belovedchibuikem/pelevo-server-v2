<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Mail\ClaimVerificationCode;
use App\Models\CreatorProfile;
use App\Models\Episode;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreatorStudioWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_claim_status_and_reissued_code_are_private_expiring_and_owned(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/*' => fn () => Http::response($this->rss())]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/status.xml', 'title' => 'Status']);
        $claim = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/shows/{$show->id}/claims", ['method' => 'email'])->assertCreated()->json('data.claim_id');
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/claims/{$claim}")->assertNotFound();
        $status = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/claims/{$claim}")->assertOk()->assertJsonPath('data.destination_masked', 'o****@example.com');
        $this->assertStringNotContainsString('owner@example.com', $status->getContent());
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/claims/{$claim}/challenge")->assertAccepted()->assertJsonPath('data.masked_destination', 'o****@example.com');
        $this->assertDatabaseCount('claim_challenges', 2);
        $this->assertDatabaseMissing('claim_challenges', ['show_claim_id' => $claim, 'consumed_at' => null, 'attempts' => 1]);
        Mail::assertSent(ClaimVerificationCode::class, 2);
    }

    public function test_verified_owner_and_studio_member_receive_scoped_creator_reads(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $owner->id, 'display_name' => 'Owner']);
        $show = Show::create(['rss_url' => 'https://example.com/studio.xml', 'title' => 'Studio Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'studio-1', 'title' => 'Episode', 'audio_url' => 'https://example.com/a.mp3', 'duration_seconds' => 1256]);
        $claim = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $claim, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'verified', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verified_show_claims')->insert(['show_id' => $show->id, 'show_claim_id' => $claim, 'created_at' => now(), 'updated_at' => now()]);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'show_id' => $show->id, 'episode_id' => $episode->id, 'caption' => 'Studio reel', 'state' => 'published', 'duration_ms' => 12000, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $studio = (string) Str::ulid();
        DB::table('studios')->insert(['id' => $studio, 'creator_profile_id' => $creator->id, 'name' => 'Team', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('studio_members')->insert(['studio_id' => $studio, 'user_id' => $member->id, 'role' => 'analyst', 'created_at' => now(), 'updated_at' => now()]);
        $listener = User::factory()->create(['name' => 'Listener Ada', 'handle' => 'ada_listener']);
        DB::table('creator_followers')->insert(['creator_profile_id' => $creator->id, 'user_id' => $listener->id, 'created_at' => now(), 'updated_at' => now()]);
        $giftType = GiftType::create(['slug' => 'studio-applause', 'name' => 'Applause', 'coins' => 100, 'version' => 1]);
        $funding = FinancialAccount::create(['type' => 'studio_test_funding', 'unit' => 'PCN', 'balance' => 0]);
        $creatorAccount = FinancialAccount::create(['owner_type' => CreatorProfile::class, 'owner_id' => $creator->id, 'type' => 'creator_balance', 'unit' => 'PCN', 'balance' => 0]);
        $transaction = app(PostLedgerTransaction::class)->handle('gift.sent', 'studio-gift-1', 'PCN', [['account_id' => $funding->id, 'amount' => -90], ['account_id' => $creatorAccount->id, 'amount' => 90]]);
        DB::table('gifts')->insert(['id' => (string) Str::ulid(), 'sender_id' => $listener->id, 'creator_profile_id' => $creator->id, 'gift_type_id' => $giftType->id, 'ledger_transaction_id' => $transaction->id, 'message' => 'Thank you', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tax_profiles')->insert(['id' => (string) Str::ulid(), 'creator_profile_id' => $creator->id, 'country_code' => 'NG', 'state' => 'pending', 'details_encrypted' => 'secret-tax', 'created_at' => now(), 'updated_at' => now()]);
        $payoutMethod = (string) Str::ulid();
        DB::table('payout_methods')->insert(['id' => $payoutMethod, 'owner_type' => User::class, 'owner_id' => $owner->id, 'provider' => 'paystack', 'kind' => 'bank', 'label' => 'Access Bank', 'destination_encrypted' => 'private', 'destination_last_four' => '1234', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('creator_payout_settings')->insert(['creator_profile_id' => $creator->id, 'payout_method_id' => $payoutMethod, 'currency' => 'NGN', 'schedule' => 'monthly', 'minimum_amount' => 10000, 'compliance_state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$owner, $member] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio')->assertOk()->assertJsonPath('data.display_name', 'Owner');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/shows')->assertOk()->assertJsonPath('data.0.id', $show->id)->assertJsonPath('data.0.title', 'Studio Show')->assertJsonPath('data.0.episodes_count', 1)->assertJsonPath('data.0.follower_count', 0);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/episodes')->assertOk()->assertJsonPath('data.0.id', $episode->id)->assertJsonPath('data.0.title', 'Episode')->assertJsonPath('data.0.show_title', 'Studio Show')->assertJsonPath('data.0.duration_seconds', 1256)->assertJsonPath('data.0.availability', 'available');
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/studio/episodes/{$episode->id}")->assertOk()->assertJsonPath('data.id', $episode->id)->assertJsonPath('data.show_title', 'Studio Show');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/reels')->assertOk()->assertJsonPath('data.0.id', $reel)->assertJsonPath('data.0.caption', 'Studio reel')->assertJsonPath('data.0.episode_title', 'Episode')->assertJsonPath('data.0.state', 'published');
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/studio/reels/{$reel}")->assertOk()->assertJsonPath('data.id', $reel)->assertJsonPath('data.episode_title', 'Episode');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/audience')->assertOk()->assertJsonPath('data.followers', 1)->assertJsonPath('data.show_followers', 0)->assertJsonPath('data.listeners_30d', 0);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/audience/followers')->assertOk()->assertJsonPath('data.0.id', $listener->id)->assertJsonPath('data.0.name', 'Listener Ada')->assertJsonPath('data.0.handle', 'ada_listener');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/audience/supporters')->assertOk()->assertJsonPath('data.0.id', $listener->id)->assertJsonPath('data.0.name', 'Listener Ada')->assertJsonPath('data.0.gifts_count', 1)->assertJsonPath('data.0.coins', 100)->assertJsonPath('meta.supporters_count', 1)->assertJsonPath('meta.coins_received', 100);
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/monetization')->assertOk()->assertJsonPath('data.gifts_received', 1)->assertJsonPath('data.payouts_count', 0)->assertJsonPath('data.accounts.0.balance', 90)->assertJsonPath('data.tax_profiles.0.country_code', 'NG')->assertJsonMissingPath('data.tax_profiles.0.details_encrypted');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio/transactions')->assertOk()->assertJsonPath('data.0.id', $transaction->id)->assertJsonPath('data.0.event_type', 'gift.sent')->assertJsonPath('data.0.amount', 90)->assertJsonPath('data.0.unit', 'PCN')->assertJsonPath('data.0.counterpart_name', 'Listener Ada')->assertJsonMissingPath('data.0.destination_encrypted');
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/studio/transactions/{$transaction->id}")->assertOk()->assertJsonPath('data.id', $transaction->id)->assertJsonPath('data.message', 'Thank you')->assertJsonPath('data.gift_type_name', 'Applause');
        }
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/studio/payout-settings')->assertOk()->assertJsonPath('data.settings.currency', 'NGN')->assertJsonPath('data.settings.schedule', 'monthly')->assertJsonPath('data.settings.minimum_amount', 10000)->assertJsonPath('data.tax_profile.country_code', 'NG')->assertJsonMissingPath('data.tax_profile.details_encrypted');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/payout-methods')->assertOk()->assertJsonPath('data.0.id', $payoutMethod)->assertJsonPath('data.0.label', 'Access Bank')->assertJsonPath('data.0.destination_last_four', '1234')->assertJsonMissing(['destination_encrypted']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reels/drafts', ['caption' => 'Draft reel', 'episode_id' => $episode->id])->assertCreated()->assertJsonPath('data.caption', 'Draft reel')->assertJsonPath('data.episode_id', $episode->id)->assertJsonPath('data.show_id', $show->id)->assertJsonPath('data.state', 'draft');
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/studio/episodes', ['show_id' => $show->id, 'title' => 'Studio draft', 'description' => 'Draft notes'])->assertCreated()->assertJsonPath('data.title', 'Studio draft')->assertJsonPath('data.show_id', $show->id)->assertJsonPath('data.availability', 'draft')->assertJsonMissingPath('data.audio_url');
        $live = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/studio/live', ['title' => 'Studio live', 'show_id' => $show->id])->assertCreated()->assertJsonPath('data.title', 'Studio live')->assertJsonPath('data.state', 'scheduled')->assertJsonPath('data.show_id', $show->id);
        $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/studio/live/'.$live->json('data.id'), ['state' => 'live'])->assertOk()->assertJsonPath('data.state', 'live')->assertJsonPath('data.title', 'Studio live');
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/studio/payout-settings', ['currency' => 'USD'])->assertOk()->assertJsonPath('data.settings.currency', 'USD')->assertJsonPath('data.settings.schedule', 'monthly')->assertJsonMissingPath('data.tax_profile.details_encrypted');
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/studio/payout-settings', ['schedule' => 'weekly', 'minimum_amount' => 5000])->assertOk()->assertJsonPath('data.settings.schedule', 'weekly')->assertJsonPath('data.settings.minimum_amount', 5000);
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/studio/payout-settings', ['country_code' => 'NG', 'tax_details' => ['account_type' => 'Individual', 'legal_name' => 'Ada Owner']])->assertOk()->assertJsonPath('data.tax_profile.country_code', 'NG')->assertJsonMissingPath('data.tax_profile.details_encrypted');
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/studio/payout-settings')->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/studio/payout-settings', ['schedule' => 'daily'])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/search?q='.urlencode((string) $show->rss_url))->assertOk()->assertJsonPath('data.shows.0.id', $show->id);
        $stranger = User::factory()->create();
        CreatorProfile::create(['user_id' => $stranger->id, 'display_name' => 'Stranger']);
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/reels/drafts', ['episode_id' => $episode->id])->assertStatus(422)->assertJsonPath('error.code', 'UNPROCESSABLE');
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/studio/episodes', ['show_id' => $show->id, 'title' => 'Nope'])->assertStatus(422)->assertJsonPath('error.code', 'UNPROCESSABLE');
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio')->assertForbidden()->assertJsonPath('error.code', 'CLAIM_REQUIRED');
        $this->actingAs($outsider, 'sanctum')->postJson('/api/v1/studio/episodes', ['show_id' => $show->id, 'title' => 'Nope'])->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->postJson('/api/v1/studio/live', ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio/shows')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/studio/episodes/{$episode->id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio/reels')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio/transactions')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio/audience/followers')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/studio/audience/supporters')->assertForbidden();
    }

    private function rss(): string
    {
        return '<?xml version="1.0"?><rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>Show</title><itunes:owner><itunes:email>owner@example.com</itunes:email></itunes:owner></channel></rss>';
    }
}
