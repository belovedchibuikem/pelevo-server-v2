<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class UserOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_360_masks_identity_and_exposes_governed_context(): void
    {
        $this->withoutVite();
        [$admin, $user] = $this->actors();

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->get("/admin/users/{$user->id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/UserDetail')->where('user.email', 'p******@example.test')->where('user.phone', '*********4567')->has('devices')->has('accounts')->has('sanctions')->has('support')->has('privacy.exports')->has('privacy.deletions')->has('audit'));
    }

    public function test_disabling_requires_confirmation_and_revokes_access_with_audit(): void
    {
        [$admin, $user] = $this->actors(true);
        $session = ['admin_mfa_verified_at' => now()->timestamp];

        $this->actingAs($admin, 'admin')->withSession($session)->putJson("/api/admin/v1/users/{$user->id}/access", ['action' => 'disable', 'reason' => 'Confirmed account takeover investigation.', 'confirmation' => 'wrong'])->assertStatus(422)->assertJsonPath('error.code', 'CONFIRMATION_MISMATCH');
        $this->actingAs($admin, 'admin')->withSession($session)->putJson("/api/admin/v1/users/{$user->id}/access", ['action' => 'disable', 'reason' => 'Confirmed account takeover investigation.', 'confirmation' => $user->handle])->assertOk()->assertJsonStructure(['data' => ['audit_reference']]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'disabled']);
        $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->assertDatabaseMissing('devices', ['user_id' => $user->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $user->id, 'action' => 'user.disable']);
    }

    private function actors(bool $canSuspend = false): array
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create(['name' => 'Private Person', 'email' => 'private@example.test', 'phone' => '+234801234567', 'handle' => 'private-person']);
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::random(8).'@example.com', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        $role = DB::table('roles')->where('name', $canSuspend ? 'superadmin' : 'support')->value('id');
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        if ($canSuspend) {
            $device = (string) Str::ulid();
            DB::table('devices')->insert(['id' => $device, 'user_id' => $user->id, 'device_identifier' => 'device-hash', 'name' => 'Phone', 'platform' => 'android', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('refresh_tokens')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'device_id' => $device, 'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$admin, $user];
    }
}
