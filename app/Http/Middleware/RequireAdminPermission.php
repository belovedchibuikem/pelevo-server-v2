<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin && DB::table('admin_role')->join('permission_role', 'permission_role.role_id', '=', 'admin_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('admin_role.admin_id', $admin->id)->where('permissions.name', $permission)->exists(), 403);

        return $next($request);
    }
}
