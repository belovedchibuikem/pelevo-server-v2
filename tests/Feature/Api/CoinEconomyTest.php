<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\Admin;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoinEconomyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeded_packs_follow_the_live_store_fee_and_diamond_cashout_keeps_the_app_share_inside_the_rate(): void
    {
        config()->set('finance.public_enabled', true);
        $economy = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/gifts/economy')->assertOk();
        $economy->assertJsonPath('data.regime.code', 'standard')
            ->assertJsonPath('data.regime.store_fee_percent', 30)
            ->assertJsonPath('data.regime.diamond_usd', '0.0006207')
            ->assertJsonPath('data.anchor.coins', 300)
            ->assertJsonPath('data.anchor.usd', '0.38')
            ->assertJsonPath('data.anchor.gross_coin_usd', '0.0012667')
            ->assertJsonPath('data.minimum_cashout_usd', '15.00')
            ->assertJsonPath('data.rules.coins_per_diamond', 1)
            ->assertJsonPath('data.rules.ledger_currency', 'USD')
            ->assertJsonPath('data.rules.extra_platform_fee_on_cashout', false);
        $pack = collect($economy->json('data.packs'))->first(fn (array $row): bool => $row['product_id'] === 'coin_pack_500' && $row['store'] === 'google');
        $this->assertSame(85000, $pack['price_ngn_kobo']);
        $this->assertSame(63, $pack['price_usd_cents']);
        $this->assertSame(25500, $pack['economics']['ngn']['store_fee_minor']);
        $this->assertSame(59500, $pack['economics']['ngn']['net_minor']);
        $this->assertSame(41650, $pack['economics']['ngn']['creator_minor']);
        $this->assertSame(17850, $pack['economics']['ngn']['app_minor']);
        $anchor = collect($economy->json('data.packs'))->first(fn (array $row): bool => $row['product_id'] === 'coin_pack_300' && $row['store'] === 'apple');
        $this->assertSame(38, $anchor['price_usd_cents']);
        $thousand = collect($economy->json('data.packs'))->first(fn (array $row): bool => $row['product_id'] === 'coin_pack_1000' && $row['store'] === 'apple');
        $this->assertSame(170000, $thousand['price_ngn_kobo']);
        $this->assertSame(127, $thousand['price_usd_cents']);

        $sender = User::factory()->create();
        $creatorUser = User::factory()->create();
        $creatorUser->forceFill(['age_majority_confirmed_at' => now(), 'country_code' => 'NG'])->save();
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Reel Creator']);
        $diamonds = (int) $economy->json('data.min_cashout_diamonds');
        $giftType = GiftType::create(['slug' => 'economy-star', 'name' => 'Star', 'coins' => $diamonds, 'version' => 1]);
        $wallet = FinancialAccount::create(['owner_type' => User::class, 'owner_id' => $sender->id, 'type' => 'gift_wallet', 'unit' => 'PCN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'iap_liability', 'unit' => 'PCN', 'balance' => 0]);
        app(PostLedgerTransaction::class)->handle('test.iap', 'economy-fund', 'PCN', [
            ['account_id' => $funding->id, 'amount' => -$diamonds],
            ['account_id' => $wallet->id, 'amount' => $diamonds],
        ]);
        $this->actingAs($sender, 'sanctum')->withHeader('Idempotency-Key', 'economy-gift')->postJson('/api/v1/gifts/send', [
            'gift_type_id' => $giftType->id,
            'creator_profile_id' => $creator->id,
        ])->assertCreated()->assertJsonPath('data.coins', $diamonds)->assertJsonPath('data.diamonds', $diamonds);

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
        $quote = $this->actingAs($creatorUser, 'sanctum')->getJson('/api/v1/creators/diamonds')->assertOk();
        $quote->assertJsonPath('data.balance', $diamonds)
            ->assertJsonPath('data.quotes.USD.gross_minor', 1500)
            ->assertJsonPath('data.quotes.USD.transfer_fee_minor', 0)
            ->assertJsonPath('data.quotes.USD.net_minor', 1500)
            ->assertJsonPath('data.quotes.USD.payable', true)
            ->assertJsonPath('data.quotes.USD.rate_includes_app_share', true)
            ->assertJsonPath('data.display.available_usd', '15.00')
            ->assertJsonPath('data.display.currency', 'NGN')
            ->assertJsonPath('data.display.fx_rate', '1450.00')
            ->assertJsonPath('data.display.display_available', '₦21,750.00');

        $method = (string) Str::ulid();
        DB::table('payout_methods')->insert([
            'id' => $method,
            'owner_type' => User::class,
            'owner_id' => $creatorUser->id,
            'provider' => 'paystack',
            'kind' => 'bank',
            'label' => 'GTBank',
            'destination_encrypted' => encrypt(['account_number' => '0123456789', 'bank_code' => '058']),
            'destination_last_four' => '6789',
            'verified_at' => now(),
            'proof_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($creatorUser, 'sanctum')->withHeader('Idempotency-Key', 'cashout-small')->postJson('/api/v1/creators/diamond-cashouts', [
            'payout_method_id' => $method,
            'diamonds' => 50,
            'currency' => 'NGN',
        ])->assertUnprocessable();

        $cashout = $this->actingAs($creatorUser, 'sanctum')->withHeader('Idempotency-Key', 'cashout-once')->postJson('/api/v1/creators/diamond-cashouts', [
            'payout_method_id' => $method,
            'diamonds' => $diamonds,
            'currency' => 'NGN',
        ])->assertCreated();
        $cashout->assertJsonPath('data.gross_usd_cents', 1500)
            ->assertJsonPath('data.gross_minor', 2175000)
            ->assertJsonPath('data.transfer_fee_minor', 0)
            ->assertJsonPath('data.net_minor', 2175000)
            ->assertJsonPath('data.fx_rate', '1450.00')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.state', 'queued');
        $cashoutId = $cashout->json('data.id');
        $this->actingAs($creatorUser, 'sanctum')->withHeader('Idempotency-Key', 'cashout-once')->postJson('/api/v1/creators/diamond-cashouts', [
            'payout_method_id' => $method,
            'diamonds' => $diamonds,
            'currency' => 'NGN',
        ])->assertOk()->assertJsonPath('data.id', $cashoutId);
        $this->assertSame(0, (int) FinancialAccount::where('owner_id', $creator->id)->where('type', 'diamond_wallet')->value('balance'));
        $this->assertSame($diamonds, (int) FinancialAccount::where('type', 'diamond_redemption')->value('balance'));
        $this->assertNull(FinancialAccount::where('type', 'gift_platform_fee')->value('balance'));

        $regimeId = DB::table('gifts')->value('economy_regime_id');
        $admin = $this->financeAdmin();
        $client = $this->actingAs($admin, 'admin')->withSession(['admin_mfa_verified_at' => now()->timestamp]);
        $client->putJson('/api/admin/v1/finance/coin-economy/'.$regimeId, $this->regimePayload('standard', '0.90'))->assertStatus(409);
        $client->putJson('/api/admin/v1/finance/diamond-cashouts/'.$cashoutId, [
            'state' => 'rejected',
            'reason' => 'The account name did not match the creator.',
        ])->assertOk();
        $this->assertSame($diamonds, (int) FinancialAccount::where('owner_id', $creator->id)->where('type', 'diamond_wallet')->value('balance'));
        $this->assertSame(0, (int) FinancialAccount::where('type', 'diamond_redemption')->value('balance'));
        $this->assertDatabaseHas('diamond_cashouts', ['id' => $cashoutId, 'state' => 'rejected']);

        $smallBusiness = DB::table('coin_economy_regimes')->where('code', 'small_business')->value('id');
        $client->putJson('/api/admin/v1/finance/coin-economy/'.$smallBusiness.'/activation', [
            'active' => true,
            'reason' => 'Small Business Program enrollment is approved.',
        ])->assertOk();
        $this->actingAs($creatorUser, 'sanctum')->getJson('/api/v1/creators/diamonds')
            ->assertOk()
            ->assertJsonPath('data.regime.code', 'small_business')
            ->assertJsonPath('data.regime.diamond_usd', '0.0007537')
            ->assertJsonPath('data.quotes.USD.payable', true)
            ->assertJsonPath('data.display.currency', 'NGN');

        $client->postJson('/api/admin/v1/finance/coin-economy', $this->regimePayload('pilot', '0.9'))->assertCreated();
        $this->actingAs($creatorUser, 'sanctum')->getJson('/api/v1/gifts/economy')
            ->assertOk()
            ->assertJsonPath('data.regime.code', 'pilot')
            ->assertJsonPath('data.regime.diamond_ngn', '0.9');
    }

    /**
     * @return array<string, mixed>
     */
    private function regimePayload(string $code, string $naira): array
    {
        return [
            'code' => $code,
            'name' => 'Pilot store fee',
            'store_fee_percent' => 30,
            'creator_split_percent' => 70,
            'diamond_ngn' => $naira,
            'diamond_usd' => '0.00052',
            'transfer_fee_ngn' => '50',
            'transfer_fee_ngn_min' => '10',
            'transfer_fee_ngn_max' => '50',
            'transfer_fee_usd' => '0',
            'min_cashout_diamonds' => 100,
            'effective_at' => now()->toIso8601String(),
            'active' => true,
            'reason' => 'Publish a replacement diamond rate for future gifts and cashouts.',
        ];
    }

    private function financeAdmin(): Admin
    {
        $admin = Admin::create(['name' => 'Finance', 'email' => 'economy-'.Str::random(5).'@example.com', 'password' => 'password', 'status' => 'active']);
        $role = DB::table('roles')->insertGetId(['name' => 'economy-'.Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['finance.view', 'finance.adjust', 'payouts.approve'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id') ?: DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('admin_role')->insert(['admin_id' => $admin->id, 'role_id' => $role]);

        return $admin;
    }
}
