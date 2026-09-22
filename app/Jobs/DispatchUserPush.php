<?php

namespace App\Jobs;

use App\Services\PushDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $this->onQueue('notifications');
    }

    public function handle(PushDispatch $push): void
    {
        $push->notifyUser($this->userId, $this->message);
    }
}
