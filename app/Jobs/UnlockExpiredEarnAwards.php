<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class UnlockExpiredEarnAwards implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('finance');
    }

    public function handle(): void
    {
        $ids = DB::table('earn_awards')
            ->whereNull('unlocked_at')
            ->where('locked_until', '<=', now())
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('earn_awards')->whereIn('id', $ids)->update([
            'unlocked_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
