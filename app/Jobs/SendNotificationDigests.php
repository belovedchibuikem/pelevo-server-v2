<?php

namespace App\Jobs;

use App\Mail\DigestMail;
use App\Services\MailPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SendNotificationDigests implements ShouldQueue
{
    use Queueable;

    /** @var array<string, list<string>> */
    private const INCLUDE_TYPES = [
        'new_episodes' => ['new_episode'],
        'downloads' => ['download'],
        'mentions' => ['mention', 'follow', 'reply'],
        'promotions' => ['broadcast'],
    ];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(MailPreference $mail): void
    {
        DB::table('notification_preferences')->orderBy('user_id')->chunk(200, function ($rows) use ($mail): void {
            foreach ($rows as $row) {
                $this->sendIfDue($mail, $row);
            }
        });
    }

    private function sendIfDue(MailPreference $mail, object $row): void
    {
        if (! $mail->alertsEnabled((string) $row->user_id)) {
            return;
        }
        $options = $this->options($row->mobile_options ?? null);
        $days = array_map('intval', $options['summary_days'] ?? []);
        $time = is_string($options['summary_time'] ?? null) ? $options['summary_time'] : null;
        $include = is_array($options['summary_include'] ?? null) ? $options['summary_include'] : [];
        if ($days === [] || $time === null || $include === []) {
            return;
        }
        try {
            $local = now()->timezone((string) ($row->timezone ?: 'UTC'));
        } catch (\Throwable) {
            return;
        }
        if (! in_array($local->dayOfWeek, $days, true) || ! $this->inWindow($local, $time)) {
            return;
        }
        $types = [];
        foreach ($include as $category) {
            $types = array_merge($types, self::INCLUDE_TYPES[$category] ?? []);
        }
        $types = array_values(array_unique($types));
        if ($types === []) {
            return;
        }
        $items = DB::table('notifications')
            ->where('user_id', $row->user_id)
            ->whereIn('type', $types)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(12)
            ->get(['title', 'body'])
            ->map(fn (object $item): array => ['title' => (string) $item->title, 'body' => (string) $item->body])
            ->all();
        if ($items === []) {
            return;
        }
        $cacheKey = 'mail-digest:'.$row->user_id.':'.$local->toDateString();
        if (! Cache::add($cacheKey, 1, $local->copy()->endOfDay())) {
            return;
        }
        try {
            $mail->queueAlert((string) $row->user_id, new DigestMail($items, $local->toDayDateTimeString()));
        } catch (\Throwable $error) {
            Log::warning('mail.digest_failed', ['user_id' => $row->user_id, 'error' => $error->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function inWindow(Carbon $local, string $time): bool
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts)) {
            return false;
        }
        $target = ((int) $parts[1] * 60) + (int) $parts[2];
        $now = ($local->hour * 60) + $local->minute;
        $delta = ($now - $target + 1440) % 1440;

        return $delta < 15;
    }
}
