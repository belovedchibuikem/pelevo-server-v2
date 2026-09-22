<?php

namespace App\Jobs;

use App\Services\InAppNotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverInAppNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $userId, public readonly array $message, public readonly string $expiresAt)
    {
        $this->onQueue('notifications')->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(InAppNotificationDelivery $delivery): void
    {
        $delivery->deliver($this->userId, $this->message, $this->expiresAt);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->message['type'] === 'broadcast') {
            DB::table('notification_deliveries')->where('notification_broadcast_id', $this->message['data']['broadcast_id'])
                ->where('user_id', $this->userId)->where('channel', 'in_app')->where('state', 'deferred')
                ->update(['state' => 'failed', 'failure' => 'In-app delivery exhausted its retries. Inspect the failed job before retrying.', 'updated_at' => now()]);
        }
    }
}
