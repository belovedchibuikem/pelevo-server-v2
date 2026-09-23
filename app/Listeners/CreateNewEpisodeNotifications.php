<?php

namespace App\Listeners;

use App\Events\NewEpisodePublished;
use App\Mail\NewEpisodeAlert;
use App\Services\InAppNotificationDelivery;
use App\Services\MailPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CreateNewEpisodeNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [10, 60, 300];

    public function handle(NewEpisodePublished $event): void
    {
        if (! config('features.notifications')) {
            Log::warning('push.episode_fanout_skipped', [
                'episode_id' => $event->episode->id,
                'reason' => 'notifications_feature_disabled',
            ]);

            return;
        }
        $event->episode->loadMissing('show');
        $followersNotified = 0;
        DB::table('follows')->leftJoin('notification_preferences', 'notification_preferences.user_id', '=', 'follows.user_id')
            ->join('users', 'users.id', '=', 'follows.user_id')
            ->where('follows.show_id', $event->episode->show_id)
            ->where('follows.notifications_enabled', true)
            ->where(fn ($query) => $query->whereNull('notification_preferences.new_episodes')->orWhere('notification_preferences.new_episodes', true))
            ->select('follows.user_id')
            ->orderBy('follows.user_id')
            ->chunk(500, function ($followers) use ($event, &$followersNotified): void {
                foreach ($followers as $follower) {
                    $dedupeKey = 'new-episode:'.$event->episode->id;
                    $fresh = ! DB::table('notifications')->where('user_id', $follower->user_id)->where('deduplication_key', $dedupeKey)->exists();
                    try {
                        app(InAppNotificationDelivery::class)->deliver($follower->user_id, ['type' => 'new_episode', 'key' => $dedupeKey, 'title' => $event->episode->title, 'body' => 'A new episode is available.', 'data' => ['episode_id' => $event->episode->id, 'show_id' => $event->episode->show_id]]);
                        $followersNotified++;
                    } catch (\Throwable $error) {
                        Log::warning('push.episode_deliver_failed', [
                            'episode_id' => $event->episode->id,
                            'user_id' => $follower->user_id,
                            'error' => $error->getMessage(),
                        ]);
                    }
                    if ($fresh) {
                        app(MailPreference::class)->queueAlert((string) $follower->user_id, new NewEpisodeAlert($event->episode));
                    }
                }
            });
        Log::info('push.episode_fanout', [
            'episode_id' => $event->episode->id,
            'show_id' => $event->episode->show_id,
            'followers' => $followersNotified,
        ]);
    }
}
