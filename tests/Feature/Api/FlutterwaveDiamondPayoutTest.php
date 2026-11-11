<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FlutterwaveDiamondPayoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pay_sends_major_units_only_when_the_wallet_can_cover_the_batch(): void
    {
        [$admin, $cashoutId, $netMinor] = $this->approvedCashout();
        Http::fake([
            'https://api.flutterwave.com/v3/balances/*' => Http::response(['status' => 'success', 'data' => ['available_balance' => 90000000]]),
            'https://api.flutterwave.com/v3/bulk-transfers' => Http::response(['status' => 'success', 'message' => 'Bulk transfer queued.', 'data' => ['id' => 44]]),
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->postJson('/api/admin/v1/finance/diamond-cashouts/pay', [
                'reason' => 'Weekly diamond cashout batch has funds.',
                'cashout_ids' => [$cashoutId],
            ])
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertDatabaseHas('diamond_cashouts', [
            'id' => $cashoutId,
            'state' => 'processing',
            'provider_reference' => 'dc-'.$cashoutId,
        ]);
        Http::assertSent(function ($request) use ($cashoutId, $netMinor): bool {
            if (! str_contains($request->url(), '/v3/bulk-transfers')) {
                return false;
            }
            $item = $request->data()['bulk_data'][0];

            return $item['reference'] === 'dc-'.$cashoutId
                && $item['bank_code'] === '058'
                && $item['account_number'] === '0123456789'
                && $item['currency'] === 'NGN'
                && abs((float) $item['amount'] - round($netMinor / 100, 2)) < 0.001;
        });

        $this->postJson('/webhooks/v1/flutterwave', [
            'event' => 'transfer.completed',
            'data' => ['id' => 9001, 'reference' => 'dc-'.$cashoutId, 'status' => 'SUCCESSFUL'],
        ], ['verif-hash' => 'verif-secret'])->assertStatus(202);
        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'paid']);
        $this->assertSame(0, $this->diamondWallet());
    }

    public function test_pay_refuses_when_the_wallet_is_short_and_does_not_call_transfers(): void
    {
        [$admin, $cashoutId] = $this->approvedCashout();
        Http::fake([
            'https://api.flutterwave.com/v3/balances/*' => Http::response(['status' => 'success', 'data' => ['available_balance' => 1]]),
            'https://api.flutterwave.com/v3/bulk-transfers' => Http::response(['status' => 'success'], 200),
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->postJson('/api/admin/v1/finance/diamond-cashouts/pay', [
                'reason' => 'Weekly diamond cashout batch has funds.',
                'cashout_ids' => [$cashoutId],
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'approved', 'provider_reference' => null]);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v3/bulk-transfers'));
    }

    public function test_a_rejected_transfer_returns_the_cashout_to_approved(): void
    {
        [$admin, $cashoutId] = $this->approvedCashout();
        Http::fake([
            'https://api.flutterwave.com/v3/balances/*' => Http::response(['status' => 'success', 'data' => ['available_balance' => 90000000]]),
            'https://api.flutterwave.com/v3/bulk-transfers' => Http::response(['status' => 'error', 'message' => 'Invalid bank code'], 400),
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->postJson('/api/admin/v1/finance/diamond-cashouts/pay', [
                'reason' => 'Weekly diamond cashout batch has funds.',
                'cashout_ids' => [$cashoutId],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'approved', 'provider_reference' => null]);
    }

    public function test_a_timeout_leaves_the_cashout_processing_and_a_failure_webhook_returns_diamonds(): void
    {
        [$admin, $cashoutId, , $diamonds] = $this->approvedCashout();
        Http::fake([
            'https://api.flutterwave.com/v3/balances/*' => Http::response(['status' => 'success', 'data' => ['available_balance' => 90000000]]),
            'https://api.flutterwave.com/v3/bulk-transfers' => function (): void {
                throw new ConnectionException('timed out');
            },
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->postJson('/api/admin/v1/finance/diamond-cashouts/pay', [
                'reason' => 'Weekly diamond cashout batch has funds.',
                'cashout_ids' => [$cashoutId],
            ])
            ->assertOk();
        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'processing', 'provider_reference' => 'dc-'.$cashoutId]);

        $this->postJson('/webhooks/v1/flutterwave', [
            'event' => 'transfer.completed',
            'data' => ['id' => 9002, 'reference' => 'dc-'.$cashoutId, 'status' => 'FAILED', 'complete_message' => 'Account closed'],
        ], ['verif-hash' => 'verif-secret'])->assertStatus(202);

        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'failed']);
        $this->assertSame($diamonds, $this->diamondWallet());
    }

    /**
     * @return array{0: Admin, 1: string, 2: int, 3: int}
     */
    private function approvedCashout(): array
    {
        config()->set('finance.public_enabled', true);
        config()->set('services.flutterwave.payout_token', 'test-token');
        config()->set('services.flutterwave.webhook_secret', 'verif-secret');
        $economy = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/gifts/economy')->assertOk();
        $sender = User::factory()->create();
        $creatorUser = User::factory()->create();
        $creatorUser->forceFill(['age_majority_confirmed_at' => now(), 'country_code' => 'NG'])->save();
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Reel Creator']);
        $diamonds = (int) $economy->json('data.min_cashout_diamonds');
        $giftType = GiftType::create(['slug' => 'payout-star-'.Str::lower(Str::random(4)), 'name' => 'Star', 'coins' => $diamonds, 'version' => 1]);
        $wallet = FinancialAccount::create(['owner_type' => User::class, 'owner_id' => $sender->id, 'type' => 'gift_wallet', 'unit' => 'PCN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'iap_liability', 'unit' => 'PCN', 'balance' => 0]);
        app(PostLedgerTransaction::class)->handle('test.iap', 'payout-fund-'.Str::lower(Str::random(6)), 'PCN', [
            ['account_id' => $funding->id, 'amount' => -$diamonds],
            ['account_id' => $wallet->id, 'amount' => $diamonds],
        ]);
        $this->actingAs($sender, 'sanctum')->withHeader('Idempotency-Key', 'payout-gift')->postJson('/api/v1/gifts/send', [
            'gift_type_id' => $giftType->id,
            'creator_profile_id' => $creator->id,
        ])->assertCreated();
        DB::table('fx_rate_versions')->insert([
            'id' => (string) Str::ulid(),
            'base_unit' => 'USD',
            'quote_currency' => 'NGN',
            'rate' => '1450.00000000',
            'source' => 'test',
            'approval_state' => 'approved',
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $method = (string) Str::ulid();
        DB::table('payout_methods')->insert([
            'id' => $method,
            'owner_type' => User::class,
            'owner_id' => $creatorUser->id,
            'provider' => 'flutterwave',
            'kind' => 'bank',
            'label' => 'GTBank',
            'destination_encrypted' => encrypt(['account_number' => '0123456789', 'bank_code' => '058', 'country' => 'NG']),
            'destination_last_four' => '6789',
            'verified_at' => now(),
            'proof_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cashout = $this->actingAs($creatorUser, 'sanctum')->withHeader('Idempotency-Key', 'payout-cashout')->postJson('/api/v1/creators/diamond-cashouts', [
            'payout_method_id' => $method,
            'diamonds' => $diamonds,
            'currency' => 'NGN',
        ])->assertCreated();
        $cashoutId = (string) $cashout->json('data.id');
        $admin = $this->financeAdmin();
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])
            ->putJson('/api/admin/v1/finance/diamond-cashouts/'.$cashoutId, [
                'state' => 'approved',
                'reason' => 'Bank name matches the creator profile.',
            ])->assertOk();

        return [$admin, $cashoutId, (int) $cashout->json('data.net_minor'), $diamonds];
    }

    private function diamondWallet(): int
    {
        return (int) FinancialAccount::query()->where('type', 'diamond_wallet')->value('balance');
    }

    private function financeAdmin(): Admin
    {
        $admin = Admin::create(['name' => 'Finance', 'email' => 'payout-'.Str::random(5).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'payout-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.view', 'finance.adjust', 'payouts.approve'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id') ?: DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);

        return $admin;
    }
}
