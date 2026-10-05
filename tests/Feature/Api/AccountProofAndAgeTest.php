<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountProofAndAgeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_money_actions_require_an_unticked_age_confirmation_before_they_proceed(): void
    {
        config()->set('finance.public_enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/age-majority', ['confirmed' => false])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/iap/verify', ['store' => 'apple', 'receipt' => 'receipt'])->assertForbidden()->assertJsonPath('error.code', 'AGE_CONFIRMATION_REQUIRED');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/payout-methods', ['provider' => 'paypal', 'kind' => 'paypal', 'destination' => ['email' => 'earn@example.com']])->assertForbidden()->assertJsonPath('error.code', 'AGE_CONFIRMATION_REQUIRED');
        $method = (string) Str::ulid();
        DB::table('payout_methods')->insert(['id' => $method, 'owner_type' => User::class, 'owner_id' => $user->id, 'provider' => 'paypal', 'kind' => 'paypal', 'destination_encrypted' => encrypt(['email' => 'earn@example.com']), 'destination_last_four' => 'com', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'age-withdraw')->postJson('/api/v1/withdrawals', ['payout_method_id' => $method, 'coins' => 1000])->assertForbidden()->assertJsonPath('error.code', 'AGE_CONFIRMATION_REQUIRED');

        $confirmed = $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/age-majority', ['confirmed' => true])->assertOk()->assertJsonPath('data.age_majority_confirmed', true);
        $stamp = $confirmed->json('data.age_majority_confirmed_at');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/age-majority', ['confirmed' => true])->assertOk()->assertJsonPath('data.age_majority_confirmed_at', $stamp);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.age_majority_confirmed', true);
    }

    public function test_bank_proof_stays_private_until_review_then_is_purged(): void
    {
        Storage::fake('proofs');
        Storage::fake('public');
        config()->set([
            'finance.public_enabled' => true,
            'services.paystack.payout_verification_url' => 'https://paystack.test/resolve',
            'services.paystack.payout_verification_token' => 'token',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://paystack.test/resolve' => Http::response(['verified' => true, 'reference' => 'bank-1', 'account_name' => 'Ada Owner'])]);
        $user = User::factory()->create(['name' => 'Ada Owner']);
        $user->forceFill(['age_majority_confirmed_at' => now()])->save();

        $method = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payout-methods', [
            'provider' => 'paystack',
            'kind' => 'bank',
            'destination' => ['account_number' => '0123456789', 'bank_code' => '044'],
        ])->assertCreated()->assertJsonPath('data.label', 'Ada Owner')->assertJsonPath('data.verified_at', null)->assertJsonPath('data.proof_status', 'none')->assertJsonMissingPath('data.proof_path')->json('data.id');

        $this->actingAs($user, 'sanctum')->post('/api/v1/payout-methods/'.$method.'/account-proof', [
            'account_proof' => UploadedFile::fake()->create('notes.txt', 12, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->post('/api/v1/payout-methods/'.$method.'/account-proof', [
            'account_proof' => UploadedFile::fake()->image('huge.jpg')->size(5000),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $upload = $this->actingAs($user, 'sanctum')->post('/api/v1/payout-methods/'.$method.'/account-proof', [
            'account_proof' => UploadedFile::fake()->image('statement.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.proof_status', 'pending')->assertJsonPath('data.verified_at', null)->assertJsonMissingPath('data.proof_path');
        $path = DB::table('payout_methods')->where('id', $method)->value('proof_path');
        $this->assertNotEmpty($path);
        $this->assertStringNotContainsString($path, $upload->getContent());
        Storage::disk('proofs')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($user, 'sanctum')->post('/api/v1/payout-methods/'.$method.'/account-proof', [
            'account_proof' => UploadedFile::fake()->image('cropped.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $replaced = DB::table('payout_methods')->where('id', $method)->value('proof_path');
        Storage::disk('proofs')->assertMissing($path);
        Storage::disk('proofs')->assertExists($replaced);
        $this->assertCount(1, Storage::disk('proofs')->allFiles());

        $proofUrl = '/api/admin/v1/finance/payout-methods/'.$method.'/proof';
        $this->assertNotSame(200, $this->get($proofUrl)->status());

        $admin = $this->financeAdmin();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->withoutVite();
        $page = $this->get('/admin/finance?desk=payouts')->assertOk();
        $this->assertStringNotContainsString($replaced, $page->getContent());
        $this->get($proofUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('cache-control', 'private, no-store');

        $this->postJson('/api/admin/v1/finance/payout-methods/'.$method.'/decision', [
            'decision' => 'approved',
            'reason' => 'Name and account number match. Creator confirmed they are 18 or older.',
        ])->assertOk()->assertJsonPath('data.purged', true)->assertJsonPath('data.verified', true);
        $this->assertNull(DB::table('payout_methods')->where('id', $method)->value('proof_path'));
        $this->assertNotNull(DB::table('payout_methods')->where('id', $method)->value('verified_at'));
        $this->assertSame('approved', DB::table('payout_methods')->where('id', $method)->value('proof_status'));
        Storage::disk('proofs')->assertMissing($replaced);
        $this->assertSame([], Storage::disk('proofs')->allFiles());
        $this->get($proofUrl)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $method, 'action' => 'payout_proof.approved']);
    }

    public function test_rejecting_an_account_proof_purges_the_file_and_leaves_the_method_unverified(): void
    {
        Storage::fake('proofs');
        config()->set(['finance.public_enabled' => true, 'services.paystack.payout_verification_url' => null]);
        $user = User::factory()->create();
        $user->forceFill(['age_majority_confirmed_at' => now()])->save();
        $method = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payout-methods', [
            'provider' => 'paystack',
            'kind' => 'bank',
            'destination' => ['account_number' => '0123456789', 'bank_code' => '058'],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user, 'sanctum')->post('/api/v1/payout-methods/'.$method.'/account-proof', [
            'account_proof' => UploadedFile::fake()->image('statement.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $path = DB::table('payout_methods')->where('id', $method)->value('proof_path');

        $admin = $this->financeAdmin();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->postJson('/api/admin/v1/finance/payout-methods/'.$method.'/decision', [
                'decision' => 'rejected',
                'reason' => 'The visible name does not match the account holder.',
            ])->assertOk()->assertJsonPath('data.verified', false)->assertJsonPath('data.purged', true);

        $this->assertNull(DB::table('payout_methods')->where('id', $method)->value('verified_at'));
        $this->assertNull(DB::table('payout_methods')->where('id', $method)->value('proof_path'));
        $this->assertSame('rejected', DB::table('payout_methods')->where('id', $method)->value('proof_status'));
        Storage::disk('proofs')->assertMissing($path);
    }

    private function financeAdmin(): Admin
    {
        $admin = Admin::create(['name' => 'Finance', 'email' => 'proof-'.Str::random(5).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'proof-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.view', 'payouts.approve'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id') ?: DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);

        return $admin;
    }
}
