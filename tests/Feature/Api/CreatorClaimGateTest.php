<?php

namespace Tests\Feature\Api;

use App\Integrations\Rss\RssOwnershipInspector;
use App\Jobs\ExpireClaims;
use App\Jobs\VerifyDescriptionClaim;
use App\Mail\ClaimVerificationCode;
use App\Models\CreatorProfile;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreatorClaimGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_email_owner_is_masked_mailed_attempt_limited_and_never_exposed(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/*' => Http::response($this->rss('owner@example.com'))]);
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/owner.xml', 'title' => 'Owned Show']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/shows/{$show->id}/claims", ['method' => 'email'])
            ->assertCreated()->assertJsonPath('data.masked_destination', 'o****@example.com')->assertJsonPath('data.challenge', null);
        $this->assertStringNotContainsString('owner@example.com', $response->getContent());
        $claimId = $response->json('data.claim_id');
        $challenge = DB::table('claim_challenges')->where('show_claim_id', $claimId)->first();
        $this->assertStringNotContainsString('owner@example.com', $challenge->destination_encrypted);
        Mail::assertQueued(ClaimVerificationCode::class, fn (ClaimVerificationCode $mail) => $mail->hasTo('owner@example.com'));

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user, 'sanctum')->postJson("/api/v1/claims/{$claimId}/verify", ['code' => '000000'])->assertUnprocessable();
        }
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/claims/{$claimId}/verify", ['code' => '000000'])->assertConflict();
        $this->assertDatabaseHas('claim_challenges', ['show_claim_id' => $claimId, 'attempts' => 5]);
    }

    public function test_first_valid_email_claim_wins_and_duplicate_becomes_dispute(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/*' => Http::response($this->rss('owner@example.com'))]);
        $show = Show::create(['rss_url' => 'https://example.com/race.xml', 'title' => 'Race']);
        $users = [User::factory()->create(), User::factory()->create()];
        $claims = [];
        $codes = [];
        foreach ($users as $user) {
            $claims[] = $this->actingAs($user, 'sanctum')->postJson("/api/v1/shows/{$show->id}/claims", ['method' => 'email'])->json('data.claim_id');
        }
        Mail::assertQueued(ClaimVerificationCode::class, 2);
        Mail::assertQueued(ClaimVerificationCode::class, function (ClaimVerificationCode $mail) use (&$codes): bool {
            $codes[] = $mail->code;

            return true;
        });

        $this->actingAs($users[0], 'sanctum')->postJson("/api/v1/claims/{$claims[0]}/verify", ['code' => $codes[0]])->assertOk();
        $this->actingAs($users[1], 'sanctum')->postJson("/api/v1/claims/{$claims[1]}/verify", ['code' => $codes[1]])->assertConflict();
        $this->assertDatabaseCount('verified_show_claims', 1);
        $this->assertDatabaseHas('show_claims', ['id' => $claims[1], 'state' => 'disputed']);
        $this->assertDatabaseHas('claim_disputes', ['show_claim_id' => $claims[1], 'existing_claim_id' => $claims[0], 'state' => 'open']);
    }

    public function test_description_uses_live_uncached_feed_and_requires_manual_review(): void
    {
        Queue::fake();
        Http::fakeSequence()
            ->push($this->rss(null, 'Initial'))
            ->push($this->rss(null, 'Now showing PELEVO-VERIFY-CODE'));
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/live.xml', 'title' => 'Live Feed']);
        $created = $this->actingAs($user, 'sanctum')->postJson("/api/v1/shows/{$show->id}/claims", ['method' => 'description'])->assertCreated();
        $claimId = $created->json('data.claim_id');
        $code = $created->json('data.challenge');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/claims/{$claimId}/description/confirm")->assertAccepted();
        Queue::assertPushed(VerifyDescriptionClaim::class);
        DB::table('claim_challenges')->where('show_claim_id', $claimId)->update([
            'destination_encrypted' => encrypt('PELEVO-VERIFY-CODE'),
            'code_hash' => hash('sha256', 'PELEVO-VERIFY-CODE'),
        ]);
        (new VerifyDescriptionClaim($claimId))->handle(app(RssOwnershipInspector::class));

        $this->assertDatabaseHas('show_claims', ['id' => $claimId, 'state' => 'review', 'verified_at' => null]);
        Http::assertSent(fn ($request) => $request->hasHeader('Cache-Control', 'no-cache') && $request->hasHeader('Pragma', 'no-cache'));
        $evidence = DB::table('show_claims')->where('id', $claimId)->value('live_evidence');
        $this->assertStringNotContainsString('@', $evidence);
    }

    public function test_expiry_job_and_creator_access_refresh_immediately_after_verification(): void
    {
        Mail::fake();
        Http::fake(['https://example.com/*' => Http::response($this->rss('owner@example.com'))]);
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/access.xml', 'title' => 'Access']);
        $claimId = $this->actingAs($user, 'sanctum')->postJson("/api/v1/shows/{$show->id}/claims", ['method' => 'email'])->json('data.claim_id');
        $code = null;
        Mail::assertQueued(ClaimVerificationCode::class, function (ClaimVerificationCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertJsonPath('data.capabilities.creator_access', false);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/claims/{$claimId}/verify", ['code' => $code])->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertJsonPath('data.capabilities.creator_access', true);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/studio')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/shows/{$show->id}")
            ->assertOk()
            ->assertJsonPath('data.claimed_by_viewer', true);
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/shows/{$show->id}")
            ->assertOk()
            ->assertJsonPath('data.claimed_by_viewer', false);
        $studioId = $this->actingAs($user, 'sanctum')->postJson('/api/v1/studios', ['name' => 'Team'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('studio_members', ['studio_id' => $studioId, 'user_id' => $user->id, 'role' => 'owner']);

        $expired = $this->claim($show, User::factory()->create(), now()->subMinute());
        (new ExpireClaims)->handle();
        $this->assertDatabaseHas('show_claims', ['id' => $expired, 'state' => 'expired']);
    }

    private function claim(Show $show, User $user, $expires): string
    {
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => $user->name]);
        $id = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $id, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'email', 'state' => 'pending', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function rss(?string $email, string $description = 'Description'): string
    {
        $owner = $email ? "<itunes:owner><itunes:email>{$email}</itunes:email></itunes:owner>" : '';

        return "<?xml version=\"1.0\"?><rss xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\"><channel><title>Show</title><description>{$description}</description>{$owner}</channel></rss>";
    }
}
