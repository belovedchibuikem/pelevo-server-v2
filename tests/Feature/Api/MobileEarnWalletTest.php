<?php

namespace Tests\Feature\Api;

use App\Jobs\UnlockExpiredEarnAwards;
use App\Models\ConfigurationVersion;
use App\Models\Device;
use App\Models\Episode;
use App\Models\FinancialAccount;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileEarnWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wallet_and_history_require_authentication(): void
    {
        $this->getJson('/api/v1/earn/wallet')->assertUnauthorized();
        $this->getJson('/api/v1/withdrawals')->assertUnauthorized();
    }

    public function test_empty_wallet_has_no_seeded_balance_or_created_account(): void
    {
        $user = User::factory()->create();
        config(['finance.public_enabled' => false, 'finance.earn_min_withdraw_coins' => 1250]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/earn/wallet')->assertOk()
            ->assertJsonPath('data.user_id', $user->id)->assertJsonPath('data.balance', '0')
            ->assertJsonPath('data.minimum_withdrawal', '1250')->assertJsonPath('data.withdrawals_enabled', false);
        $this->assertDatabaseCount('financial_accounts', 0);
    }

    public function test_wallet_is_owner_and_unit_scoped_with_exact_amounts_and_effective_threshold(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([[$user, 'earn_wallet', 'ECN', '9007199254740993'], [$other, 'earn_wallet', 'ECN', 999], [$user, 'gift_wallet', 'PCN', 500]] as [$owner, $type, $unit, $balance]) {
            FinancialAccount::create(['owner_type' => User::class, 'owner_id' => $owner->id, 'type' => $type, 'unit' => $unit, 'balance' => $balance]);
        }
        ConfigurationVersion::create(['version' => 1, 'payload' => ['money' => ['earn_min_withdraw_coins' => 2000]], 'reason' => 'Test threshold', 'effective_at' => now()->subDay()]);
        ConfigurationVersion::create(['version' => 2, 'payload' => ['money' => ['earn_min_withdraw_coins' => 3000]], 'reason' => 'Future threshold', 'effective_at' => now()->addDay()]);
        config(['finance.public_enabled' => true]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/earn/wallet')->assertOk()
            ->assertJsonPath('data.balance', '9007199254740993')->assertJsonPath('data.minimum_withdrawal', '2000')->assertJsonPath('data.withdrawals_enabled', true);
    }

    private function withdrawal(User $user): string
    {
        $method = (string) Str::ulid();
        $transaction = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('payout_methods')->insert(['id' => $method, 'owner_type' => User::class, 'owner_id' => $user->id, 'provider' => 'paystack', 'destination_encrypted' => 'private-test-value', 'destination_last_four' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_transactions')->insert(['id' => $transaction, 'reference' => $transaction, 'event_type' => 'withdrawal.reserved', 'idempotency_key' => $transaction, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $user->id, 'payout_method_id' => $method, 'ledger_transaction_id' => $transaction, 'coins' => 1500, 'state' => 'queued', 'idempotency_key' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_history_paginates_tied_dates_without_exposing_other_users_or_private_fields(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $ids = [$this->withdrawal($user), $this->withdrawal($user)];
        $otherId = $this->withdrawal(User::factory()->create());
        rsort($ids);
        $first = $this->actingAs($user, 'sanctum')->getJson('/api/v1/withdrawals?limit=1')->assertOk()
            ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.0.coins', '1500')
            ->assertJsonPath('meta.user_id', $user->id)->assertJsonPath('meta.has_more', true);
        $this->assertSame(['id', 'coins', 'state', 'created_at'], array_keys($first->json('data.0')));
        $this->getJson('/api/v1/withdrawals?limit=1&cursor='.urlencode($first->json('meta.cursor')))->assertOk()
            ->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('meta.has_more', false)->assertJsonMissing(['id' => $otherId]);
    }

    public function test_history_validates_page_size_and_returns_real_empty_state(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson('/api/v1/withdrawals?limit=51')->assertUnprocessable();
        $this->getJson('/api/v1/withdrawals?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/withdrawals')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.cursor', null);
    }

    public function test_earn_catalog_presents_eligible_public_fields_without_feed_secrets(): void
    {
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.com/earn-catalog.xml', 'title' => 'The Honest Bunch', 'author' => 'With Toke & Friends', 'artwork_url' => 'https://cdn.example.com/honest.png', 'earn_enabled' => true]);
        $short = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Too short', 'audio_url' => 'https://cdn.example.com/short.mp3', 'duration_seconds' => 30, 'published_at' => now()]);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Train Your Mind Daily', 'audio_url' => 'https://cdn.example.com/earn.mp3', 'duration_seconds' => 600, 'published_at' => now()]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/earn/shows')->assertOk()
            ->assertJsonPath('data.0.id', $show->id)->assertJsonPath('data.0.title', 'The Honest Bunch')
            ->assertJsonPath('data.0.author', 'With Toke & Friends')->assertJsonPath('data.0.episodes_count', 1)
            ->assertJsonMissing(['rss_url', $show->rss_url])->assertJsonPath('meta.has_more', false);
        $this->assertSame(['id', 'title', 'author', 'artwork_url', 'episodes_count', 'niche'], array_keys($this->getJson('/api/v1/earn/shows')->json('data.0')));
        $listed = $this->getJson('/api/v1/earn/episodes')->assertOk()
            ->assertJsonPath('data.0.id', $episode->id)->assertJsonPath('data.0.show_id', $show->id)
            ->assertJsonPath('data.0.show_title', 'The Honest Bunch')->assertJsonPath('data.0.duration_seconds', 600)
            ->assertJsonMissing(['guid' => $episode->guid, 'id' => $short->id]);
        $this->assertSame(['id', 'show_id', 'show_title', 'show_author', 'title', 'artwork_url', 'duration_seconds', 'audio_url', 'niche', 'coins', 'locked', 'locked_until'], array_keys($listed->json('data.0')));
        $this->assertFalse($listed->json('data.0.locked'));
        $this->assertSame(2, $listed->json('data.0.coins'));
        $this->getJson('/api/v1/earn/episodes?show_id='.$show->id)->assertOk()->assertJsonPath('data.0.id', $episode->id);
    }

    public function test_started_session_and_award_omit_integrity_and_ledger_secrets(): void
    {
        config()->set('finance.public_enabled', true);
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'device-catalog', 'name' => 'Phone']);
        $show = Show::create(['rss_url' => 'https://example.com/earn-session.xml', 'title' => 'Earn Show', 'earn_enabled' => true]);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Earn Episode', 'audio_url' => 'https://cdn.example.com/audio.mp3', 'duration_seconds' => 600]);
        $started = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertCreated()
            ->assertJsonPath('data.episode_id', $episode->id)->assertJsonPath('data.expected_award', 2)->assertJsonPath('data.lock_hours', 72);
        $this->assertSame(['id', 'episode_id', 'expected_award', 'nonce', 'nonce_expires_at', 'lock_hours'], array_keys($started->json('data')));
        $this->assertSame(64, strlen($started->json('data.nonce')));
        DB::table('earn_sessions')->where('id', $started->json('data.id'))->update(['verified_seconds' => 600, 'last_position' => 600]);
        $award = $this->withHeader('Idempotency-Key', 'earn-catalog')->postJson('/api/v1/earn/sessions/'.$started->json('data.id').'/complete')->assertCreated();
        $this->assertSame(['id', 'episode_id', 'coins', 'locked_until'], array_keys($award->json('data')));
        $this->assertSame(2, $award->json('data.coins'));
        $this->assertArrayNotHasKey('ledger_transaction_id', $award->json('data'));
    }

    public function test_earn_catalog_lists_only_marked_shows_and_hides_locked_audio(): void
    {
        $user = User::factory()->create();
        $marked = Show::create(['rss_url' => 'https://example.com/marked.xml', 'title' => 'Marked Earn', 'earn_enabled' => true, 'status' => 'active']);
        $indexOnly = Show::create(['rss_url' => 'https://example.com/index.xml', 'title' => 'Podcast Index Import', 'earn_enabled' => false, 'status' => 'active']);
        DB::table('show_external_ids')->insert(['show_id' => $indexOnly->id, 'provider' => 'podcast_index', 'external_id' => '9001']);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Business', 'slug' => 'business', 'position' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_show')->insert(['category_id' => $categoryId, 'show_id' => $marked->id]);
        Episode::create(['show_id' => $marked->id, 'guid' => (string) Str::ulid(), 'title' => 'Monetized episode', 'audio_url' => 'https://cdn.example.com/marked.mp3', 'duration_seconds' => 700, 'published_at' => now()]);
        Episode::create(['show_id' => $indexOnly->id, 'guid' => (string) Str::ulid(), 'title' => 'Index episode', 'audio_url' => 'https://cdn.example.com/index.mp3', 'duration_seconds' => 700, 'published_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/earn/shows')->assertOk()
            ->assertJsonPath('data.0.title', 'Marked Earn')->assertJsonPath('data.0.niche', 'Business')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/earn/episodes')->assertOk()->assertJsonPath('data.0.title', 'Monetized episode')->assertJsonPath('data.0.coins', 2)->assertJsonCount(1, 'data');
        $this->withHeader('X-Test-Country', 'NG')->getJson('/api/v1/earn/access')->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.country', 'NG');
        $this->withHeader('X-Test-Country', 'NG')->getJson('/api/v1/earn/shows')->assertForbidden();
    }

    public function test_unlock_job_releases_an_episode_only_after_the_lock_expires(): void
    {
        config()->set('finance.public_enabled', true);
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'device-unlock', 'name' => 'Phone']);
        $show = Show::create(['rss_url' => 'https://example.com/unlock.xml', 'title' => 'Unlock Show', 'earn_enabled' => true]);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Unlock Episode', 'audio_url' => 'https://cdn.example.com/unlock.mp3', 'duration_seconds' => 600]);
        $started = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertCreated();
        DB::table('earn_sessions')->where('id', $started->json('data.id'))->update(['verified_seconds' => 600, 'last_position' => 600]);
        $this->withHeader('Idempotency-Key', 'unlock-once')->postJson('/api/v1/earn/sessions/'.$started->json('data.id').'/complete')->assertCreated();
        $this->assertNull(DB::table('earn_awards')->value('unlocked_at'));
        (new UnlockExpiredEarnAwards)->handle();
        $this->assertNull(DB::table('earn_awards')->value('unlocked_at'));
        DB::table('earn_awards')->update(['locked_until' => now()->subMinute()]);
        (new UnlockExpiredEarnAwards)->handle();
        $this->assertNotNull(DB::table('earn_awards')->value('unlocked_at'));
        $this->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertCreated();
    }

    public function test_completion_requires_nearly_full_playback_at_normal_speed(): void
    {
        config()->set('finance.public_enabled', true);
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_identifier' => 'device-speed', 'name' => 'Phone']);
        $show = Show::create(['rss_url' => 'https://example.com/speed.xml', 'title' => 'Speed Show', 'earn_enabled' => true]);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => (string) Str::ulid(), 'title' => 'Speed Episode', 'audio_url' => 'https://cdn.example.com/speed.mp3', 'duration_seconds' => 600]);
        $started = $this->actingAs($user, 'sanctum')->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/episodes/{$episode->id}/sessions")->assertCreated();
        $session = $started->json('data.id');
        $nonce = $started->json('data.nonce');
        $this->withHeader('X-Device-Id', $device->device_identifier)->postJson("/api/v1/earn/sessions/{$session}/heartbeat", [
            'position' => 30, 'sequence' => 1, 'elapsed_seconds' => 20, 'playback_rate' => 2, 'foreground' => true, 'audio_active' => true, 'integrity_token' => 'token', 'nonce' => $nonce,
        ])->assertConflict();
        DB::table('earn_sessions')->where('id', $session)->update(['state' => 'active', 'risk_state' => 'clear', 'verified_seconds' => 100, 'last_position' => 100]);
        $this->withHeader('Idempotency-Key', 'too-short')->postJson("/api/v1/earn/sessions/{$session}/complete")->assertConflict()->assertJsonPath('error.code', 'MODERATION_HOLD');
        $this->assertDatabaseCount('earn_awards', 0);
    }
}
