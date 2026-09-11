<?php

namespace App\Listeners;

use App\Events\NewEpisodePublished;
use App\Services\InAppNotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class CreateNewEpisodeNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function handle(NewEpisodePublished $event): void
    {
        if (! config('features.notifications')) {
            return;
        }
        DB::table('follows')->leftJoin('notification_preferences', 'notification_preferences.user_id', '=', 'follows.user_id')->where('follows.show_id', $event->episode->show_id)->where('follows.notifications_enabled', true)->where(fn ($query) => $query->whereNull('notification_preferences.new_episodes')->orWhere('notification_preferences.new_episodes', true))->select('follows.user_id')->orderBy('follows.user_id')->chunk(500, function ($followers) use ($event): void {
            foreach ($followers as $follower) {
                app(InAppNotificationDelivery::class)->deliver($follower->user_id, ['type' => 'new_episode', 'key' => 'new-episode:'.$event->episode->id, 'title' => $event->episode->title, 'body' => 'A new episode is available.', 'data' => ['episode_id' => $event->episode->id, 'show_id' => $event->episode->show_id]]);
            }
        });
    }
}
