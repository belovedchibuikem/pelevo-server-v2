<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\PremiumTrialOffer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PremiumTrialOfferTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_and_annual_plans_publish_the_trial_and_checkout_does_not_charge(): void
    {
        config()->set('premium.public_enabled', true);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $plans = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/premium/plans')->assertOk()->json('data'));
        $monthly = $plans->firstWhere('slug', 'plus-monthly');
        $yearly = $plans->firstWhere('slug', 'plus-yearly');
        $this->assertSame(160000, $monthly['price_minor']);
        $this->assertSame('month', $monthly['interval']);
        $this->assertSame(3, $monthly['trial_months']);
        $this->assertSame(1760000, $yearly['price_minor']);
        $this->assertSame('year', $yearly['interval']);
        $this->assertSame(3, $yearly['trial_months']);
        $this->assertFalse($plans->contains(fn (array $plan): bool => $plan['slug'] === PremiumTrialOffer::SLUG));

        $checkout = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'trial-checkout')
            ->postJson('/api/v1/premium/checkout', ['plan_id' => $yearly['id'], 'provider' => 'paystack'])
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', 0)
            ->assertJsonPath('data.state', 'pending');
        $this->assertNull($checkout->json('data.checkout_url'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('premium_entitlements', 0);
    }

    public function test_banner_is_capped_to_every_eighth_session_and_eight_days(): void
    {
        $user = User::factory()->create();
        $this->planId('plus-yearly');
        for ($session = 1; $session <= 7; $session++) {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->sessions(), ['session_id' => 'session-'.$session])
                ->assertOk()
                ->assertJsonPath('data.eligible', false)
                ->assertJsonPath('data.premium', false)
                ->assertJsonPath('data.session_count', $session);
        }
        $this->actingAs($user, 'sanctum')
            ->postJson($this->sessions(), ['session_id' => 'session-8'])
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.session_count', 8);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->sessions(), ['session_id' => 'session-8'])
            ->assertOk()
            ->assertJsonPath('data.session_count', 8)
            ->assertJsonPath('data.eligible', true);

        $this->actingAs($user, 'sanctum')->postJson($this->shown())->assertOk()->assertJsonPath('data.slug', PremiumTrialOffer::SLUG);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->sessions(), ['session_id' => 'session-16'])
            ->assertOk()
            ->assertJsonPath('data.session_count', 9)
            ->assertJsonPath('data.eligible', false);

        Carbon::setTestNow(now()->addDays(8)->addMinute());
        for ($session = 10; $session <= 15; $session++) {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->sessions(), ['session_id' => 'session-'.$session])
                ->assertOk()
                ->assertJsonPath('data.eligible', false);
        }
        $this->actingAs($user, 'sanctum')
            ->postJson($this->sessions(), ['session_id' => 'session-after-cooldown'])
            ->assertOk()
            ->assertJsonPath('data.session_count', 16)
            ->assertJsonPath('data.eligible', true);
    }

    public function test_active_premium_never_sees_the_offer(): void
    {
        $user = User::factory()->create();
        $planId = $this->planId('plus-yearly');
        DB::table('premium_entitlements')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'premium_plan_id' => $planId,
            'provider_reference' => 'already-plus',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->sessions(), ['session_id' => 'plus-session'])
            ->assertOk()
            ->assertJsonPath('data.premium', true)
            ->assertJsonPath('data.eligible', false);
    }

    public function test_verified_store_receipt_starts_one_three_month_trial(): void
    {
        config()->set([
            'services.google.verification_url' => 'https://google.test/iap',
            'services.google.verification_token' => 'secret',
            'services.google.application_id' => 'com.pelevo.app',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://google.test/iap' => Http::response([
            'valid' => true,
            'original_transaction_id' => 'txn-trial-1',
            'product_id' => PremiumTrialOffer::YEARLY_PRODUCT_ID,
            'application_id' => 'com.pelevo.app',
            'quantity' => 1,
        ])]);
        $user = User::factory()->create();
        $planId = $this->planId('plus-yearly');
        $payload = ['store' => 'google', 'receipt' => 'receipt-token', 'plan_id' => $planId];
        $started = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/premium/store-purchase', $payload)
            ->assertCreated()
            ->assertJsonPath('data.entitlement.state', 'active')
            ->assertJsonPath('data.entitlement.plan_id', $planId)
            ->assertJsonPath('data.subscription.state', 'trialing')
            ->assertJsonPath('data.subscription.provider', 'google');
        $ends = Carbon::parse($started->json('data.entitlement.ends_at'));
        $this->assertTrue($ends->greaterThan(now()->addMonths(2)));
        $this->assertTrue($ends->lessThan(now()->addMonths(4)));
        $this->assertArrayNotHasKey('provider_reference', $started->json('data.entitlement'));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/premium/store-purchase', $payload)->assertCreated();
        $this->assertDatabaseCount('premium_entitlements', 1);
        $this->assertDatabaseCount('premium_subscriptions', 1);
        Http::assertSentCount(2);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/premium/store-purchase', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    private function planId(string $slug): string
    {
        return (string) DB::table('premium_plans')->where('slug', $slug)->value('id');
    }

    private function sessions(): string
    {
        return '/api/v1/premium/offers/'.PremiumTrialOffer::SLUG.'/sessions';
    }

    private function shown(): string
    {
        return '/api/v1/premium/offers/'.PremiumTrialOffer::SLUG.'/shown';
    }
}
