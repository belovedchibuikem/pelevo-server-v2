<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class EnsureAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_superadmin_with_role(): void
    {
        $this->artisan('pelevo:ensure-admin', [
            'email' => 'ops@pelevo.test',
            '--password' => 'Beloved?123?',
            '--name' => 'Ops',
            '--role' => 'superadmin',
        ])->assertSuccessful();

        $admin = Admin::query()->where('email', 'ops@pelevo.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('Beloved?123?', $admin->password));
        $this->assertSame('active', $admin->status);
        $this->assertTrue(DB::table('admin_role')
            ->where('admin_id', $admin->id)
            ->where('role_id', DB::table('roles')->where('name', 'superadmin')->value('id'))
            ->exists());
        $this->assertEqualsCanonicalizing(
            \App\Support\AdminAccess::PERMISSIONS,
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('roles.name', 'superadmin')
                ->pluck('permissions.name')
                ->all(),
        );
    }

    public function test_updates_existing_password(): void
    {
        $admin = Admin::query()->create([
            'email' => 'ops@pelevo.test',
            'name' => 'Ops',
            'password' => 'Old-password-9!',
            'status' => 'active',
        ]);

        $this->artisan('pelevo:ensure-admin', [
            'email' => 'ops@pelevo.test',
            '--password' => 'Pelevo?123?',
            '--name' => 'Pelevo Ops',
        ])->assertSuccessful();

        $admin->refresh();
        $this->assertSame('Pelevo Ops', $admin->name);
        $this->assertTrue(Hash::check('Pelevo?123?', $admin->password));
    }
}
