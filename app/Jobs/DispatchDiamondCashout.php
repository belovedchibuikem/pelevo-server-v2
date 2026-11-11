<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchDiamondCashout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $cashoutId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->cashoutId;
    }

    public function handle(): void
    {
        // Payment is an explicit finance action. A previously queued copy of
        // this job must not post the old generic payload to Flutterwave.
    }
}
