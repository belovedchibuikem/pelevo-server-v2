<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ExpirePendingReelAdImpressions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('finance');
    }

    public function handle(): void
    {
        DB::table('ad_impressions')
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->orderBy('triggered_at')
            ->chunkById(500, function ($impressions): void {
                DB::table('ad_impressions')
                    ->whereIn('id', $impressions->pluck('id'))
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'bounced',
                        'bounced_at' => now(),
                        'updated_at' => now(),
                    ]);
            }, 'id');
    }
}
