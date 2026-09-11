<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnsureAdminCommand extends Command
{
    protected $signature = 'pelevo:ensure-admin
        {email : Admin email address}
        {--password= : Plain-text password (required when creating or resetting)}
        {--name= : Display name}
        {--role=superadmin : Role to attach}';

    protected $description = 'Create or update a Pelevo admin operator and attach an RBAC role (safe for Forge).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = $this->option('password');
        $name = trim((string) ($this->option('name') ?: Str::before($email, '@')));
        $roleName = strtolower(trim((string) $this->option('role')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }

        $this->ensureRolesAndPermissions();

        $admin = Admin::query()->where('email', $email)->first();
        if (! $admin && blank($password)) {
            $this->error(' --password is required when creating a new admin.');

            return self::FAILURE;
        }

        if (! $admin) {
            $admin = Admin::query()->create([
                'email' => $email,
                'name' => $name !== '' ? $name : 'Pelevo Operator',
                'password' => (string) $password,
                'status' => 'active',
            ]);
            $this->info("Created admin {$email}");
        } else {
            $updates = ['status' => 'active'];
            if ($name !== '') {
                $updates['name'] = $name;
            }
            if (filled($password)) {
                $updates['password'] = (string) $password;
            }
            $admin->update($updates);
            $this->info("Updated admin {$email}");
        }

        $roleId = DB::table('roles')->where('name', $roleName)->value('id');
        if (! $roleId) {
            $this->error("Role [{$roleName}] does not exist.");

            return self::FAILURE;
        }

        DB::table('admin_role')->insertOrIgnore([
            'admin_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        $this->line("Attached role [{$roleName}]");
        $this->line('Login: '.rtrim((string) config('app.url'), '/').'/admin/login');
        $this->warn('First sign-in will require authenticator MFA enrolment.');

        return self::SUCCESS;
    }

    private function ensureRolesAndPermissions(): void
    {
        foreach (['superadmin', 'support', 'moderator', 'finance', 'catalog_editor', 'analyst'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissions = [
            'users.view', 'users.suspend', 'catalog.write', 'claims.decide', 'moderation.act',
            'finance.view', 'finance.adjust', 'payouts.approve', 'settings.write', 'broadcast.send',
            'audit.view', 'ai.manage', 'operations.manage',
        ];
        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }

        $matrix = [
            'support' => ['users.view'],
            'moderator' => ['moderation.act'],
            'catalog_editor' => ['catalog.write', 'claims.decide'],
            'finance' => ['finance.view', 'finance.adjust', 'payouts.approve'],
            'analyst' => [],
            'superadmin' => $permissions,
        ];

        foreach ($matrix as $role => $rolePermissions) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            foreach ($rolePermissions as $permission) {
                $permissionId = DB::table('permissions')->where('name', $permission)->value('id');
                if ($roleId && $permissionId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }
}
