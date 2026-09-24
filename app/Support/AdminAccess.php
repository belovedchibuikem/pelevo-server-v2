<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdminAccess
{
    public const ROLES = ['superadmin', 'support', 'moderator', 'finance', 'catalog_editor', 'analyst'];

    public const PERMISSIONS = [
        'users.view',
        'users.suspend',
        'catalog.write',
        'claims.decide',
        'moderation.act',
        'finance.view',
        'finance.adjust',
        'payouts.approve',
        'settings.write',
        'broadcast.send',
        'audit.view',
        'ai.manage',
        'operations.manage',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        return [
            'support' => ['users.view', 'moderation.act'],
            'moderator' => ['moderation.act'],
            'catalog_editor' => ['catalog.write', 'claims.decide', 'moderation.act'],
            'finance' => ['finance.view', 'finance.adjust', 'payouts.approve', 'moderation.act'],
            'analyst' => ['moderation.act'],
            'superadmin' => self::PERMISSIONS,
        ];
    }

    public static function ensureRbac(): void
    {
        foreach (self::ROLES as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (self::roleMatrix() as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            foreach ($permissions as $permission) {
                $permissionId = DB::table('permissions')->where('name', $permission)->value('id');
                if ($roleId && $permissionId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
        self::syncSuperadminPermissions();
        self::grantModeratorToAllAdmins();
    }

    public static function grantModeratorToAllAdmins(): void
    {
        $moderatorId = DB::table('roles')->where('name', 'moderator')->value('id');
        if (! $moderatorId) {
            return;
        }
        foreach (DB::table('admins')->where('status', 'active')->pluck('id') as $adminId) {
            if (! is_string($adminId) || $adminId === '') {
                continue;
            }
            DB::table('admin_role')->insertOrIgnore([
                'admin_id' => $adminId,
                'role_id' => $moderatorId,
            ]);
        }
    }

    public static function syncSuperadminPermissions(): void
    {
        $roleId = DB::table('roles')->where('name', 'superadmin')->value('id');
        if (! $roleId) {
            return;
        }
        foreach (DB::table('permissions')->pluck('id') as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public static function attachRole(string $adminId, string $roleName = 'superadmin'): void
    {
        $roleId = DB::table('roles')->where('name', $roleName)->value('id');
        if (! $roleId) {
            return;
        }
        DB::table('admin_role')->insertOrIgnore([
            'admin_id' => $adminId,
            'role_id' => $roleId,
        ]);
        if ($roleName === 'superadmin') {
            self::syncSuperadminPermissions();
        }
        if ($roleName !== 'moderator') {
            $moderatorId = DB::table('roles')->where('name', 'moderator')->value('id');
            if ($moderatorId) {
                DB::table('admin_role')->insertOrIgnore([
                    'admin_id' => $adminId,
                    'role_id' => $moderatorId,
                ]);
            }
        }
    }

    public static function isSuperadmin(?Admin $admin): bool
    {
        if ($admin === null) {
            return false;
        }

        return DB::table('admin_role')
            ->join('roles', 'roles.id', '=', 'admin_role.role_id')
            ->where('admin_role.admin_id', $admin->id)
            ->where('roles.name', 'superadmin')
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public static function permissions(?Admin $admin): Collection
    {
        if ($admin === null) {
            return collect();
        }
        if (self::isSuperadmin($admin)) {
            return collect(self::PERMISSIONS)
                ->merge(DB::table('permissions')->pluck('name'))
                ->unique()
                ->sort()
                ->values();
        }

        return DB::table('admin_role')
            ->join('permission_role', 'permission_role.role_id', '=', 'admin_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('admin_role.admin_id', $admin->id)
            ->distinct()
            ->orderBy('permissions.name')
            ->pluck('permissions.name');
    }

    public static function allows(?Admin $admin, string $permission): bool
    {
        if ($admin === null) {
            return false;
        }
        if (self::isSuperadmin($admin)) {
            return true;
        }

        return self::permissions($admin)->contains($permission);
    }

    public static function allowsId(?string $adminId, string $permission): bool
    {
        if ($adminId === null || $adminId === '') {
            return false;
        }
        $admin = Admin::query()->find($adminId);
        if ($admin === null || $admin->status !== 'active') {
            return false;
        }

        return self::allows($admin, $permission);
    }
}
