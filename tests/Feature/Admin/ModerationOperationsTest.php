<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModerationOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_appeal_sanction_and_live_takedown_workflows_preserve_evidence_and_audit(): void
    {
        $admin = $this->admin();
        $session = ['admin_mfa_verified_at' => now()->timestamp];
        $client = $this->actingAs($admin, 'admin')->withSession($session);
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        $reel = (string) Str::ulid();
        DB::table('reels')->insert(['id' => $reel, 'creator_profile_id' => $creator->id, 'state' => 'rejected', 'duration_ms' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        $report = (string) Str::ulid();
        DB::table('content_reports')->insert(['id' => $report, 'reporter_id' => $user->id, 'reportable_type' => 'reel', 'reportable_id' => $reel, 'reason' => 'abuse', 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $appeal = (string) Str::ulid();
        DB::table('appeals')->insert(['id' => $appeal, 'user_id' => $user->id, 'subject_type' => 'reel', 'subject_id' => $reel, 'reason' => 'Please review this decision carefully.', 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $live = (string) Str::ulid();
        DB::table('live_sessions')->insert(['id' => $live, 'creator_profile_id' => $creator->id, 'title' => 'Unsafe Live', 'state' => 'live', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $client->getJson("/api/admin/v1/moderation/reels/{$reel}")->assertOk()->assertJsonStructure(['data' => ['reel', 'engagement', 'reports', 'actions', 'processing', 'appeals']]);
        $client->getJson("/api/admin/v1/moderation/reports/{$report}")->assertOk()->assertJsonPath('data.report.reason', 'abuse');
        $client->putJson("/api/admin/v1/moderation/reports/{$report}", ['resolution' => 'actioned', 'reason' => 'The reported policy violation was confirmed.'])->assertOk()->assertJsonStructure(['data' => ['audit_reference']]);
        $client->putJson("/api/admin/v1/moderation/appeals/{$appeal}", ['decision' => 'granted', 'reason' => 'New evidence requires another moderation review.'])->assertOk();
        $this->assertDatabaseHas('reels', ['id' => $reel, 'state' => 'pending_review']);
        $sanction = $client->postJson('/api/admin/v1/moderation/sanctions', ['user_id' => $user->id, 'type' => 'content_restriction', 'reason' => 'Repeated confirmed content policy violations.'])->assertCreated()->json('data.sanction_id');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/comments', [])->assertForbidden();
        $client->getJson("/api/admin/v1/moderation/live/{$live}")->assertOk();
        $client->postJson("/api/admin/v1/moderation/live/{$live}/takedown", ['reason' => 'Immediate safety policy takedown required.'])->assertOk();
        $this->assertDatabaseHas('live_sessions', ['id' => $live, 'state' => 'removed']);
        $this->assertDatabaseHas('user_sanctions', ['id' => $sanction, 'state' => 'active']);
        $this->assertDatabaseCount('audit_logs', 4);
    }

    private function admin(): Admin
    {
        $admin = Admin::create(['name' => 'Moderator', 'email' => Str::random(8).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'mod-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'moderation.act')->value('id') ?: DB::table('permissions')->insertGetId(['name' => 'moderation.act', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);

        return $admin;
    }
}
