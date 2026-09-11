<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\CreatorProfile;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileGiftsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wallet_packs_catalog_activity_and_receipt_use_public_fields(): void
    {
        $sender = User::factory()->create(['name' => 'Listener Ada', 'handle' => 'ada_listener']);
        $creatorUser = User::factory()->create(['handle' => 'studio_owner']);
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Studio Owner']);
        $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/wallet')->assertOk()->assertJsonPath('data.unit', 'PCN')->assertJsonPath('data.balance', 0);
        $this->assertDatabaseMissing('financial_accounts', ['owner_id' => $sender->id, 'type' => 'gift_wallet']);

        DB::table('coin_products')->insert(['id' => (string) Str::ulid(), 'store' => 'apple', 'product_id' => 'pelevo_coins_1200', 'coins' => 1200, 'unit' => 'PCN', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        GiftType::create(['slug' => 'mobile-applause', 'name' => 'Applause', 'coins' => 100, 'version' => 1]);
        $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/packs')->assertOk()->assertJsonPath('data.0.product_id', 'pelevo_coins_1200')->assertJsonPath('data.0.coins', 1200)->assertJsonPath('data.0.unit', 'PCN')->assertJsonMissingPath('data.0.created_at');
        $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/catalog')->assertOk()->assertJsonPath('data.0.name', 'Applause')->assertJsonPath('data.0.coins', 100)->assertJsonMissingPath('data.0.updated_at');
        $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/wallet/activity')->assertOk()->assertJsonCount(0, 'data');

        $wallet = FinancialAccount::create(['owner_type' => User::class, 'owner_id' => $sender->id, 'type' => 'gift_wallet', 'unit' => 'PCN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'iap_liability', 'unit' => 'PCN', 'balance' => 0]);
        $transaction = app(PostLedgerTransaction::class)->handle('iap.verified', 'gift-wallet-iap', 'PCN', [['account_id' => $funding->id, 'amount' => -1200], ['account_id' => $wallet->id, 'amount' => 1200]]);
        $activity = $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/wallet/activity')->assertOk();
        $activity->assertJsonPath('data.0.event_type', 'iap.verified')->assertJsonPath('data.0.amount', 1200)->assertJsonPath('data.0.unit', 'PCN')->assertJsonMissingPath('data.0.idempotency_key');
        $this->assertNotSame($transaction->id, $activity->json('data.0.id'));

        $giftType = GiftType::where('slug', 'mobile-applause')->first();
        $gift = (string) Str::ulid();
        $spend = app(PostLedgerTransaction::class)->handle('gift.sent', 'gift-wallet-send', 'PCN', [['account_id' => $wallet->id, 'amount' => -100], ['account_id' => $funding->id, 'amount' => 100]]);
        DB::table('gifts')->insert(['id' => $gift, 'sender_id' => $sender->id, 'creator_profile_id' => $creator->id, 'gift_type_id' => $giftType->id, 'ledger_transaction_id' => $spend->id, 'message' => 'Thank you', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($sender, 'sanctum')->getJson("/api/v1/gifts/{$gift}")->assertOk()->assertJsonPath('data.id', $gift)->assertJsonPath('data.gift_type_name', 'Applause')->assertJsonPath('data.coins', 100)->assertJsonPath('data.creator_display_name', 'Studio Owner')->assertJsonPath('data.creator_handle', 'studio_owner')->assertJsonPath('data.message', 'Thank you')->assertJsonPath('data.remaining_balance', 1100)->assertJsonMissingPath('data.ledger_transaction_id')->assertJsonMissingPath('data.fee_version_id');
        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/gifts/{$gift}")->assertNotFound();
        $this->actingAs($sender, 'sanctum')->getJson('/api/v1/gifts/wallet')->assertOk()->assertJsonPath('data.balance', 1100);
    }

    public function test_iap_verify_omits_verification_payload_and_credits_once(): void
    {
        config()->set(['finance.public_enabled' => true, 'services.apple.verification_url' => 'https://apple.test/verify']);
        Http::preventStrayRequests();
        Http::fake(['https://apple.test/verify' => Http::response(['valid' => true, 'original_transaction_id' => 'mobile-iap-1', 'product_id' => 'pelevo_coins_500', 'environment' => 'sandbox'])]);
        DB::table('coin_products')->insert(['id' => (string) Str::ulid(), 'store' => 'apple', 'product_id' => 'pelevo_coins_500', 'coins' => 500, 'unit' => 'PCN', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        $payload = ['store' => 'apple', 'receipt' => 'opaque-store-receipt'];
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/iap/verify', $payload)->assertCreated()->assertJsonPath('data.coins', 500)->assertJsonPath('data.unit', 'PCN')->assertJsonPath('data.remaining_balance', 500)->assertJsonMissingPath('data.verification_payload')->assertJsonMissingPath('data.receipt_hash')->assertJsonMissingPath('data.original_transaction_id');
    }
}
