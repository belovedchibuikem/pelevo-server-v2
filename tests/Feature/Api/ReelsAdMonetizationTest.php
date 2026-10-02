<?php

namespace Tests\Feature\Api;

use App\Jobs\ReleaseReconciledReelAdRevenue;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ReelsAdMonetizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_native_impression_allocates_and_releases_usd_and_ngn(): void
    {
        config()->set('reels_ads.enabled', true);
        config()->set('reels_ads.allocation_enabled', true);
        config()->set('reels_ads.threshold_min', 2);
        config()->set('reels_ads.threshold_max', 2);
        config()->set('reels_ads.hold_days', 30);
        config()->set('reels_ads.reserve_bps', 500);

        $fan = User::factory()->create();
        $firstCreator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'First']);
        $secondCreator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Second']);
        $firstReel = $this->reel($firstCreator->id);
        $secondReel = $this->reel($secondCreator->id);
        $feedSession = (string) Str::uuid();
        $headers = ['X-Platform' => 'android', 'X-Device-Id' => 'test-installation'];

        $first = $this->completeReel($fan, $firstReel, $feedSession, $headers);
        $first->assertJsonPath('data.ad_due', null);

        $second = $this->completeReel($fan, $secondReel, $feedSession, $headers);
        $impressionId = $second->json('data.ad_due.ad_impression_id');
        $this->assertNotEmpty($impressionId);
        $this->assertDatabaseCount('ad_impression_attributions', 2);
        $this->assertDatabaseCount('creator_ad_revenue_allocations', 0);

        $eventId = (string) Str::uuid();
        $this->withHeaders($headers)->actingAs($fan, 'sanctum')->postJson(
            "/api/v1/reels/ad-impressions/$impressionId/watched",
            ['client_event_id' => $eventId, 'feed_session_id' => $feedSession],
        )->assertOk()->assertJsonPath('data.status', 'watched');
        $this->withHeaders($headers)->actingAs($fan, 'sanctum')->postJson(
            "/api/v1/reels/ad-impressions/$impressionId/watched",
            ['client_event_id' => $eventId, 'feed_session_id' => $feedSession],
        )->assertOk()->assertJsonPath('data.idempotent', true);

        $this->assertDatabaseCount('creator_ad_revenue_allocations', 2);
        $this->assertSame(
            [100, 100],
            DB::table('creator_ad_revenue_allocations')->orderBy('gross_usd_micros')->pluck('gross_usd_micros')->map(fn ($amount) => (int) $amount)->all(),
        );

        DB::table('creator_ad_revenue_allocations')->update(['held_until' => now()->subSecond()]);
        $this->assertSame(200, (int) DB::table('financial_accounts')->where('type', 'reels_ad_pending')->sum('balance'));
        $this->assertSame(0, (int) DB::table('financial_accounts')->where('type', 'reels_ad_available')->sum('balance'));
        $this->payoutSetting($firstCreator->id, 'USD');
        $this->payoutSetting($secondCreator->id, 'NGN');
        DB::table('fx_rate_versions')->insert([
            'id' => (string) Str::ulid(),
            'base_unit' => 'USD',
            'quote_currency' => 'NGN',
            'rate' => '1600.00000000',
            'source' => 'test',
            'approval_state' => 'approved',
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchId = (string) Str::ulid();
        DB::table('admob_reconciliation_batches')->insert([
            'id' => $batchId,
            'admob_account_id' => 'pub-test',
            'statement_month' => now()->startOfMonth()->toDateString(),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'finalized_usd_micros' => 100,
            'estimated_usd_micros' => 200,
            'adjustment_usd_micros' => -100,
            'source_checksum' => str_repeat('a', 64),
            'status' => 'approved',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ReleaseReconciledReelAdRevenue::dispatchSync($batchId);

        $this->assertDatabaseHas('creator_ad_revenue_allocations', [
            'creator_profile_id' => $firstCreator->id,
            'status' => 'released',
            'confirmed_usd_micros' => 50,
            'available_currency' => 'USD',
        ]);
        $this->assertDatabaseHas('creator_ad_revenue_allocations', [
            'creator_profile_id' => $secondCreator->id,
            'status' => 'released',
            'confirmed_usd_micros' => 50,
            'available_currency' => 'NGN',
            'available_amount_minor' => 8,
        ]);
        $this->assertDatabaseCount('currency_conversions', 1);
        $this->assertSame(5, (int) DB::table('financial_accounts')->where('type', 'reels_ad_reserve')->where('unit', 'USD')->sum('balance'));
        $this->assertContains(
            (int) DB::table('creator_ad_revenue_allocations')->where('creator_profile_id', $firstCreator->id)->value('available_amount_minor'),
            [47, 48],
        );
    }

    public function test_bounced_ad_never_allocates_creator_money(): void
    {
        config()->set('reels_ads.enabled', true);
        config()->set('reels_ads.allocation_enabled', true);
        config()->set('reels_ads.threshold_min', 1);
        config()->set('reels_ads.threshold_max', 1);

        $fan = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Creator']);
        $feedSession = (string) Str::uuid();
        $response = $this->completeReel(
            $fan,
            $this->reel($creator->id),
            $feedSession,
            ['X-Platform' => 'ios', 'X-Device-Id' => 'ios-device'],
        );
        $impressionId = $response->json('data.ad_due.ad_impression_id');

        $this->actingAs($fan, 'sanctum')->postJson(
            "/api/v1/reels/ad-impressions/$impressionId/bounce",
            ['feed_session_id' => $feedSession, 'reason' => 'skipped'],
        )->assertOk();

        $this->assertDatabaseHas('ad_impressions', ['id' => $impressionId, 'status' => 'bounced']);
        $this->assertDatabaseCount('creator_ad_revenue_allocations', 0);
    }

    public function test_ad_cycle_progress_survives_new_feed_session(): void
    {
        config()->set('reels_ads.enabled', true);
        config()->set('reels_ads.threshold_min', 2);
        config()->set('reels_ads.threshold_max', 2);

        $fan = User::factory()->create();
        $creator = CreatorProfile::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Creator']);
        $headers = ['X-Platform' => 'android', 'X-Device-Id' => 'android-device'];

        $this->completeReel($fan, $this->reel($creator->id), (string) Str::uuid(), $headers)
            ->assertJsonPath('data.ad_due', null);

        $reopenedFeed = (string) Str::uuid();
        $second = $this->completeReel($fan, $this->reel($creator->id), $reopenedFeed, $headers);
        $impressionId = $second->json('data.ad_due.ad_impression_id');

        $this->assertNotEmpty($impressionId);
        $this->assertDatabaseHas('ad_impressions', ['id' => $impressionId, 'feed_session_id' => $reopenedFeed]);
        $this->assertDatabaseCount('ad_impression_attributions', 2);
    }

    private function reel(string $creatorId): string
    {
        $id = (string) Str::ulid();
        DB::table('reels')->insert([
            'id' => $id,
            'creator_profile_id' => $creatorId,
            'state' => 'published',
            'duration_ms' => 5000,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function completeReel(User $user, string $reel, string $feedSession, array $headers): TestResponse
    {
        $session = (string) Str::uuid();
        $this->withHeaders($headers)->actingAs($user, 'sanctum')->postJson(
            "/api/v1/reels/$reel/view-heartbeat",
            [
                'session_id' => $session,
                'feed_session_id' => $feedSession,
                'sequence' => 1,
                'playback_position_ms' => 0,
                'completed' => false,
                'app_foreground' => true,
                'audible' => true,
                'ad_eligible' => true,
            ],
        )->assertOk();
        $this->travel(5)->seconds();

        return $this->withHeaders($headers)->actingAs($user, 'sanctum')->postJson(
            "/api/v1/reels/$reel/view-heartbeat",
            [
                'session_id' => $session,
                'feed_session_id' => $feedSession,
                'sequence' => 2,
                'playback_position_ms' => 5000,
                'completed' => true,
                'app_foreground' => true,
                'audible' => true,
                'ad_eligible' => true,
            ],
        )->assertOk();
    }

    private function payoutSetting(string $creatorId, string $currency): void
    {
        DB::table('creator_payout_settings')->insert([
            'creator_profile_id' => $creatorId,
            'currency' => $currency,
            'ad_payout_currency' => $currency,
            'ad_payout_currency_effective_at' => now(),
            'schedule' => 'monthly',
            'minimum_amount' => 1000,
            'compliance_state' => 'verified',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
