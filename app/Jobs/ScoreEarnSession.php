<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ScoreEarnSession implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $sessionId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->sessionId;
    }

    public function handle(): void
    {
        $session = DB::table('earn_sessions')->where('id', $this->sessionId)->first();
        if (! $session || ! in_array($session->state, ['active', 'review'], true)) {
            return;
        }
        $daily = DB::table('earn_awards')->where('user_id', $session->user_id)->where('created_at', '>=', now()->startOfDay())->count();
        $rapidIps = DB::table('earn_sessions')->where('user_id', $session->user_id)->where('created_at', '>=', now()->subHour())->distinct()->count('ip_address');
        $risk = $daily >= config('finance.earn_daily_completion_cap') || $rapidIps >= 4;
        DB::table('earn_sessions')->where('id', $session->id)->update(['risk_state' => $risk ? 'review' : 'clear', 'state' => $risk ? 'review' : $session->state, 'updated_at' => now()]);
    }
}
