<?php

namespace Tests\Feature\Admin;

use App\Jobs\GenerateAdminExport;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_views_are_private_or_shared_but_only_the_owner_can_delete(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = $this->operator();
        $second = $this->operator();
        $this->actingAs($first, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $private = $this->postJson('/api/admin/v1/workspaces/users/views', ['name' => 'Private', 'shared' => false, 'filters' => ['view' => 'directory']])->assertCreated()->json('data.id');
        $shared = $this->postJson('/api/admin/v1/workspaces/users/views', ['name' => 'Team', 'shared' => true, 'layout' => ['columns' => ['name', 'id'], 'compact' => true], 'filters' => ['view' => 'directory', 'state' => 'active']])->assertCreated()->json('data.id');
        $this->actingAs($second, 'admin')->getJson('/api/admin/v1/workspaces/users/tools')->assertOk()->assertJsonCount(1, 'data.views')->assertJsonPath('data.views.0.id', $shared);
        $this->deleteJson('/api/admin/v1/workspaces/users/views/'.$private)->assertNotFound();
        $this->deleteJson('/api/admin/v1/workspaces/users/views/'.$shared)->assertNotFound();
        $this->getJson('/api/admin/v1/workspaces/users/tools')->assertJsonPath('data.views.0.filters.layout.columns', ['name', 'id'])->assertJsonPath('data.views.0.filters.layout.compact', true);
        $this->postJson('/api/admin/v1/workspaces/users/views', ['name' => 'Invalid', 'shared' => true, 'layout' => ['columns' => ['name'], 'compact' => true, 'secret' => 'no'], 'filters' => ['view' => 'directory']])->assertUnprocessable();
        $this->getJson('/api/admin/v1/workspaces/settings/tools')->assertForbidden();
    }

    public function test_export_is_queued_filtered_private_audited_safe_and_expiring(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $admin = $this->operator();
        User::factory()->create(['name' => '=Export Formula', 'status' => 'active']);
        User::factory()->create(['name' => 'Other Person', 'status' => 'active']);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $id = $this->postJson('/api/admin/v1/workspaces/users/exports', ['reason' => 'Review filtered account records', 'filters' => ['view' => 'directory', 'q' => 'Export Formula']])->assertAccepted()->json('data.id');
        Queue::assertPushed(GenerateAdminExport::class, fn (GenerateAdminExport $job): bool => $job->connection === null && $job->queue === 'admin-exports');
        (new GenerateAdminExport($id))->handle();
        (new GenerateAdminExport($id))->handle();
        $this->assertDatabaseHas('admin_exports', ['id' => $id, 'row_count' => 1, 'state' => 'completed']);
        $csv = Storage::disk('local')->get('admin-exports/'.$id.'.csv');
        $this->assertStringContainsString("'=Export Formula", $csv);
        $this->assertStringNotContainsString('Other Person', $csv);
        $this->assertStringNotContainsString('password', $csv);
        $this->get('/api/admin/v1/workspaces/users/exports/'.$id)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $id, 'action' => 'export.downloaded']);
        $this->actingAs($this->operator(), 'admin')->getJson('/api/admin/v1/workspaces/users/exports/'.$id)->assertNotFound();
        $this->actingAs($admin, 'admin');
        DB::table('admin_exports')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/admin/v1/workspaces/users/exports/'.$id)->assertGone();
    }

    public function test_export_rechecks_revoked_permission_and_rejects_unapproved_filters(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = $this->operator();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->postJson('/api/admin/v1/workspaces/users/exports', ['reason' => 'Invalid field access test', 'filters' => ['view' => 'directory', 'table' => 'admins']])->assertUnprocessable();
        $id = $this->postJson('/api/admin/v1/workspaces/users/exports', ['reason' => 'Review account state evidence', 'filters' => ['view' => 'directory']])->assertAccepted()->json('data.id');
        DB::table('admin_role')->where('admin_id', $admin->id)->delete();
        (new GenerateAdminExport($id))->handle();
        $this->assertDatabaseHas('admin_exports', ['id' => $id, 'state' => 'expired']);
    }

    private function operator(): Admin
    {
        $admin = Admin::create(['name' => 'Operator', 'email' => Str::ulid().'@example.test', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'support')->value('id')]);

        return $admin;
    }
}
