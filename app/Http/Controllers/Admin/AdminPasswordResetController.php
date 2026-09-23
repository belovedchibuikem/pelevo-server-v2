<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminPasswordReset;
use App\Models\Admin;
use App\Services\MailPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class AdminPasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return Inertia::render('Admin/ForgotPassword');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $admin = Admin::where('email', Str::lower($data['email']))->where('status', 'active')->first();
        if ($admin) {
            DB::table('admin_password_reset_tokens')->where('admin_id', $admin->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            $token = Str::random(80);
            DB::table('admin_password_reset_tokens')->insert(['id' => (string) Str::ulid(), 'admin_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
            app(MailPreference::class)->queueTransactional($admin->email, new AdminPasswordReset($token));
        }

        return back()->with('status', 'If an active administrator account matches, a reset link has been sent.');
    }

    public function resetForm(string $token): Response
    {
        return Inertia::render('Admin/ResetPassword', ['token' => $token]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        $valid = DB::transaction(function () use ($data): bool {
            $row = DB::table('admin_password_reset_tokens')->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $row || $row->consumed_at || $row->attempts >= $row->max_attempts || now()->isAfter($row->expires_at)) {
                return false;
            }
            Admin::findOrFail($row->admin_id)->update(['password' => $data['password']]);
            DB::table('admin_password_reset_tokens')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('admin_sessions')->where('admin_id', $row->admin_id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

            return true;
        });
        if (! $valid) {
            return back()->withErrors(['token' => 'This password reset link is invalid or expired.']);
        }

        return to_route('admin.login')->with('status', 'Password reset. Sign in and complete MFA.');
    }
}
