<?php

namespace Tests\Feature\Notifications;

use App\Events\NewEpisodePublished;
use App\Jobs\DeliverInAppNotification;
use App\Jobs\DispatchNotificationBroadcast;
use App\Listeners\CreateNewEpisodeNotifications;
use App\Models\Admin;
use App\Models\Episode;
use App\Models\Show;
use App\Models\User;
use App\Services\InAppNotificationDelivery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InAppNotificationDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function preferences(User $user, array $values = [], array $options = []): void
    {
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $user->id], [
            'new_episodes' => true, 'push_enabled' => false, 'timezone' => 'UTC',
            'mobile_options' => json_encode($options, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(), ...$values,
        ]);
    }

    private function message(): array
    {
        return ['type' => 'support_reply', 'key' => 'support-reply:'.Str::ulid(), 'title' => 'Support response', 'body' => 'Your ticket was updated.', 'data' => []];
    }

    public function test_delivery_is_idempotent_and_does_not_restore_archived_messages(): void
    {
        $user = User::factory()->create();
        $message = $this->message();
        $delivery = app(InAppNotificationDelivery::class);
        $this->assertSame('delivered', $delivery->deliver($user->id, $message));
        DB::table('notifications')->where('user_id', $user->id)->update(['dismissed_at' => now()]);
        $this->assertSame('delivered', $delivery->deliver($user->id, $message));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull(DB::table('notifications')->value('dismissed_at'));
    }

    public function test_channel_account_and_feature_gates_prevent_inbox_writes(): void
    {
        Queue::fake([DeliverInAppNotification::class]);
        $user = User::factory()->create();
        $delivery = app(InAppNotificationDelivery::class);
        $this->preferences($user, options: ['in_app_enabled' => false]);
        $this->assertSame('suppressed', $delivery->deliver($user->id, $this->message()));
        $this->preferences($user);
        $user->update(['status' => 'suspended']);
        $this->assertSame('suppressed', $delivery->deliver($user->id, $this->message()));
        $user->update(['status' => 'active']);
        config(['features.notifications' => false]);
        $this->assertSame('suppressed', $delivery->deliver($user->id, $this->message()));
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public static function quietWindows(): array
    {
        return [
            'overnight before midnight' => ['2026-09-10 22:30:00', '22:00', '07:00', 'Africa/Lagos', '2026-09-11 06:00:00'],
            'overnight after midnight' => ['2026-09-11 03:30:00', '22:00', '07:00', 'Africa/Lagos', '2026-09-11 06:00:00'],
            'same day' => ['2026-09-10 10:00:00', '09:00', '12:00', 'UTC', '2026-09-10 12:00:00'],
            'start inclusive' => ['2026-09-10 09:00:00', '09:00', '12:00', 'UTC', '2026-09-10 12:00:00'],
            'daylight saving change' => ['2026-03-08 05:30:00', '22:00', '07:00', 'America/New_York', '2026-03-08 11:00:00'],
        ];
    }

    #[DataProvider('quietWindows')]
    public function test_quiet_hours_defer_until_local_end(string $now, string $start, string $end, string $timezone, string $resume): void
    {
        $this->travelTo(Carbon::parse($now, 'UTC'));
        Queue::fake([DeliverInAppNotification::class]);
        $user = User::factory()->create();
        $this->preferences($user, ['quiet_hours_start' => $start, 'quiet_hours_end' => $end, 'timezone' => $timezone]);
        $this->assertSame('deferred', app(InAppNotificationDelivery::class)->deliver($user->id, $this->message()));
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertPushed(DeliverInAppNotification::class, function ($job) use ($resume): bool {
            return $job->connection === 'database' && $job->queue === 'notifications' && $job->afterCommit === true
                && $job->delay->format('Y-m-d H:i:s') === $resume;
        });
    }

    public function test_deferred_job_rechecks_preferences_and_expires_without_delivery(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        Queue::fake([DeliverInAppNotification::class]);
        $user = User::factory()->create();
        $this->preferences($user, ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $delivery = app(InAppNotificationDelivery::class);
        $delivery->deliver($user->id, $this->message());
        $job = Queue::pushed(DeliverInAppNotification::class)->first();
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $this->preferences($user, options: ['in_app_enabled' => false]);
        $job->handle($delivery);
        $this->assertDatabaseCount('notifications', 0);
        $this->preferences($user);
        $this->travel(8)->days();
        $job->handle($delivery);
        $this->assertSame('expired', $delivery->deliver($user->id, $job->message, $job->expiresAt));
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertPushed(DeliverInAppNotification::class, 1);
    }

    public function test_end_boundary_delivers_once_with_push_disabled(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        Queue::fake([DeliverInAppNotification::class]);
        $user = User::factory()->create();
        $this->preferences($user, ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $delivery = app(InAppNotificationDelivery::class);
        $delivery->deliver($user->id, $this->message());
        $job = Queue::pushed(DeliverInAppNotification::class)->first();
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $job->handle($delivery);
        $job->handle($delivery);
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(DeliverInAppNotification::class, 1);
    }

    public function test_episode_producer_respects_category_priority_and_follow_changes(): void
    {
        Queue::fake([DeliverInAppNotification::class]);
        $this->travelTo(now()->setTime(23, 0));
        $user = User::factory()->create();
        $show = Show::create(['rss_url' => 'https://example.test/feed', 'title' => 'Test show']);
        $episode = Episode::create(['show_id' => $show->id, 'guid' => 'one', 'title' => 'Episode', 'audio_url' => 'https://example.test/audio']);
        $user->followedShows()->attach($show);
        $listener = new CreateNewEpisodeNotifications;
        $this->preferences($user, options: ['types' => []]);
        $listener->handle(new NewEpisodePublished($episode));
        $this->preferences($user, options: ['high_priority_only' => true]);
        $listener->handle(new NewEpisodePublished($episode));
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
        $this->preferences($user, ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $listener->handle(new NewEpisodePublished($episode));
        $job = Queue::pushed(DeliverInAppNotification::class)->first();
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $user->followedShows()->detach($show);
        $job->handle(app(InAppNotificationDelivery::class));
        $this->assertDatabaseCount('notifications', 0);
        $user->followedShows()->attach($show);
        $episode->update(['availability' => 'unavailable']);
        $job->handle(app(InAppNotificationDelivery::class));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_broadcast_receipts_distinguish_suppressed_deferred_and_delivered(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        Queue::fake([DeliverInAppNotification::class]);
        $enabled = User::factory()->create();
        $disabled = User::factory()->create();
        $this->preferences($enabled, ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->preferences($disabled, options: ['in_app_enabled' => false]);
        $admin = Admin::create(['name' => 'Operator', 'email' => 'notify@example.test', 'password' => 'Test-password-9!', 'status' => 'active']);
        $template = (string) Str::ulid();
        $broadcast = (string) Str::ulid();
        DB::table('notification_templates')->insert(['id' => $template, 'key' => 'test', 'version' => 1, 'title' => 'Update', 'body' => 'Service update', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_broadcasts')->insert(['id' => $broadcast, 'notification_template_id' => $template, 'audience' => '{}', 'state' => 'scheduled', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        (new DispatchNotificationBroadcast($broadcast))->handle();
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $enabled->id, 'state' => 'deferred', 'delivered_at' => null]);
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $disabled->id, 'state' => 'suppressed', 'delivered_at' => null]);
        $this->assertDatabaseCount('notifications', 0);
        $this->travelTo(now()->addDay()->setTime(7, 0));
        $job = Queue::pushed(DeliverInAppNotification::class)->first();
        $job->failed(new \RuntimeException('Internal diagnostic details'));
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $enabled->id, 'state' => 'failed', 'delivered_at' => null]);
        $job->handle(app(InAppNotificationDelivery::class));
        $job->handle(app(InAppNotificationDelivery::class));
        $job->failed(new \RuntimeException('Late duplicate failure'));
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $enabled->id, 'state' => 'delivered']);
        $this->assertNotNull(DB::table('notification_deliveries')->where('user_id', $enabled->id)->value('delivered_at'));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }
}
