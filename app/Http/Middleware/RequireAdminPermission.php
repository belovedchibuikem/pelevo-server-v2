<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(AdminAccess::allows(auth('admin')->user(), $permission), 403);

        return $next($request);
    }
}
