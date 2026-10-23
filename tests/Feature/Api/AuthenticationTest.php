<?php

namespace Tests\Feature\Api;

use App\Mail\OneTimeCode;
use App\Mail\PelevoNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RegistersAccounts;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use RegistersAccounts;

    public function test_registration_sends_a_code_and_signs_in_only_after_confirmation(): void
    {
        Mail::fake();
        $response = $this->withHeaders(['X-Device-Id' => 'flutter-test-device'])->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Ada phone']);
        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.verification_required', true)
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonMissingPath('data.access_token');
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'email_verified_at' => null]);
        Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->hasTo('ada@example.com') && $mail->purpose === 'email_verification');
        Mail::assertNotSent(PelevoNotice::class);

        $code = Mail::sent(OneTimeCode::class)->first()?->code;
        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'ada@example.com',
            'code' => $code,
            'purpose' => 'email_verification',
            'device_name' => 'Ada phone',
        ]);
        $verified->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'device_id'], 'request_id']);
        $verified->assertJsonPath('data.user.capabilities.creator_access', false);
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $verified->json('data.refresh_token')]);
        $this->assertNotNull(User::where('email', 'ada@example.com')->value('email_verified_at'));
        $verified->assertJsonPath('data.welcome_bonus.granted', true)
            ->assertJsonPath('data.welcome_bonus.coins', 250)
            ->assertJsonPath('data.welcome_bonus.balance', 250);
        $this->assertDatabaseHas('ledger_transactions', ['event_type' => 'welcome.bonus']);
        Mail::assertSent(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo('ada@example.com') && $mail->heading === 'Your African podcast home' && str_contains($mail->intro, '250 welcome coins'));
    }

    public function test_welcome_bonus_stops_after_the_launch_cap(): void
    {
        Mail::fake();
        config(['pelevo.welcome_bonus_cap' => 1]);
        $first = $this->withHeaders(['X-Device-Id' => 'flutter-test-device'])->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Ada phone']);
        $first->assertCreated();
        $code = Mail::sent(OneTimeCode::class)->first()?->code;
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'ada@example.com',
            'code' => $code,
            'purpose' => 'email_verification',
            'device_name' => 'Ada phone',
        ])->assertOk()->assertJsonPath('data.welcome_bonus.coins', 250);

        $this->postJson('/api/v1/auth/register', ['name' => 'Bola', 'email' => 'bola@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Bola phone'])->assertCreated();
        $secondCode = Mail::sent(OneTimeCode::class)->last()?->code;
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'bola@example.com',
            'code' => $secondCode,
            'purpose' => 'email_verification',
            'device_name' => 'Bola phone',
        ])->assertOk()->assertJsonMissingPath('data.welcome_bonus');
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_unverified_password_login_sends_a_new_code_without_a_session(): void
    {
        Mail::fake();
        User::factory()->unverified()->create([
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9!',
            'device_name' => 'Phone',
        ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_UNVERIFIED');

        $this->assertDatabaseCount('refresh_tokens', 0);
        Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->purpose === 'email_verification');
    }

    public function test_validation_uses_stable_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION')->assertJsonPath('ok', false);
    }

    public function test_registration_saves_username_and_rejects_duplicates(): void
    {
        Mail::fake();
        User::factory()->create(['handle' => 'taken_name']);
        $payload = ['name' => 'Ada', 'handle' => 'taken_name', 'email' => 'new-listener@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Phone'];

        $this->postJson('/api/v1/auth/register', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);

        $payload['handle'] = 'new_listener';
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated()->assertJsonPath('data.verification_required', true);
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'handle' => 'new_listener']);
    }

    public function test_mobile_token_reaches_me(): void
    {
        $registration = $this->registerAndVerify(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'phone'], ['X-Device-Id' => 'test-device']);
        $this->withToken($registration->json('data.access_token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    }
}
