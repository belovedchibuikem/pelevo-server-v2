<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\ConfigurationVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ConfigurationWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_configuration_publish_preserves_history_and_rejects_stale_edits(): void
    {
        $this->withoutVite();
        $admin = $this->operator();
        $current = ConfigurationVersion::firstOrFail();
        $payload = $current->payload;
        $payload['limits']['comment_chars'] = 450;
        $data = ['base_version' => 1, 'payload' => $payload, 'effective_at' => now()->addHour()->format('Y-m-d\\TH:i:sP'), 'reason' => 'Adjust comment length prospectively'];
        $this->get('/admin/settings/configuration')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Configuration')->has('versions', 1));
        $this->postJson('/api/admin/v1/configuration-versions', $data)->assertCreated()->assertJsonPath('data.version', 2);
        $this->postJson('/api/admin/v1/configuration-versions', $data)->assertConflict();
        $this->assertSame(400, $current->fresh()->payload['limits']['comment_chars']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.published', 'admin_id' => $admin->id]);
        $data['base_version'] = 2;
        $data['payload']['flags']['unknown_flag'] = true;
        $this->postJson('/api/admin/v1/configuration-versions', $data)->assertUnprocessable();
        $this->withSession(['admin_mfa_verified_at' => now()->subMinutes(6)->timestamp])->postJson('/api/admin/v1/configuration-versions', $data)->assertForbidden();
    }

    private function operator(string $role = 'superadmin'): Admin
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::ulid().'@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);

        return $admin;
    }
}
