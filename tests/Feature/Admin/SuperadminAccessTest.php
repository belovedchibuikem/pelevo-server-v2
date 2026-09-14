<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Support\AdminAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SuperadminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_keeps_full_access_even_when_role_matrix_is_incomplete(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::query()->create([
            'name' => 'Root',
            'email' => 'root@pelevo.test',
            'password' => 'Admin-password-9!',
            'status' => 'active',
        ]);
        $roleId = DB::table('roles')->where('name', 'superadmin')->value('id');
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $roleId]);
        DB::table('permission_role')->where('role_id', $roleId)->delete();

        $this->assertTrue(AdminAccess::isSuperadmin($admin));
        $this->assertTrue(AdminAccess::allows($admin, 'settings.write'));
        $this->assertTrue(AdminAccess::allows($admin, 'finance.view'));
        $this->assertTrue(AdminAccess::allows($admin, 'operations.manage'));

        $session = ['admin_mfa_verified_at' => now()->timestamp];
        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/catalog')->assertOk();
        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/finance')->assertOk();
        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/settings')->assertOk();
        $this->actingAs($admin, 'admin')->withSession($session)->get('/admin/operations')->assertOk();
    }

    public function test_support_role_is_still_limited(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $admin = Admin::query()->create([
            'name' => 'Helpdesk',
            'email' => 'support@pelevo.test',
            'password' => 'Admin-password-9!',
            'status' => 'active',
        ]);
        AdminAccess::attachRole($admin->id, 'support');

        $this->assertFalse(AdminAccess::allows($admin, 'finance.view'));
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->get('/admin/finance')
            ->assertForbidden();
    }
}
