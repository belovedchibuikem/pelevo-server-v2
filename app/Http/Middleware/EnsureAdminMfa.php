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
        $valid = is_numeric($verifiedAt) && now()->timestamp - (int) $verifiedAt <= 1800;
        if ($valid) {
            return $next($request);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, 'Multi-factor authentication is required.');
        }

        return redirect()->route('admin.step-up')->with('intended_url', $request->fullUrl());
    }
}
