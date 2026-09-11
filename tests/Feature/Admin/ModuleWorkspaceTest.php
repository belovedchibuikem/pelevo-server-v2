<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ModuleWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_document_module_has_a_real_permission_protected_workspace(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Super Admin', 'email' => 'modules@example.com', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        $role = DB::table('roles')->where('name', 'superadmin')->value('id');
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        $session = ['admin_mfa_verified_at' => now()->timestamp];

        foreach (['users', 'reels-live', 'community', 'communications', 'growth', 'cms', 'ai', 'support', 'analytics', 'settings', 'audit'] as $module) {
            $this->actingAs($admin, 'admin')->withSession($session)->get("/admin/{$module}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/ModuleWorkspace')->where('module', $module)->has('views')->has('records.data')->has('records.columns')->has('freshAt'));
        }
    }

    public function test_user_workspace_filters_safe_columns_without_exposing_email_or_password(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        User::factory()->create(['name' => 'Visible Operator Search', 'email' => 'private@example.test']);
        $admin = Admin::create(['name' => 'Support', 'email' => 'support@example.com', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        $role = DB::table('roles')->where('name', 'support')->value('id');
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->get('/admin/users?view=directory&q=Visible')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeView', 'directory')
                ->where('records.total', 1)
                ->where('records.columns', fn ($columns): bool => $columns->contains('name') && ! $columns->contains('email') && ! $columns->contains('password'))
                ->where('records.data.0.name', 'Visible Operator Search'));
    }

    public function test_module_rejects_unknown_views_and_insufficient_permissions(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::create(['name' => 'Restricted', 'email' => 'restricted@example.com', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        $session = ['admin_mfa_verified_at' => now()->timestamp];

        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/users')->assertForbidden();

        $role = DB::table('roles')->where('name', 'support')->value('id');
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);
        $this->actingAs($admin, 'admin')->withSession($session)->from('/admin/users')->get('/admin/users?view=not-a-real-table')->assertRedirect('/admin/users')->assertSessionHasErrors('view');
    }

    public function test_workspace_supports_shareable_date_sort_and_page_size_filters(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        User::factory()->count(3)->create();
        $admin = Admin::create(['name' => 'Support', 'email' => 'filters@example.com', 'password' => 'Admin-password-9!', 'status' => 'active', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => DB::table('roles')->where('name', 'support')->value('id')]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->get('/admin/users?view=directory&date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString().'&sort=name&direction=asc&per_page=50')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
                ->where('filters.per_page', 50)
                ->where('records.per_page', 50));
    }
}
