<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class AdminAccountController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Admin/Profile', ['admin' => $request->user('admin')->only('id', 'name', 'email', 'notification_preferences'), 'sessions' => DB::table('admin_sessions')->where('admin_id', $request->user('admin')->id)->whereNull('revoked_at')->latest('last_seen_at')->get(), 'currentSessionId' => $request->session()->get('admin_tracked_session_id', $request->session()->getId())]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'current_password' => ['nullable', 'required_with:password', 'current_password:admin'], 'password' => ['nullable', 'confirmed', Password::defaults()], 'notification_preferences' => ['required', 'array'], 'notification_preferences.incidents' => ['required', 'boolean'], 'notification_preferences.tasks' => ['required', 'boolean']]);
        $request->user('admin')->update(['name' => $data['name'], 'notification_preferences' => $data['notification_preferences'], ...isset($data['password']) ? ['password' => $data['password']] : []]);

        return back()->with('success', 'Profile updated.');
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        DB::table('admin_sessions')->where('id', $session)->where('admin_id', $request->user('admin')->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($session === $request->session()->get('admin_tracked_session_id', $request->session()->getId())) {
            auth('admin')->logout();
            $request->session()->invalidate();

            return to_route('admin.login');
        }

        return back();
    }
}
