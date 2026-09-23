<?php

namespace Tests\Feature\Admin;

use App\Mail\AdminPasswordReset;
use App\Models\Admin;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_requires_password_then_mfa(): void
    {
        $this->withoutVite();
        $secret = 'JBSWY3DPEHPK3PXP';
        $admin = Admin::create(['name' => 'Operator', 'email' => 'ops@example.com', 'password' => 'Admin-password-9!', 'mfa_secret' => $secret, 'mfa_confirmed_at' => now()]);
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-password-9!'])->assertRedirect('/admin/mfa');
        $this->post('/admin/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }

    public function test_mobile_identity_cannot_open_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_authenticated_admin_visiting_login_returns_to_admin_dashboard(): void
    {
        $admin = Admin::create([
            'name' => 'Operator',
            'email' => 'authenticated-ops@example.com',
            'password' => 'Admin-password-9!',
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/login')
            ->assertRedirect('/admin');
    }

    public function test_failed_admin_login_shows_inertia_errors(): void
    {
        $this->withoutVite();
        $this->from('/admin/login')
            ->post('/admin/login', ['email' => 'missing@example.com', 'password' => 'not-the-password'])
            ->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Login')
            ->where('errors.email', 'The supplied credentials are invalid.'));
    }

    public function test_admin_without_mfa_enrols_and_receives_hashed_recovery_codes(): void
    {
        $this->withoutVite();
        $admin = Admin::create(['name' => 'New Operator', 'email' => 'new-ops@example.com', 'password' => 'Admin-password-9!']);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-password-9!'])->assertRedirect('/admin/mfa/enrol');
        $this->get('/admin/mfa/enrol')->assertOk();
        $secret = $admin->fresh()->mfa_secret;
        $this->post('/admin/mfa/enrol', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect('/admin/mfa/recovery');
        $this->assertNotNull($admin->fresh()->mfa_confirmed_at);
        $this->assertSame(8, DB::table('admin_recovery_codes')->where('admin_id', $admin->id)->count());
        $this->assertSame(64, strlen(DB::table('admin_recovery_codes')->where('admin_id', $admin->id)->value('code_hash')));
    }

    public function test_recovery_code_is_single_use_and_admin_session_is_tracked(): void
    {
        $this->withoutVite();
        $admin = Admin::create(['name' => 'Operator', 'email' => 'recovery@example.com', 'password' => 'Admin-password-9!', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        $code = 'ABCDE-12345';
        DB::table('admin_recovery_codes')->insert(['id' => (string) Str::ulid(), 'admin_id' => $admin->id, 'code_hash' => hash('sha256', $code), 'created_at' => now(), 'updated_at' => now()]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-password-9!']);
        $this->post('/admin/mfa', ['code' => $code])->assertRedirect('/admin');
        $this->assertDatabaseCount('admin_sessions', 1);
        $this->assertNotNull(DB::table('admin_recovery_codes')->where('admin_id', $admin->id)->value('used_at'));
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertNotNull(DB::table('admin_sessions')->where('admin_id', $admin->id)->value('revoked_at'));

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-password-9!']);
        $this->post('/admin/mfa', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_password_recovery_is_non_enumerating_single_use_and_revokes_sessions(): void
    {
        $this->withoutVite();
        Mail::fake();
        $admin = Admin::create(['name' => 'Operator', 'email' => 'reset@example.com', 'password' => 'Admin-password-9!', 'mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()]);
        DB::table('admin_sessions')->insert(['id' => 'existing-session', 'admin_id' => $admin->id, 'mfa_verified_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $known = $this->post('/admin/forgot-password', ['email' => $admin->email])->assertSessionHas('status');
        $unknown = $this->post('/admin/forgot-password', ['email' => 'missing@example.com'])->assertSessionHas('status');
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        $token = null;
        Mail::assertSent(AdminPasswordReset::class, function (AdminPasswordReset $mail) use (&$token, $admin): bool {
            $token = $mail->token;

            return $mail->hasTo($admin->email);
        });
        $this->assertIsString($token);
        $this->post('/admin/reset-password', ['token' => $token, 'password' => 'New-Admin-password-9!', 'password_confirmation' => 'New-Admin-password-9!'])->assertRedirect('/admin/login');
        $this->assertTrue(Hash::check('New-Admin-password-9!', $admin->fresh()->password));
        $this->assertNotNull(DB::table('admin_sessions')->where('id', 'existing-session')->value('revoked_at'));
        $this->post('/admin/reset-password', ['token' => $token, 'password' => 'Another-password-9!', 'password_confirmation' => 'Another-password-9!'])->assertSessionHasErrors('token');
    }

    public function test_step_up_refreshes_mfa_time_and_revoked_tracked_session_is_rejected(): void
    {
        $this->withoutVite();
        $secret = 'JBSWY3DPEHPK3PXP';
        $admin = Admin::create(['name' => 'Operator', 'email' => 'step-up@example.com', 'password' => 'Admin-password-9!', 'mfa_secret' => $secret, 'mfa_confirmed_at' => now()]);
        DB::table('admin_sessions')->insert(['id' => 'tracked-session', 'admin_id' => $admin->id, 'mfa_verified_at' => now()->subMinutes(10), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->subMinutes(10)->timestamp, 'admin_tracked_session_id' => 'tracked-session']);
        $client->get('/admin/step-up')->assertOk();
        $client->post('/admin/step-up', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect('/admin');
        $this->assertTrue(Carbon::parse(DB::table('admin_sessions')->where('id', 'tracked-session')->value('mfa_verified_at'))->isAfter(now()->subMinute()));

        DB::table('admin_sessions')->where('id', 'tracked-session')->update(['revoked_at' => now()]);
        $client->get('/admin')->assertRedirect('/admin/login')->assertSessionHasErrors('email');
    }
}
