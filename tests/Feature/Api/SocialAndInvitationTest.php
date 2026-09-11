<?php

namespace Tests\Feature\Api;

use App\Contracts\SocialIdentityVerifier;
use App\Data\SocialIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SocialAndInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_social_identity_creates_account_and_issues_session(): void
    {
        $this->app->instance(SocialIdentityVerifier::class, new class implements SocialIdentityVerifier
        {
            public function verify(string $provider, string $token): ?SocialIdentity
            {
                return new SocialIdentity('google-subject', 'social@example.com', true, 'Social Listener');
            }
        });

        $response = $this->withHeader('X-Device-Id', 'social-device')->postJson('/api/v1/auth/social', ['provider' => 'google', 'token' => 'provider-token', 'device_name' => 'Phone']);

        $response->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'device_id']]);
        $this->assertDatabaseHas('users', ['email' => 'social@example.com', 'name' => 'Social Listener']);
        $this->assertDatabaseHas('connected_accounts', ['provider' => 'google', 'provider_subject' => 'google-subject']);
    }

    public function test_unverified_new_social_email_is_rejected_without_creating_user(): void
    {
        $this->app->instance(SocialIdentityVerifier::class, new class implements SocialIdentityVerifier
        {
            public function verify(string $provider, string $token): ?SocialIdentity
            {
                return new SocialIdentity('unverified-subject', 'unverified@example.com', false);
            }
        });

        $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'token' => 'provider-token', 'device_name' => 'Phone'])->assertUnprocessable()->assertJsonPath('error.code', 'SOCIAL_EMAIL_UNVERIFIED');
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_connected_accounts_list_is_owner_scoped_and_public(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = (string) Str::ulid();
        DB::table('connected_accounts')->insert([
            'id' => $id,
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_subject' => 'secret-subject',
            'email' => 'google@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('connected_accounts')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $other->id,
            'provider' => 'apple',
            'provider_subject' => 'other-subject',
            'email' => 'other@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['mobile']);
        $response = $this->getJson('/api/v1/me/connected-accounts')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.provider', 'google')
            ->assertJsonPath('data.0.email', 'google@example.com')
            ->assertJsonPath('meta.user_id', $owner->id)
            ->assertJsonMissing(['provider_subject' => 'secret-subject'])
            ->assertJsonMissing(['email' => 'other@example.com']);
        $this->assertSame(['id', 'provider', 'email', 'created_at'], array_keys($response->json('data.0')));
        $this->assertCount(1, $response->json('data'));
        $this->postJson('/api/v1/me/connected-accounts', ['provider' => 'facebook', 'token' => 'token'])->assertUnprocessable();
        $this->deleteJson('/api/v1/me/connected-accounts/'.$id)->assertOk()->assertJsonPath('data.disconnected', true);
        $this->getJson('/api/v1/me/connected-accounts')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.user_id', $owner->id);
    }

    public function test_connected_accounts_require_authentication(): void
    {
        $this->getJson('/api/v1/me/connected-accounts')->assertUnauthorized();
        $this->postJson('/api/v1/me/connected-accounts', ['provider' => 'google', 'token' => 'token'])->assertUnauthorized();
    }

    public function test_provider_identity_cannot_be_linked_to_two_users(): void
    {
        $this->app->instance(SocialIdentityVerifier::class, new class implements SocialIdentityVerifier
        {
            public function verify(string $provider, string $token): ?SocialIdentity
            {
                return new SocialIdentity('owned-subject', 'owner@example.com', true);
            }
        });
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        DB::table('connected_accounts')->insert(['id' => (string) Str::ulid(), 'user_id' => $owner->id, 'provider' => 'google', 'provider_subject' => 'owned-subject', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($attacker, ['mobile']);

        $this->postJson('/api/v1/me/connected-accounts', ['provider' => 'google', 'token' => 'provider-token'])->assertConflict()->assertJsonPath('error.code', 'SOCIAL_ACCOUNT_CONFLICT');
    }

    public function test_invitation_can_be_rotated_and_redeemed_only_once_per_user(): void
    {
        $inviter = User::factory()->create();
        Sanctum::actingAs($inviter, ['mobile']);
        $this->getJson('/api/v1/me/invite')->assertOk()->assertJsonPath('data.user_id', $inviter->id)->assertJsonPath('data.code', null);
        $this->assertSame(['user_id', 'code', 'expires_at', 'redemptions', 'max_redemptions'], array_keys($this->getJson('/api/v1/me/invite')->json('data')));
        $first = $this->postJson('/api/v1/me/invite')->assertCreated();
        $this->assertSame(['user_id', 'code', 'expires_at', 'redemptions', 'max_redemptions'], array_keys($first->json('data')));
        $this->getJson('/api/v1/me/invite')->assertOk()->assertJsonPath('data.code', $first->json('data.code'))->assertJsonMissing(['revoked_at']);
        $second = $this->postJson('/api/v1/me/invite', ['rotate' => true])->assertCreated();
        $this->assertNotSame($first->json('data.code'), $second->json('data.code'));
        $this->assertDatabaseHas('user_invitations', ['code' => $first->json('data.code')]);

        $referred = User::factory()->create();
        Sanctum::actingAs($referred, ['mobile']);
        $this->postJson('/api/v1/referrals/redeem', ['code' => $first->json('data.code')])->assertUnprocessable()->assertJsonPath('error.code', 'INVITE_INVALID');
        $this->postJson('/api/v1/referrals/redeem', ['code' => $second->json('data.code')])->assertCreated()->assertJsonPath('data.redeemed', true);
        $this->postJson('/api/v1/referrals/redeem', ['code' => $second->json('data.code')])->assertConflict()->assertJsonPath('error.code', 'REFERRAL_ALREADY_REDEEMED');
        $this->assertDatabaseHas('referrals', ['referrer_id' => $inviter->id, 'referred_id' => $referred->id, 'state' => 'pending']);
    }
}
