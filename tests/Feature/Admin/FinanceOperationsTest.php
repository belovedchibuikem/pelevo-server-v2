<?php

namespace Tests\Feature\Admin;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\Admin;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class FinanceOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_payout_destination_is_verified_encrypted_and_rejected_withdrawal_releases_reservation(): void
    {
        Mail::fake();
        config()->set(['finance.public_enabled' => true, 'services.paystack.payout_verification_url' => 'https://paystack.test/resolve']);
        Http::preventStrayRequests();
        Http::fake(['https://paystack.test/resolve' => Http::response(['verified' => true, 'reference' => 'resolve-1', 'account_name' => 'Verified Person'])]);
        $user = User::factory()->create();
        $payload = ['provider' => 'paystack', 'kind' => 'bank', 'destination' => ['account_number' => '0123456789', 'bank_code' => '058']];
        $method = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payout-methods', $payload)->assertCreated()->assertJsonMissing(['destination_encrypted'])->json('data.id');
        $ciphertext = DB::table('payout_methods')->where('id', $method)->value('destination_encrypted');
        $this->assertStringNotContainsString('0123456789', $ciphertext);

        $wallet = FinancialAccount::firstOrCreate(['owner_type' => get_class($user), 'owner_id' => $user->id, 'type' => 'earn_wallet', 'unit' => 'ECN'], ['balance' => 0]);
        $source = FinancialAccount::create(['type' => 'earn_funding', 'unit' => 'ECN', 'balance' => 0]);
        app(PostLedgerTransaction::class)->handle('test.fund', 'fund-user', 'ECN', [['account_id' => $source->id, 'amount' => -2000], ['account_id' => $wallet->id, 'amount' => 2000]]);
        $withdrawal = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'withdraw-once')->postJson('/api/v1/withdrawals', ['payout_method_id' => $method, 'coins' => 1250])->assertCreated()->json('data.id');
        $this->assertSame(750, $wallet->fresh()->balance);

        $admin = $this->financeAdmin();
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $this->withoutVite();
        $client->get('/admin/finance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Finance')
            ->has('desks', 7)
            ->has('attention')
            ->has('products.fees')
            ->has('earn.liability')
            ->where('desk', 'fx'));
        $client->get('/admin/finance?desk=fx')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('desk', 'fx')->has('products.packs'));
        $client->putJson("/api/admin/v1/finance/withdrawals/{$withdrawal}", ['state' => 'rejected', 'reason' => 'Beneficiary verification was subsequently revoked.'])->assertOk();
        $this->assertSame(2000, $wallet->fresh()->balance);
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal, 'state' => 'rejected']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $withdrawal, 'action' => 'withdrawal.rejected']);
    }

    public function test_product_catalog_publishes_versions_and_blocks_sku_coin_rewrites(): void
    {
        $admin = $this->financeAdmin();
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $originalFee = DB::table('fee_versions')->where('type', 'gift_platform')->first();
        $this->assertNotNull($originalFee);

        $client->postJson('/api/admin/v1/finance/fees', ['type' => 'gift_platform', 'percent' => 15, 'effective_at' => now()->addDay()->toIso8601String(), 'reason' => 'Seasonal gift platform fee increase for Q4.'])->assertCreated();
        $this->assertDatabaseHas('fee_versions', ['id' => $originalFee->id, 'basis_points' => $originalFee->basis_points]);
        $this->assertSame(2, DB::table('fee_versions')->where('type', 'gift_platform')->count());

        $client->putJson('/api/admin/v1/settings/fx', ['base_unit' => 'PCN', 'quote_currency' => 'NGN', 'rate' => 4.25, 'source' => 'manual', 'effective_at' => now()->toIso8601String(), 'reason' => 'Manual FX snapshot for creator payout conversion.'])->assertCreated();
        $this->assertDatabaseHas('fx_rate_versions', ['base_unit' => 'PCN', 'quote_currency' => 'NGN']);

        $pack = $client->postJson('/api/admin/v1/finance/coin-products', ['store' => 'apple', 'product_id' => 'pelevo_coins_test', 'coins' => 900, 'unit' => 'PCN', 'active' => true, 'reason' => 'New sandbox SKU for finance catalog tests.'])->assertCreated()->json('data');
        $user = User::factory()->create();
        DB::table('iap_receipts')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'coin_product_id' => $pack['id'], 'store' => 'apple', 'original_transaction_id' => 'txn-1', 'receipt_hash' => hash('sha256', 'receipt-1'), 'state' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        $client->postJson('/api/admin/v1/finance/coin-products', ['store' => 'apple', 'product_id' => 'pelevo_coins_test', 'coins' => 1200, 'unit' => 'PCN', 'active' => true, 'reason' => 'Attempt to rewrite coins after a receipt exists.'])->assertStatus(409);
        $client->putJson('/api/admin/v1/finance/coin-products/'.$pack['id'], ['active' => false, 'reason' => 'Retire SKU after receipts without rewriting coins.'])->assertOk();
        $this->assertDatabaseHas('coin_products', ['id' => $pack['id'], 'coins' => 900, 'active' => 0]);

        $gift = $client->postJson('/api/admin/v1/finance/gift-types', ['slug' => 'encore', 'name' => 'Encore', 'coins' => 250, 'active' => true, 'reason' => 'Add a mid-tier gift to the live catalog.'])->assertCreated()->json('data');
        $client->putJson('/api/admin/v1/finance/gift-types/'.$gift['id'], ['name' => 'Encore', 'coins' => 300, 'active' => true, 'reason' => 'Raise encore catalog price; historic gifts stay on ledger.'])->assertOk();
        $this->assertDatabaseHas('gift_types', ['id' => $gift['id'], 'version' => 2, 'coins' => 300]);
    }

    public function test_finance_routes_require_permissions_and_fresh_mfa(): void
    {
        $admin = Admin::create(['name' => 'No Access', 'email' => 'none@example.com', 'password' => 'password', 'status' => 'active']);
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->subHour()->timestamp])->get('/admin/finance')->assertRedirect('/admin/step-up');
        $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp])->get('/admin/finance')->assertForbidden();
    }

    public function test_signed_payout_webhook_reconciles_once_and_replay_has_no_second_effect(): void
    {
        Mail::fake();
        config()->set('services.paystack.webhook_secret', 'webhook-secret');
        $user = User::factory()->create();
        $method = (string) Str::ulid();
        DB::table('payout_methods')->insert(['id' => $method, 'owner_type' => get_class($user), 'owner_id' => $user->id, 'provider' => 'paystack', 'kind' => 'bank', 'destination_encrypted' => encrypt(['account_number' => '0123456789']), 'destination_last_four' => '6789', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $wallet = FinancialAccount::create(['owner_type' => get_class($user), 'owner_id' => $user->id, 'type' => 'earn_wallet', 'unit' => 'ECN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'earn_funding', 'unit' => 'ECN', 'balance' => 0]);
        $payable = FinancialAccount::create(['owner_type' => get_class($user), 'owner_id' => $user->id, 'type' => 'withdrawal_payable', 'unit' => 'ECN', 'balance' => 0]);
        $post = app(PostLedgerTransaction::class);
        $post->handle('test.fund', 'webhook-fund', 'ECN', [['account_id' => $funding->id, 'amount' => -1250], ['account_id' => $wallet->id, 'amount' => 1250]]);
        $reservation = $post->handle('withdrawal.reserved', 'webhook-reserve', 'ECN', [['account_id' => $wallet->id, 'amount' => -1250], ['account_id' => $payable->id, 'amount' => 1250]]);
        $withdrawal = (string) Str::ulid();
        DB::table('withdrawals')->insert(['id' => $withdrawal, 'user_id' => $user->id, 'payout_method_id' => $method, 'ledger_transaction_id' => $reservation->id, 'coins' => 1250, 'state' => 'processing', 'idempotency_key' => 'webhook-reserve', 'provider_reference' => 'provider-123', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['type' => 'transfer.success', 'data' => ['reference' => 'provider-123']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['X-Pelevo-Signature' => hash_hmac('sha256', $body, 'webhook-secret'), 'X-Provider-Event-Id' => 'event-1', 'Content-Type' => 'application/json'];

        $this->postJson('/webhooks/v1/paystack', $payload, $headers)->assertAccepted();
        $this->postJson('/webhooks/v1/paystack', $payload, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal, 'state' => 'paid']);
        $this->assertDatabaseCount('provider_webhook_events', 1);
        $this->assertSame(2, DB::table('ledger_entries')->where('ledger_transaction_id', $reservation->id)->count());
    }

    private function financeAdmin(): Admin
    {
        $admin = Admin::create(['name' => 'Finance', 'email' => 'finance-'.Str::random(5).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'finance-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.view', 'finance.adjust', 'payouts.approve'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id') ?: DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);

        return $admin;
    }
}
