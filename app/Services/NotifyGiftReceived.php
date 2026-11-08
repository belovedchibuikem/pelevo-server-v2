<?php

namespace App\Services;

use App\Mail\PelevoNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NotifyGiftReceived
{
    public function notify(
        string $giftId,
        string $creatorProfileId,
        string $senderUserId,
        string $giftTypeName,
        int $coins,
        ?string $message = null,
    ): void {
        $creatorUserId = DB::table('creator_profiles')->where('id', $creatorProfileId)->value('user_id');
        if (! is_string($creatorUserId) || $creatorUserId === '' || $creatorUserId === $senderUserId) {
            return;
        }

        $sender = DB::table('users')->where('id', $senderUserId)->first(['name', 'handle']);
        $senderName = trim((string) ($sender->name ?? ''));
        if ($senderName === '') {
            $handle = trim((string) ($sender->handle ?? ''));
            $senderName = $handle !== '' ? '@'.$handle : 'A Pelevo listener';
        }

        $giftLabel = trim($giftTypeName) !== '' ? trim($giftTypeName) : 'a gift';
        $coinLabel = number_format(max(0, $coins));
        $title = $senderName.' sent you a gift';
        $body = $senderName.' gifted you '.$giftLabel.' ('.$coinLabel.' coins).';
        $detail = $message !== null && trim($message) !== ''
            ? '"'.Str::limit(trim($message), 200).'"'
            : $body;

        try {
            app(InAppNotificationDelivery::class)->deliver($creatorUserId, [
                'type' => 'gift_received',
                'key' => 'gift:'.$giftId,
                'title' => $title,
                'body' => $body,
                'data' => [
                    'gift_id' => $giftId,
                    'creator_profile_id' => $creatorProfileId,
                    'sender_user_id' => $senderUserId,
                    'gift_type_name' => $giftLabel,
                    'coins' => (string) $coins,
                ],
            ]);
        } catch (\Throwable $error) {
            Log::warning('gift.notification_failed', [
                'error' => $error->getMessage(),
                'gift_id' => $giftId,
            ]);
        }

        app(MailPreference::class)->queueAlert($creatorUserId, new PelevoNotice(
            subjectLine: $title.' on Pelevo',
            eyebrow: 'Gift received',
            heading: 'You received a gift',
            intro: $body.' These coins were added to your creator diamond balance.',
            detail: $detail,
            actionLabel: 'Open Pelevo',
            actionUrl: config('app.url'),
            footerNote: 'You received this because Email Notifications is on in Pelevo. Turn it off in Settings to stop these emails.',
            preheader: $body,
        ));
    }
}
