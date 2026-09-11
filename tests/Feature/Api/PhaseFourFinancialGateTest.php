<?php

namespace Tests\Feature\Api;

use App\Actions\Finance\PostLedgerTransaction;
use App\Actions\Finance\ReverseLedgerTransaction;
use App\Jobs\RunReconciliation;
use App\Models\CreatorProfile;
use App\Models\Device;
use App\Models\Episode;
use App\Models\FinancialAccount;
use App\Models\GiftType;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class PhaseFourFinancialGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_financial_writes_are_closed_until_finance_signoff(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/iap/verify', ['store' => 'apple', 'receipt' => 'receipt'])->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_DEGRADED');
    }

    public function test_iap_is_server_verified_credited_once_and_cannot_be_claimed_by_another_user(): void
    {
        config()->set(['finance.public_enabled' => true, 'services.apple.verification_url' => 'https://apple.test/verify']);
        Http::preventStrayRequests();
        Http::fake(['https://apple.test/verify' => Http::response(['valid' => true, 'original_transaction_id' => 'original-1', 'product_id' => 'pelevo_coins_500', 'environment' => 'sandbox'])]);
        $product = (string) Str::ulid();
        DB::table('coin_products')->insert(['id' => $product, 'store' => 'apple', 'product_id' => 'pelevo_coins_500', 'coins' => 500, 'unit' => 'PCN', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $payload = ['store' => 'apple', 'receipt' => 'opaque-store-receipt'];

        $this->actingAs($first, 'sanctum')->postJson('/api/v1/iap/verify', $payload)->assertCreated();
        $this->actingAs($first, 'sanctum')->postJson('/api/v1/iap/verify', $payload)->assertOk();
        $this->actingAs($second, 'sanctum')->postJson('/api/v1/iap/verify', $payload)->assertConflict();

        $this->assertDatabaseCount('iap_receipts', 1);
        $this->assertSame(500, (int) FinancialAccount::where('owner_id', $first->id)->where('type', 'gift_wallet')->value('balance'));
        $this->assertSame(0, DB::table('ledger_entries')->where('ledger_transaction_id', DB::table('iap_receipts')->value('ledger_transaction_id'))->sum('amount'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => isset($request['receipt']));
    }

    public function test_earn_rejects_parallel_replayed_and_impossible_heartbeats_and_preserves_review_without_credit(): void
    {
        config()->set(['finance.public_enabled' => true, 'services.earn_integrity.url' => 'https://integrity.test/check']);
        Http::preventStrayRequests();
        Http::fake(['https://integrity.test/check' => Http::response(['valid' => true])]);
        [$user, $device, $episode] = $this->listenerEpisode(1200);
        $started = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertCreated()->assertJsonPath('data.expected_award', 3);
        $session = $started->json('data.id');
        $nonce = $started->json('data.nonce');
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertConflict();
        $heartbeat = ['position' => 30, 'sequence' => 1, 'elapsed_seconds' => 30, 'playback_rate' => 1, 'foreground' => true, 'audio_active' => true, 'integrity_token' => 'attested-token', 'nonce' => $nonce, 'audio_fingerprint' => 'audio-one'];
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/sessions/{$session}/heartbeat", $heartbeat)->assertOk();
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/sessions/{$session}/heartbeat", $heartbeat)->assertConflict();
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'earn-complete')->postJson("/api/v1/earn/sessions/{$session}/complete")->assertConflict();
        $this->assertDatabaseHas('earn_sessions', ['id' => $session, 'state' => 'review', 'risk_state' => 'review']);
        $this->assertDatabaseCount('earn_awards', 0);
    }

    public function test_earn_completion_is_exactly_once_and_enforces_the_72_hour_lock(): void
    {
        config()->set('finance.public_enabled', true);
        [$user, $device, $episode] = $this->listenerEpisode(600);
        $session = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->json('data.id');
        DB::table('earn_sessions')->where('id', $session)->update(['verified_seconds' => 600]);

        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'earn-once')->postJson("/api/v1/earn/sessions/{$session}/complete")->assertCreated()->assertJsonPath('data.coins', 2);
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'earn-once')->postJson("/api/v1/earn/sessions/{$session}/complete")->assertOk();
        $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertConflict()->assertJsonPath('error.code', 'EPISODE_LOCKED');
        $this->assertDatabaseCount('earn_awards', 1);
        $this->assertSame(2, (int) FinancialAccount::where('owner_id', $user->id)->where('type', 'earn_wallet')->value('balance'));
    }

    public function test_ledger_reversal_is_immutable_idempotent_and_reconciliation_detects_drift(): void
    {
        $source = FinancialAccount::create(['type' => 'platform', 'unit' => 'PCN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'opening_balance', 'unit' => 'PCN', 'balance' => 0]);
        $destination = FinancialAccount::create(['type' => 'creator_balance', 'unit' => 'PCN', 'balance' => 0]);
        $post = app(PostLedgerTransaction::class);
        $post->handle('account.opened', 'opening-1', 'PCN', [['account_id' => $funding->id, 'amount' => -100], ['account_id' => $source->id, 'amount' => 100]]);
        $original = $post->handle('test.charge', 'charge-1', 'PCN', [['account_id' => $source->id, 'amount' => -40], ['account_id' => $destination->id, 'amount' => 40]]);
        $reversal = app(ReverseLedgerTransaction::class)->handle($original->id, 'reverse-1', 'Correcting an invalid charge.');
        $again = app(ReverseLedgerTransaction::class)->handle($original->id, 'reverse-2', 'Duplicate reversal request.');
        $this->assertSame($reversal->id, $again->id);
        $this->assertSame(100, $source->fresh()->balance);
        $this->assertSame(0, $destination->fresh()->balance);
        $this->assertSame(2, DB::table('ledger_entries')->where('ledger_transaction_id', $reversal->id)->count());

        $source->update(['balance' => 99]);
        (new RunReconciliation('2026-09-08'))->handle();
        $this->assertDatabaseHas('reconciliation_runs', ['business_date' => '2026-09-08', 'state' => 'drift', 'drift_count' => 1]);
        $this->assertDatabaseHas('reconciliation_items', ['financial_account_id' => $source->id, 'difference' => -1, 'state' => 'open']);

        $this->expectException(InvalidArgumentException::class);
        $post->handle('different.event', 'charge-1', 'PCN', [['account_id' => $source->id, 'amount' => -20], ['account_id' => $destination->id, 'amount' => 20]]);
    }

    public function test_gift_send_is_balanced_fee_split_replay_safe_and_insufficient_funds_safe(): void
    {
        config()->set('finance.public_enabled', true);
        $sender = User::factory()->create();
        $creatorUser = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => $creatorUser->id, 'display_name' => 'Creator']);
        $giftType = GiftType::create(['slug' => 'applause-test', 'name' => 'Applause', 'coins' => 100, 'version' => 1]);
        $wallet = FinancialAccount::create(['owner_type' => get_class($sender), 'owner_id' => $sender->id, 'type' => 'gift_wallet', 'unit' => 'PCN', 'balance' => 0]);
        $funding = FinancialAccount::create(['type' => 'iap_liability', 'unit' => 'PCN', 'balance' => 0]);
        app(PostLedgerTransaction::class)->handle('test.iap', 'gift-funding', 'PCN', [['account_id' => $funding->id, 'amount' => -100], ['account_id' => $wallet->id, 'amount' => 100]]);
        $payload = ['gift_type_id' => $giftType->id, 'creator_profile_id' => $creator->id, 'message' => 'Thank you'];

        $this->actingAs($sender, 'sanctum')->withHeader('Idempotency-Key', 'gift-once')->postJson('/api/v1/gifts/send', $payload)->assertCreated();
        $this->actingAs($sender, 'sanctum')->withHeader('Idempotency-Key', 'gift-once')->postJson('/api/v1/gifts/send', $payload)->assertCreated();
        $this->actingAs($sender, 'sanctum')->withHeader('Idempotency-Key', 'gift-twice')->postJson('/api/v1/gifts/send', $payload)->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_COINS');
        $this->assertDatabaseCount('gifts', 1);
        $this->assertNotNull(DB::table('gifts')->value('fee_version_id'));
        $this->assertSame(0, $wallet->fresh()->balance);
        $this->assertSame(90, (int) FinancialAccount::where('owner_id', $creator->id)->where('type', 'creator_balance')->value('balance'));
        $this->assertSame(10, (int) FinancialAccount::whereNull('owner_id')->where('type', 'gift_platform_fee')->value('balance'));
        $transaction = DB::table('gifts')->value('ledger_transaction_id');
        $this->assertSame(0, DB::table('ledger_entries')->where('ledger_transaction_id', $transaction)->sum('amount'));
    }

    public function test_database_rejects_ledger_entry_updates_and_deletes(): void
    {
        $left = FinancialAccount::create(['type' => 'immutable_left', 'unit' => 'PCN', 'balance' => 0]);
        $right = FinancialAccount::create(['type' => 'immutable_right', 'unit' => 'PCN', 'balance' => 0]);
        $transaction = app(PostLedgerTransaction::class)->handle('immutable.test', 'immutable-entry', 'PCN', [['account_id' => $left->id, 'amount' => -1], ['account_id' => $right->id, 'amount' => 1]]);
        $entry = DB::table('ledger_entries')->where('ledger_transaction_id', $transaction->id)->first();

        try {
            DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 99]);
            $this->fail('Ledger entry update unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
        try {
            DB::table('ledger_entries')->where('id', $entry->id)->delete();
            $this->fail('Ledger entry delete unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
        $this->assertDatabaseHas('ledger_entries', ['id' => $entry->id, 'amount' => $entry->amount]);
    }

    private function listenerEpisode(int $seconds): array
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'device-'.Str::random(6), 'name' => 'Phone']);
        $show = Show::create(['rss_url' => 'https://example.com/'.Str::random(8).'.xml', 'title' => 'Earn Show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Earn Episode', 'audio_url' => 'https://cdn.example.com/audio.mp3', 'duration_seconds' => $seconds]);

        return [$user, $device, $episode];
    }
}
