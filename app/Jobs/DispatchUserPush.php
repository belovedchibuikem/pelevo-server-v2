<?php

namespace App\Jobs;

use App\Services\PushDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DispatchUserPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public array $backoff = [5, 15, 30, 60, 120];

    /** @param array{type: string, title: string, body: string, data?: array, key?: string} $message */
    public function __construct(
        public readonly string $userId,
        public readonly array $message,
    ) {
        $this->onQueue('notifications')->afterCommit();
    }

    public function handle(PushDispatch $push): void
    {
        $sent = $push->notifyUser($this->userId, $this->message);
        if ($sent > 0) {
            return;
        }

        $enabled = DB::table('notification_preferences')->where('user_id', $this->userId)->value('push_enabled');
        if ($enabled === false || $enabled === 0) {
            return;
        }

        $hasTokens = DB::table('push_tokens')
            ->where('user_id', $this->userId)
            ->whereNull('revoked_at')
            ->where('provider', 'fcm')
            ->exists();
        if (! $hasTokens) {
            return;
        }

        throw new RuntimeException('FCM accepted 0 tokens for a listener that still has a registered device.');
    }
}
