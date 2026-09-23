<?php

namespace App\Services;

use App\Jobs\DeliverInAppNotification;
use App\Jobs\DispatchUserPush;
use App\Services\PushDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class InAppNotificationDelivery
{
    /** @param array{type: string, key: string, title: string, body: string, data: array} $message */
    public function deliver(string $userId, array $message, ?string $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays(7)->toIso8601String();

        $shouldPush = false;
        $state = DB::transaction(function () use ($userId, $message, $expiresAt, &$shouldPush): string {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                return 'suppressed';
            }
            $preferences = DB::table('notification_preferences')->where('user_id', $userId)->first();
            if (DB::table('notifications')->where('user_id', $userId)->where('deduplication_key', $message['key'])->exists()) {
                $shouldPush = $message['type'] === 'new_episode' && ($preferences?->push_enabled ?? true);

                return 'delivered';
            }
            $options = $this->mobileOptions($preferences?->mobile_options);
            $allowed = config('features.notifications') && $user->status === 'active'
                && ($options['in_app_enabled'] ?? true)
                && (! ($options['high_priority_only'] ?? false) || in_array($message['type'], ['support_reply', 'live'], true));
            if ($message['type'] === 'new_episode') {
                $allowed = $allowed && ($preferences?->new_episodes ?? true)
                    && in_array('new_episodes', $options['types'] ?? ['new_episodes'], true)
                    && DB::table('follows')->where('user_id', $userId)->where('show_id', $message['data']['show_id'])->where('notifications_enabled', true)->exists()
                    && DB::table('episodes')->where('id', $message['data']['episode_id'])->where('show_id', $message['data']['show_id'])->where('availability', 'available')->exists();
            }
            if ($message['type'] === 'live') {
                $allowed = $allowed
                    && isset($message['data']['show_id'])
                    && DB::table('follows')->where('user_id', $userId)->where('show_id', $message['data']['show_id'])->where('notifications_enabled', true)->exists();
            }
            if ($message['type'] === 'broadcast') {
                $allowed = $allowed && DB::table('notification_broadcasts as broadcasts')
                    ->join('notification_templates as templates', 'templates.id', '=', 'broadcasts.notification_template_id')
                    ->where('broadcasts.id', $message['data']['broadcast_id'])->whereIn('broadcasts.state', ['processing', 'completed'])
                    ->where('templates.active', true)->exists();
            }
            if (in_array($message['type'], ['reply', 'comment'], true)) {
                $types = $options['types'] ?? ['mentions'];
                $allowed = $allowed && in_array('mentions', $types, true);
            }
            if (! $allowed || now()->gte(Carbon::parse($expiresAt))) {
                $state = $allowed ? 'expired' : 'suppressed';
                $this->receipt($userId, $message, $state);

                return $state;
            }
            $resumeAt = $this->quietHoursEnd($preferences);
            if ($resumeAt !== null) {
                DeliverInAppNotification::dispatch($userId, $message, $expiresAt)->delay($resumeAt);
                $this->receipt($userId, $message, 'deferred');

                return 'deferred';
            }
            DB::table('notifications')->insert([
                'id' => (string) Str::ulid(), 'user_id' => $userId, 'type' => $message['type'],
                'deduplication_key' => $message['key'], 'title' => Str::limit($message['title'], 150),
                'body' => $message['body'], 'data' => json_encode($message['data'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->receipt($userId, $message, 'delivered');
            $shouldPush = $preferences?->push_enabled ?? true;

            return 'delivered';
        });
        if ($shouldPush) {
            $this->dispatchPush($userId, $message);
        }

        return $state;
    }

    private function quietHoursEnd(?object $preferences): ?Carbon
    {
        if (! $preferences?->quiet_hours_start || ! $preferences->quiet_hours_end) {
            return null;
        }
        $local = now()->setTimezone($preferences->timezone);
        $start = substr($preferences->quiet_hours_start, 0, 5);
        $end = substr($preferences->quiet_hours_end, 0, 5);
        $time = $local->format('H:i');
        $inside = $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
        if (! $inside || $start === $end) {
            return null;
        }
        $resume = $local->copy();
        if ($start > $end && $time >= $start) {
            $resume->addDay();
        }

        return $resume->setTimeFromTimeString($end.':00')->utc();
    }

    /** @param array{type: string, title: string, body: string, data?: array, key?: string} $message */
    private function dispatchPush(string $userId, array $message): void
    {
        try {
            app(PushDispatch::class)->notifyUser($userId, $message);
        } catch (\Throwable $error) {
            Log::warning('push.sync_failed', [
                'user_id' => $userId,
                'error' => $error->getMessage(),
            ]);
        }
        try {
            DispatchUserPush::dispatch($userId, $message);
        } catch (\Throwable $error) {
            Log::warning('push.queue_failed', [
                'user_id' => $userId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function mobileOptions(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function receipt(string $userId, array $message, string $state): void
    {
        if ($message['type'] !== 'broadcast') {
            return;
        }
        $key = ['notification_broadcast_id' => $message['data']['broadcast_id'], 'user_id' => $userId, 'channel' => 'in_app'];
        if (! DB::table('notification_broadcasts')->where('id', $key['notification_broadcast_id'])->exists()) {
            return;
        }
        DB::table('notification_deliveries')->insertOrIgnore([
            ...$key, 'id' => (string) Str::ulid(), 'state' => 'queued', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_deliveries')->where($key)->update([
            'state' => $state, 'attempts' => DB::raw('LEAST(attempts + 1, 255)'),
            'delivered_at' => $state === 'delivered' ? now() : null, 'failure' => null, 'updated_at' => now(),
        ]);
    }
}
