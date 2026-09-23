<?php

namespace App\Jobs;

use App\Mail\PelevoNotice;
use App\Models\User;
use App\Services\InAppNotificationDelivery;
use App\Services\MailPreference;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class DispatchNotificationBroadcast implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $broadcastId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->broadcastId;
    }

    public function handle(): void
    {
        if (! config('features.notifications')) {
            return;
        }
        $broadcast = DB::table('notification_broadcasts')->where('id', $this->broadcastId)->whereIn('state', ['scheduled', 'processing'])->first();
        if (! $broadcast || ($broadcast->scheduled_at && now()->lt($broadcast->scheduled_at))) {
            return;
        }
        $template = DB::table('notification_templates')->where('id', $broadcast->notification_template_id)->where('active', true)->first();
        if (! $template) {
            return;
        }
        $claimed = DB::table('notification_broadcasts')->where('id', $broadcast->id)->whereIn('state', ['scheduled', 'processing'])->update(['state' => 'processing', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $audience = json_decode($broadcast->audience, true) ?: [];
        User::query()->where('status', 'active')->when(isset($audience['country']), fn ($q) => $q->where('country_code', $audience['country']))->select('id')->chunkById(200, function ($users) use ($broadcast, $template): void {
            $mail = app(MailPreference::class);
            foreach ($users as $user) {
                app(InAppNotificationDelivery::class)->deliver($user->id, ['type' => 'broadcast', 'key' => 'broadcast:'.$broadcast->id, 'title' => $template->title, 'body' => $template->body, 'data' => ['broadcast_id' => $broadcast->id]]);
                $mail->queueAlert($user->id, new PelevoNotice(
                    subjectLine: $template->title,
                    eyebrow: 'Pelevo',
                    heading: $template->title,
                    intro: $template->body,
                    footerNote: 'You received this because Email Notifications is on in Pelevo. Turn it off in Settings to stop these emails.',
                ));
            }
        });
        DB::table('notification_broadcasts')->where('id', $broadcast->id)->update(['state' => 'completed', 'updated_at' => now()]);
    }
}
