<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $verifiedAt = $request->session()->get('admin_mfa_verified_at');
        abort_unless(is_numeric($verifiedAt) && now()->timestamp - (int) $verifiedAt <= 1800, 403);

        return $next($request);
    }
}
