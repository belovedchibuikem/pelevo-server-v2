<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MonitorReelAdMonetization implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct()
    {
        $this->onQueue('finance');
    }

    public function handle(): void
    {
        $stalePendingImpressions = DB::table('ad_impressions')
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->count();
        $watchedWithoutAllocation = DB::table('ad_impressions')
            ->where('status', 'watched')
            ->where('fraud_state', 'clear')
            ->where('watched_at', '<', now()->subMinutes(15))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('creator_ad_revenue_allocations')
                ->whereColumn('creator_ad_revenue_allocations.ad_impression_id', 'ad_impressions.id'))
            ->count();
        $heldWithoutSettlement = DB::table('creator_ad_revenue_allocations')
            ->where('status', 'pending')
            ->where('held_until', '<', now())
            ->whereNull('reconciliation_batch_id')
            ->count();
        $fraudReviewBacklog = DB::table('ad_impressions')
            ->where('fraud_state', 'review')
            ->count();
        $futureClawbackOffset = abs(min(0, (int) DB::table('financial_accounts')
            ->where('type', 'reels_ad_clawback_offset')
            ->where('unit', 'USD')
            ->sum('balance')));
        $fxClearing = DB::table('financial_accounts')
            ->where('type', 'reels_ad_fx_clearing')
            ->whereIn('unit', ['USD', 'NGN'])
            ->select('unit', DB::raw('sum(balance) as balance'))
            ->groupBy('unit')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->unit => (int) $row->balance])
            ->all();

        Log::info('reels_ad.health', [
            'stale_pending_impressions' => $stalePendingImpressions,
            'watched_without_allocation' => $watchedWithoutAllocation,
            'held_without_settlement' => $heldWithoutSettlement,
            'fraud_review_backlog' => $fraudReviewBacklog,
            'future_clawback_offset_usd_micros' => $futureClawbackOffset,
            'fx_clearing_by_currency' => $fxClearing,
        ]);

        if ($stalePendingImpressions > 0 || $watchedWithoutAllocation > 0) {
            Log::critical('reels_ad.money_path_anomaly', [
                'stale_pending_impressions' => $stalePendingImpressions,
                'watched_without_allocation' => $watchedWithoutAllocation,
            ]);
        }
        if ($heldWithoutSettlement > 0 || collect($fxClearing)->contains(fn (int $balance): bool => $balance !== 0)) {
            Log::warning('reels_ad.finance_attention_required', [
                'held_without_settlement' => $heldWithoutSettlement,
                'fx_clearing_by_currency' => $fxClearing,
            ]);
        }
    }
}
