<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $admin = Admin::where('email', strtolower($data['email']))->where('status', 'active')->first();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['email' => 'The supplied credentials are invalid.'])->onlyInput('email');
        }
        $request->session()->put('pending_admin_id', $admin->id);
        $request->session()->regenerate();

        return to_route($admin->mfa_confirmed_at ? 'admin.mfa.create' : 'admin.mfa.enrol');
    }

    public function enrol(Request $request): Response
    {
        $admin = Admin::findOrFail($request->session()->get('pending_admin_id'));
        abort_if($admin->mfa_confirmed_at, 404);
        if (! $admin->mfa_secret) {
            $admin->update(['mfa_secret' => Totp::generateSecret()]);
        }

        return Inertia::render('Admin/MfaEnrol', ['secret' => $admin->mfa_secret, 'provisioningUri' => 'otpauth://totp/Pelevo:'.rawurlencode($admin->email).'?secret='.$admin->mfa_secret.'&issuer=Pelevo']);
    }

    public function confirmEnrolment(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = Admin::findOrFail($request->session()->get('pending_admin_id'));
        abort_if($admin->mfa_confirmed_at || ! $admin->mfa_secret, 403);
        if (! Totp::verify($admin->mfa_secret, $data['code'])) {
            return back()->withErrors(['code' => 'The authentication code is invalid.']);
        }
        $codes = collect(range(1, 8))->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)));
        DB::transaction(function () use ($admin, $codes): void {
            $admin->update(['mfa_confirmed_at' => now()]);
            DB::table('admin_recovery_codes')->insert($codes->map(fn (string $code): array => ['id' => (string) Str::ulid(), 'admin_id' => $admin->id, 'code_hash' => hash('sha256', $code), 'created_at' => now(), 'updated_at' => now()])->all());
        });
        $request->session()->flash('recovery_codes', $codes->all());

        return to_route('admin.mfa.recovery');
    }

    public function recovery(Request $request): Response
    {
        abort_unless($request->session()->has('recovery_codes'), 404);

        return Inertia::render('Admin/MfaRecovery', ['codes' => $request->session()->get('recovery_codes')]);
    }

    public function stepUp(): Response
    {
        return Inertia::render('Admin/StepUp');
    }

    public function verifyStepUp(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = $request->user('admin');
        if (! $admin->mfa_secret || ! Totp::verify($admin->mfa_secret, $data['code'])) {
            return back()->withErrors(['code' => 'The authentication code is invalid.']);
        }
        $request->session()->put('admin_mfa_verified_at', now()->timestamp);
        DB::table('admin_sessions')->where('id', $request->session()->get('admin_tracked_session_id', $request->session()->getId()))->update(['mfa_verified_at' => now(), 'last_seen_at' => now(), 'updated_at' => now()]);

        return redirect($request->session()->pull('intended_url', route('admin.dashboard')));
    }

    public function challenge(): Response
    {
        abort_unless(session()->has('pending_admin_id'), 403);

        return Inertia::render('Admin/Mfa');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $admin = Admin::findOrFail($request->session()->get('pending_admin_id'));
        $validTotp = preg_match('/^\d{6}$/', $data['code']) && $admin->mfa_secret && Totp::verify($admin->mfa_secret, $data['code']);
        $recovery = DB::table('admin_recovery_codes')->where('admin_id', $admin->id)->where('code_hash', hash('sha256', Str::upper($data['code'])))->whereNull('used_at')->first();
        if (! $validTotp && ! $recovery) {
            return back()->withErrors(['code' => 'The authentication code is invalid.']);
        }
        if ($recovery) {
            DB::table('admin_recovery_codes')->where('id', $recovery->id)->update(['used_at' => now(), 'updated_at' => now()]);
        }
        Auth::guard('admin')->login($admin);
        $request->session()->forget('pending_admin_id');
        $request->session()->put('admin_mfa_verified_at', now()->timestamp);
        $request->session()->regenerate();
        $trackedSessionId = $request->session()->getId();
        $request->session()->put('admin_tracked_session_id', $trackedSessionId);
        DB::table('admin_sessions')->updateOrInsert(['id' => $trackedSessionId], ['admin_id' => $admin->id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'mfa_verified_at' => now(), 'last_seen_at' => now(), 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        return to_route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::table('admin_sessions')->where('id', $request->session()->get('admin_tracked_session_id', $request->session()->getId()))->update(['revoked_at' => now(), 'updated_at' => now()]);
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('admin.login');
    }
}
