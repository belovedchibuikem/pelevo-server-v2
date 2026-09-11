<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFreshAdminMfa
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $verifiedAt = $request->session()->get('admin_mfa_verified_at');
        if (! is_numeric($verifiedAt) || now()->timestamp - (int) $verifiedAt > 300) {
            if ($request->is('api/*') || $request->expectsJson()) {
                abort(403, 'Fresh multi-factor authentication is required.');
            }

            return redirect()->route('admin.step-up')->with('intended_url', $request->fullUrl());
        }

        return $next($request);
    }
}
