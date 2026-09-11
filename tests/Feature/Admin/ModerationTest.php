<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requires_permission_and_server_probed_duration_before_publish(): void
    {
        $admin = Admin::create(['name' => 'Moderator', 'email' => 'mod@example.com', 'password' => 'password', 'status' => 'active']);
        $user = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $user->id, 'display_name' => 'Creator']);
        $reelId = (string) Str::ulid();
        $uploadId = (string) Str::ulid();
        DB::table('media_uploads')->insert(['id' => $uploadId, 'user_id' => $user->id, 'disk' => 'local', 'path' => 'test', 'expected_mime' => 'video/mp4', 'expected_size' => 1, 'actual_size' => 1, 'state' => 'processed', 'probe' => json_encode(['duration_ms' => 61000]), 'expires_at' => now()->addHour(), 'uploaded_at' => now(), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reels')->insert(['id' => $reelId, 'creator_profile_id' => $creator->id, 'state' => 'pending_review', 'duration_ms' => 61000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_media')->insert(['id' => (string) Str::ulid(), 'reel_id' => $reelId, 'media_upload_id' => $uploadId, 'mime' => 'video/mp4', 'duration_ms' => 61000, 'processing_state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['action' => 'publish', 'reason_code' => 'approved', 'reason' => 'Content passed policy review.'];

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->putJson("/api/admin/v1/reels/{$reelId}/moderation", $payload)->assertForbidden();
        $role = DB::table('roles')->insertGetId(['name' => 'moderator-test', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->insertGetId(['name' => 'moderation.act', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->get('/admin/moderation')->assertOk();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->getJson('/api/admin/v1/moderation')->assertOk();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->putJson("/api/admin/v1/reels/{$reelId}/moderation", $payload)->assertUnprocessable()->assertJsonPath('error.code', 'UPLOAD_TOO_LONG');
        DB::table('reels')->where('id', $reelId)->update(['duration_ms' => 60000]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->putJson("/api/admin/v1/reels/{$reelId}/moderation", $payload)->assertOk()->assertJsonStructure(['data' => ['audit_reference']]);
        $this->assertDatabaseHas('reels', ['id' => $reelId, 'state' => 'published']);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->putJson("/api/admin/v1/reels/{$reelId}/moderation", [...$payload, 'action' => 'remove', 'reason' => 'Removed after a subsequent policy violation.'])->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/reels/{$reelId}")->assertNotFound();
        $this->assertDatabaseCount('moderation_actions', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }
}
