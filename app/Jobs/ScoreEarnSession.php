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
        $deviceBurst = DB::table('earn_sessions')->where('device_id', $session->device_id)->where('created_at', '>=', now()->subHour())->count();
        $ipUsers = $session->ip_address ? DB::table('earn_sessions')->where('ip_address', $session->ip_address)->where('created_at', '>=', now()->subHour())->distinct()->count('user_id') : 0;
        $risk = $daily >= (int) config('finance.earn_daily_completion_cap') || $rapidIps >= 4 || $deviceBurst >= 8 || $ipUsers >= (int) config('finance.earn_ip_user_cap', 12);
        DB::table('earn_sessions')->where('id', $session->id)->update(['risk_state' => $risk ? 'review' : 'clear', 'state' => $risk ? 'review' : $session->state, 'updated_at' => now()]);
    }
}
