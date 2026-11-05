<?php

namespace Tests\Feature\Api;

use App\Integrations\Payments\GooglePlayDeveloperClient;
use App\Models\User;
use App\Support\PremiumTrialOffer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GooglePlayReceiptVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'finance.public_enabled' => true,
            'services.google.verification_url' => null,
            'services.google.application_id' => 'com.Podemeraldltd.pelevo',
        ]);
    }

    public function test_google_coin_receipt_is_verified_via_android_publisher_api(): void
    {
        $this->mock(GooglePlayDeveloperClient::class, function ($mock): void {
            $mock->shouldReceive('verifyPurchase')
                ->once()
                ->withArgs(function (string $receipt, ?string $productId): bool {
                    return $productId === 'coins_500'
                        && str_contains($receipt, 'coin-token-1');
                })
                ->andReturn([
                    'valid' => true,
                    'original_transaction_id' => 'GPA.coins-500',
                    'product_id' => 'coins_500',
                    'application_id' => 'com.Podemeraldltd.pelevo',
                    'quantity' => 1,
                    'environment' => 'sandbox',
                ]);
        });

        DB::table('coin_products')->insert([
            'id' => (string) Str::ulid(),
            'store' => 'google',
            'product_id' => 'coins_500',
            'coins' => 500,
            'unit' => 'PCN',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->forceFill(['age_majority_confirmed_at' => now()])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/iap/verify', [
                'store' => 'google',
                'product_id' => 'coins_500',
                'receipt' => json_encode([
                    'purchaseToken' => 'coin-token-1',
                    'productId' => 'coins_500',
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->assertJsonPath('data.coins', 500)
            ->assertJsonPath('data.remaining_balance', 500);
    }

    public function test_google_plus_receipt_is_verified_via_subscriptionsv2(): void
    {
        $this->mock(GooglePlayDeveloperClient::class, function ($mock): void {
            $mock->shouldReceive('verifyPurchase')
                ->once()
                ->andReturn([
                    'valid' => true,
                    'original_transaction_id' => 'GPA.plus-yearly-1',
                    'product_id' => PremiumTrialOffer::GOOGLE_PRODUCT_ID,
                    'base_plan_id' => PremiumTrialOffer::YEARLY_BASE_PLAN_ID,
                    'application_id' => 'com.Podemeraldltd.pelevo',
                    'quantity' => 1,
                    'expires_at' => now()->addYear()->toIso8601String(),
                    'environment' => 'sandbox',
                ]);
        });

        $user = User::factory()->create();
        $planId = (string) DB::table('premium_plans')->where('slug', 'plus-yearly')->value('id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/premium/store-purchase', [
                'store' => 'google',
                'receipt' => 'plus-purchase-token',
                'plan_id' => $planId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.entitlement.state', 'active')
            ->assertJsonPath('data.subscription.provider', 'google');
    }

    public function test_missing_play_credentials_returns_service_degraded(): void
    {
        $this->mock(GooglePlayDeveloperClient::class, function ($mock): void {
            $mock->shouldReceive('verifyPurchase')
                ->once()
                ->andThrow(new RuntimeException('IAP verification service is not configured.'));
        });

        $user = User::factory()->create();
        $user->forceFill(['age_majority_confirmed_at' => now()])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/iap/verify', [
                'store' => 'google',
                'receipt' => 'token',
                'product_id' => 'coins_500',
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_DEGRADED');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
