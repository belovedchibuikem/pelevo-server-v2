<?php

namespace Tests\Feature\Api;

use App\Actions\Identity\OneTimeCodeService;
use App\Jobs\PrepareDataExport;
use App\Jobs\ProcessAccountDeletion;
use App\Mail\OneTimeCode;
use App\Mail\PelevoNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class IdentityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_login_is_attempt_limited_single_use_and_delivered_by_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'listener@example.com']);

        $this->postJson('/api/v1/auth/otp/send', ['email' => $user->email, 'purpose' => 'login'])
            ->assertOk()->assertJsonPath('data.accepted', true);
        Mail::assertQueued(OneTimeCode::class, fn (OneTimeCode $mail): bool => $mail->hasTo($user->email));
        $row = DB::table('one_time_codes')->where('destination', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('000000', $row->code_hash);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => '000000', 'purpose' => 'login', 'device_name' => 'Phone'])->assertUnauthorized();
        }
        $this->assertSame(5, DB::table('one_time_codes')->where('id', $row->id)->value('attempts'));
    }

    public function test_profile_onboarding_and_preferences_use_optimistic_concurrency(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);

        $this->patchJson('/api/v1/me', ['name' => 'Updated', 'bio' => 'A listener', 'timezone' => 'Africa/Lagos'])->assertOk()->assertJsonPath('data.profile.bio', 'A listener');
        $this->putJson('/api/v1/me/onboarding', ['interests' => ['Technology', 'Music']])->assertOk();
        $this->patchJson('/api/v1/me/preferences', ['version' => 0, 'autoplay' => false])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson('/api/v1/me/preferences', ['version' => 0, 'autoplay' => true])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated']);
        $this->assertDatabaseHas('user_interests', ['user_id' => $user->id, 'interest' => 'technology']);
    }

    public function test_disabled_account_cannot_sign_in_with_a_valid_email_code(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $code = app(OneTimeCodeService::class)->issue($user->email, 'login');

        $this->postJson('/api/v1/auth/otp/verify', ['email' => $user->email, 'code' => $code, 'purpose' => 'login', 'device_name' => 'Phone'])->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_disabled_account_cannot_rotate_an_existing_refresh_token(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'disabled@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Phone']);
        User::where('email', 'disabled@example.com')->update(['status' => 'disabled']);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $registration->json('data.refresh_token'), 'device_id' => $registration->json('data.device_id')])->assertUnauthorized();

        $this->assertDatabaseMissing('refresh_tokens', ['device_id' => $registration->json('data.device_id'), 'revoked_at' => null]);
    }

    public function test_logout_revokes_tokens_for_the_external_device_identifier(): void
    {
        $registration = $this->withHeader('X-Device-Id', 'physical-phone')->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'logout@example.com', 'password' => 'Correct-Horse-9!', 'password_confirmation' => 'Correct-Horse-9!', 'device_name' => 'Phone']);
        $deviceId = $registration->json('data.device_id');

        $this->withToken($registration->json('data.access_token'))->withHeader('X-Device-Id', 'physical-phone')->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseMissing('refresh_tokens', ['device_id' => $deviceId, 'revoked_at' => null]);
        $this->assertDatabaseMissing('devices', ['id' => $deviceId, 'revoked_at' => null]);
    }

    public function test_privacy_requests_are_queued_and_jobs_complete_their_lifecycle(): void
    {
        Mail::fake();
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'Correct-Horse-9!']);
        Sanctum::actingAs($user, ['mobile']);

        $export = $this->postJson('/api/v1/me/data-export')->assertAccepted();
        Queue::assertPushed(PrepareDataExport::class);
        (new PrepareDataExport($export->json('data.id')))->handle();
        Storage::disk('local')->assertExists('private/exports/'.$export->json('data.id').'.json');
        $status = $this->getJson('/api/v1/me/data-export/'.$export->json('data.id'))->assertOk();
        $this->get($status->json('data.download_url'))->assertOk()->assertHeader('cache-control', 'no-store, private');
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($user->email) && $mail->heading === 'Download your Pelevo data');

        $deletion = $this->postJson('/api/v1/me/delete', ['password' => 'Correct-Horse-9!', 'reason' => 'Leaving'])->assertAccepted();
        Queue::assertPushed(ProcessAccountDeletion::class);
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($user->email) && $mail->heading === 'We will delete your account in 30 days');
        DB::table('account_deletion_requests')->where('id', $deletion->json('data.id'))->update(['scheduled_for' => now()->subSecond()]);
        (new ProcessAccountDeletion($deletion->json('data.id')))->handle();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'deleted', 'name' => 'Deleted user']);
        $this->assertDatabaseHas('account_deletion_requests', ['id' => $deletion->json('data.id'), 'state' => 'completed']);
        Mail::assertQueued(PelevoNotice::class, fn (PelevoNotice $mail): bool => $mail->hasTo($user->email) && $mail->heading === 'Your Pelevo account is gone');
    }

    public function test_connected_accounts_cannot_be_removed_by_another_user(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $accountId = (string) Str::ulid();
        DB::table('connected_accounts')->insert(['id' => $accountId, 'user_id' => $owner->id, 'provider' => 'google', 'provider_subject' => 'subject', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($attacker, ['mobile']);

        $this->deleteJson('/api/v1/me/connected-accounts/'.$accountId)->assertNotFound();
        $this->assertDatabaseHas('connected_accounts', ['id' => $accountId, 'user_id' => $owner->id]);
    }
}
