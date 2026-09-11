<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreatorClaimReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_queue_detail_live_evidence_decision_and_audit_are_complete(): void
    {
        Http::fake(['https://example.com/*' => Http::response('<?xml version="1.0"?><rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><description>PELEVO-VERIFY-CODE</description><itunes:owner><itunes:email>private@example.com</itunes:email></itunes:owner></channel></rss>')]);
        $admin = $this->adminWithClaimPermission();
        [$claimId] = $this->claim('review');
        DB::table('claim_challenges')->insert(['id' => (string) Str::ulid(), 'show_claim_id' => $claimId, 'type' => 'description', 'destination_encrypted' => encrypt('PELEVO-VERIFY-CODE'), 'code_hash' => hash('sha256', 'PELEVO-VERIFY-CODE'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);

        $client->get('/admin/claims')->assertOk();
        $client->getJson('/api/admin/v1/claims')->assertOk()->assertJsonMissing(['destination_encrypted' => encrypt('PELEVO-VERIFY-CODE')]);
        $live = $client->postJson("/api/admin/v1/claims/{$claimId}/live-rss")->assertOk()->assertJsonPath('data.owner_email_masked', 'p******@example.com');
        $this->assertStringNotContainsString('private@example.com', $live->getContent());
        $client->putJson("/api/admin/v1/claims/{$claimId}", ['decision' => 'approved', 'reason' => 'Live RSS code confirmed by operator.'])->assertOk();
        $client->getJson("/api/admin/v1/claims/{$claimId}")->assertOk()->assertJsonCount(1, 'data.reviews')->assertJsonCount(1, 'data.audits');
        $this->assertDatabaseHas('show_claims', ['id' => $claimId, 'state' => 'verified']);
    }

    public function test_admin_cannot_approve_unverified_description_and_duplicate_opens_dispute(): void
    {
        $admin = $this->adminWithClaimPermission();
        [$first, $show] = $this->claim('review');
        [$second] = $this->claim('review', $show);
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $payload = ['decision' => 'approved', 'reason' => 'Operator confirmed the live ownership evidence.'];
        $client->putJson("/api/admin/v1/claims/{$first}", $payload)->assertOk();
        $client->putJson("/api/admin/v1/claims/{$second}", $payload)->assertConflict();
        $this->assertDatabaseHas('claim_disputes', ['show_claim_id' => $second, 'existing_claim_id' => $first]);

        [$pending] = $this->claim('pending');
        $client->putJson("/api/admin/v1/claims/{$pending}", $payload)->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_STATE');
    }

    private function adminWithClaimPermission(): Admin
    {
        $admin = Admin::create(['name' => 'Claims', 'email' => Str::random(8).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'claims-'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'claims.decide')->value('id') ?: DB::table('permissions')->insertGetId(['name' => 'claims.decide', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);

        return $admin;
    }

    private function claim(string $state, ?Show $show = null): array
    {
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => $user->name]);
        $show ??= Show::create(['rss_url' => 'https://example.com/'.Str::random(8).'.xml', 'title' => 'Claimed Show']);
        $id = (string) Str::ulid();
        DB::table('show_claims')->insert(['id' => $id, 'show_id' => $show->id, 'creator_profile_id' => $creator->id, 'method' => 'description', 'state' => $state, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return [$id, $show];
    }
}
