<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('admin')->check()) {
            if ($request->is('api/admin/*')) {
                abort(403);
            }

            return redirect()->route('admin.login');
        }
        $tracked = $request->session()->get('admin_tracked_session_id');
        if (is_string($tracked) && ! DB::table('admin_sessions')->where('id', $tracked)->where('admin_id', auth('admin')->id())->whereNull('revoked_at')->exists()) {
            auth('admin')->logout();
            $request->session()->invalidate();
            if ($request->is('api/admin/*')) {
                abort(403);
            }

            return redirect()->route('admin.login')->withErrors(['email' => 'This administrator session has expired or was revoked.']);
        }

        return $next($request);
    }
}
