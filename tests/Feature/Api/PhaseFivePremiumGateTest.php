<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PhaseFivePremiumGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_checkout_is_replay_safe_and_only_verified_webhook_grants_entitlement_and_invoice(): void
    {
        config()->set(['premium.public_enabled' => true, 'services.paystack.checkout_url' => 'https://paystack.test/checkout', 'services.paystack.checkout_token' => 'secret', 'services.paystack.webhook_secret' => 'hook-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://paystack.test/checkout' => Http::response(['authorization_url' => 'https://paystack.test/pay'])]);
        $user = User::factory()->create();
        $plan = $this->plan();
        $checkout = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'premium-once')->postJson('/api/v1/premium/checkout', ['plan_id' => $plan, 'provider' => 'paystack'])->assertCreated()->assertJsonPath('data.state', 'pending')->assertJsonMissingPath('data.request_hash')->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.provider_reference');
        $this->assertSame(['id', 'state', 'checkout_url', 'expires_at', 'amount_minor', 'currency', 'provider'], array_keys($checkout->json('data')));
        $reference = DB::table('premium_checkouts')->where('id', $checkout->json('data.id'))->value('provider_reference');
        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'premium-once')->postJson('/api/v1/premium/checkout', ['plan_id' => $plan, 'provider' => 'paystack'])->assertOk();
        $this->assertDatabaseCount('premium_entitlements', 0);
        Http::assertSentCount(1);

        $payload = ['type' => 'payment.success', 'data' => ['reference' => $reference, 'amount_minor' => 2500, 'currency' => 'NGN', 'subscription_id' => 'sub-1', 'invoice_id' => 'invoice-1']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['X-Pelevo-Signature' => hash_hmac('sha256', $body, 'hook-secret'), 'X-Provider-Event-Id' => 'premium-event-1', 'Content-Type' => 'application/json'];
        $this->postJson('/webhooks/v1/paystack', $payload, $headers)->assertAccepted();
        $this->postJson('/webhooks/v1/paystack', $payload, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('premium_entitlements', 1);
        $this->assertDatabaseCount('premium_subscriptions', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/me')->assertOk()->assertJsonPath('data.entitlement.state', 'active');
    }

    public function test_amount_mismatch_is_quarantined_without_entitlement(): void
    {
        config()->set(['premium.public_enabled' => true, 'services.flutterwave.checkout_url' => 'https://flutterwave.test/checkout', 'services.flutterwave.checkout_token' => 'secret', 'services.flutterwave.webhook_secret' => 'hook-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://flutterwave.test/checkout' => Http::response(['data' => ['link' => 'https://flutterwave.test/pay']])]);
        $user = User::factory()->create();
        $checkoutId = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'premium-mismatch')->postJson('/api/v1/premium/checkout', ['plan_id' => $this->plan(), 'provider' => 'flutterwave'])->json('data.id');
        $reference = DB::table('premium_checkouts')->where('id', $checkoutId)->value('provider_reference');
        $payload = ['type' => 'charge.success', 'data' => ['reference' => $reference, 'amount' => 1, 'currency' => 'NGN']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->postJson('/webhooks/v1/flutterwave', $payload, ['X-Pelevo-Signature' => hash_hmac('sha256', $body, 'hook-secret'), 'X-Provider-Event-Id' => 'bad-amount', 'Content-Type' => 'application/json'])->assertAccepted()->assertJsonPath('review', true);
        $this->assertDatabaseCount('premium_entitlements', 0);
        $this->assertDatabaseHas('finance_exceptions', ['type' => 'premium_webhook', 'reference' => $reference, 'state' => 'open']);
    }

    private function plan(): string
    {
        $id = (string) Str::ulid();
        DB::table('premium_plans')->insertOrIgnore(['id' => $id, 'slug' => 'monthly', 'name' => 'Premium Monthly', 'price_minor' => 2500, 'currency' => 'NGN', 'interval' => 'month', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return (string) DB::table('premium_plans')->where('slug', 'monthly')->value('id');
    }

    public function test_plans_me_and_exclusive_omit_internal_fields(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $plans = $this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/plans')->assertOk();
        $this->assertSame(['id', 'slug', 'name', 'price_minor', 'currency', 'interval'], array_keys($plans->json('data.0')));
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/me')->assertOk()->assertJsonPath('data.entitlement', null);
        DB::table('premium_entitlements')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'premium_plan_id' => $plan, 'provider_reference' => 'test-entitlement', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $me = $this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/me')->assertOk()->assertJsonPath('data.entitlement.state', 'active');
        $this->assertSame(['state', 'starts_at', 'ends_at', 'plan_id'], array_keys($me->json('data.entitlement')));
        $show = \App\Models\Show::create(['rss_url' => 'https://example.com/plus.xml', 'title' => 'Plus Show']);
        $episode = \App\Models\Episode::create(['show_id' => $show->id, 'guid' => 'plus-ep', 'title' => 'Plus Episode', 'audio_url' => 'https://example.com/plus.mp3']);
        DB::table('premium_exclusive_episodes')->insert(['episode_id' => $episode->id, 'created_at' => now(), 'updated_at' => now()]);
        $exclusive = $this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/exclusive')->assertOk();
        $this->assertSame(['id', 'show_id', 'title', 'duration_seconds', 'published_at', 'show_title', 'artwork_url'], array_keys($exclusive->json('data.0')));
        $this->assertArrayNotHasKey('audio_url', $exclusive->json('data.0'));
        $this->assertArrayNotHasKey('guid', $exclusive->json('data.0'));
    }
}
