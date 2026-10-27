<?php

namespace App\Jobs;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ReleaseReconciledReelAdRevenue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(PostLedgerTransaction $post): void
    {
        DB::transaction(function () use ($post): void {
            $batch = DB::table('admob_reconciliation_batches')
                ->where('id', $this->batchId)
                ->lockForUpdate()
                ->first();
            if (! $batch || ! in_array($batch->status, ['approved', 'posted'], true)) {
                return;
            }

            $timezone = $batch->timezone ?: config('reels_ads.settlement_timezone');
            $periodStart = CarbonImmutable::parse($batch->statement_month, $timezone)->startOfMonth()->utc();
            $periodEnd = $periodStart->addMonth();
            $periodAllocations = DB::table('creator_ad_revenue_allocations')
                ->join('ad_impressions', 'ad_impressions.id', '=', 'creator_ad_revenue_allocations.ad_impression_id')
                ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_ad_revenue_allocations.creator_profile_id')
                ->whereIn('creator_ad_revenue_allocations.status', ['pending', 'confirmed', 'released'])
                ->where('ad_impressions.status', 'watched')
                ->whereIn('ad_impressions.fraud_state', ['clear', 'review'])
                ->where('ad_impressions.watched_at', '>=', $periodStart)
                ->where('ad_impressions.watched_at', '<', $periodEnd)
                ->select('creator_ad_revenue_allocations.*', 'ad_impressions.reserve_bps as impression_reserve_bps', 'ad_impressions.fraud_state as impression_fraud_state')
                ->orderBy('creator_ad_revenue_allocations.id')
                ->lockForUpdate()
                ->get();
            if ($periodAllocations->isEmpty()) {
                DB::table('admob_reconciliation_batches')->where('id', $this->batchId)->update([
                    'status' => 'posted',
                    'posted_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $grossTotal = (int) $periodAllocations->sum('gross_usd_micros');
            $releasableTotal = min($grossTotal, (int) $batch->finalized_usd_micros);
            $confirmed = $this->proportionalAmounts($periodAllocations->all(), $releasableTotal, $grossTotal);
            $reserves = $this->reserveAmounts($periodAllocations->all(), $confirmed);
            foreach ($periodAllocations as $allocation) {
                $confirmedMicros = $confirmed[$allocation->id] ?? 0;
                if ($allocation->impression_fraud_state !== 'clear' || $this->creatorBlocked($allocation->creator_profile_id)) {
                    continue;
                }
                if ($allocation->status === 'pending') {
                    $this->confirmAllocation($allocation, $batch, $confirmedMicros, $post);
                    $allocation->status = 'confirmed';
                    $allocation->confirmed_usd_micros = $confirmedMicros;
                    $allocation->reconciliation_batch_id = $batch->id;
                }
                if (
                    $allocation->status === 'confirmed'
                    && CarbonImmutable::parse($allocation->held_until)->isPast()
                ) {
                    $this->releaseAllocation($allocation, $batch, $reserves[$allocation->id] ?? 0, $post);
                }
            }

            DB::table('ad_impressions')
                ->whereIn('id', $periodAllocations->pluck('ad_impression_id')->unique())
                ->update(['reconciliation_batch_id' => $this->batchId, 'updated_at' => now()]);
            DB::table('admob_reconciliation_batches')->where('id', $this->batchId)->update([
                'status' => 'posted',
                'posted_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function confirmAllocation(
        object $allocation,
        object $batch,
        int $confirmedMicros,
        PostLedgerTransaction $post,
    ): void {
        $gross = (int) $allocation->gross_usd_micros;
        $pending = $this->account($allocation->creator_profile_id, 'reels_ad_pending', 'USD');
        $confirmed = $this->account($allocation->creator_profile_id, 'reels_ad_confirmed', 'USD');
        $funding = $this->systemAccount('reels_ad_revenue_funding', 'USD');
        $entries = array_values(array_filter([
            ['account_id' => $pending->id, 'amount' => -$gross],
            ['account_id' => $confirmed->id, 'amount' => $confirmedMicros],
            ['account_id' => $funding->id, 'amount' => $gross - $confirmedMicros],
        ], fn (array $entry): bool => $entry['amount'] !== 0));
        $post->handle(
            'reel.ad.reconciled',
            "reel-ad-allocation:{$allocation->id}:confirm",
            'USD',
            $entries,
            [
                'allocation_id' => $allocation->id,
                'reconciliation_batch_id' => $batch->id,
                'gross_usd_micros' => $gross,
                'confirmed_usd_micros' => $confirmedMicros,
            ],
        );

        DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
            'status' => 'confirmed',
            'confirmed_usd_micros' => $confirmedMicros,
            'reconciliation_batch_id' => $batch->id,
            'updated_at' => now(),
        ]);
        Log::info('reels_ad.revenue_confirmed', [
            'allocation_id' => $allocation->id,
            'reconciliation_batch_id' => $batch->id,
            'gross_usd_micros' => $gross,
            'confirmed_usd_micros' => $confirmedMicros,
        ]);
    }

    private function releaseAllocation(
        object $allocation,
        object $batch,
        int $reserve,
        PostLedgerTransaction $post,
    ): void {
        $confirmedMicros = (int) $allocation->confirmed_usd_micros;
        if ($confirmedMicros === 0) {
            DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
                'status' => 'released',
                'released_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $reserveBps = (int) $allocation->impression_reserve_bps;
        $offset = $this->account($allocation->creator_profile_id, 'reels_ad_clawback_offset', 'USD');
        $offsetBalance = (int) DB::table('financial_accounts')->where('id', $offset->id)->lockForUpdate()->value('balance');
        $clawback = min($confirmedMicros, max(0, -$offsetBalance));
        $reserve = min($reserve, $confirmedMicros - $clawback);
        $availableUsd = $confirmedMicros - $reserve;
        $confirmed = $this->account($allocation->creator_profile_id, 'reels_ad_confirmed', 'USD');
        $reserveAccount = $this->account($allocation->creator_profile_id, 'reels_ad_reserve', 'USD');
        $entries = [
            ['account_id' => $confirmed->id, 'amount' => -$confirmedMicros],
            ['account_id' => $offset->id, 'amount' => $clawback],
            ['account_id' => $reserveAccount->id, 'amount' => $reserve],
        ];
        $availableUsd -= $clawback;

        $conversionId = null;
        $availableMinor = $availableUsd;
        if ($availableUsd === 0) {
            $release = $post->handle(
                'reel.ad.released_to_clawback',
                "reel-ad-allocation:{$allocation->id}:release",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                ['allocation_id' => $allocation->id, 'reconciliation_batch_id' => $batch->id, 'reserve_bps' => $reserveBps, 'clawback_usd_micros' => $clawback],
            );
        } else {
            $available = $this->account($allocation->creator_profile_id, 'reels_ad_available', 'USD');
            $entries[] = ['account_id' => $available->id, 'amount' => $availableUsd];
            $release = $post->handle(
                'reel.ad.released',
                "reel-ad-allocation:{$allocation->id}:release",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                ['allocation_id' => $allocation->id, 'reconciliation_batch_id' => $batch->id, 'reserve_bps' => $reserveBps, 'clawback_usd_micros' => $clawback],
            );
        }

        DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
            'status' => 'released',
            'confirmed_usd_micros' => $confirmedMicros,
            'reserve_usd_micros' => $reserve,
            'clawback_usd_micros' => $clawback,
            'available_currency' => 'USD',
            'available_amount_minor' => $availableMinor,
            'release_ledger_transaction_id' => $release->id,
            'reconciliation_batch_id' => $batch->id,
            'currency_conversion_id' => $conversionId,
            'released_at' => now(),
            'updated_at' => now(),
        ]);
        Log::info('reels_ad.revenue_released', [
            'allocation_id' => $allocation->id,
            'reconciliation_batch_id' => $batch->id,
            'confirmed_usd_micros' => $confirmedMicros,
            'reserve_usd_micros' => $reserve,
            'clawback_usd_micros' => $clawback,
            'available_currency' => 'USD',
            'available_amount_minor' => $availableMinor,
        ]);
    }

    private function proportionalAmounts(array $allocations, int $target, int $grossTotal): array
    {
        $amounts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($allocations as $allocation) {
            $numerator = $target * (int) $allocation->gross_usd_micros;
            $amounts[$allocation->id] = intdiv($numerator, max(1, $grossTotal));
            $remainders[$allocation->id] = $numerator % max(1, $grossTotal);
            $allocated += $amounts[$allocation->id];
        }
        arsort($remainders, SORT_NUMERIC);
        $ids = array_keys($remainders);
        for ($remaining = $target - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $amounts[$ids[$index % count($ids)]]++;
        }

        return $amounts;
    }

    private function reserveAmounts(array $allocations, array $confirmed): array
    {
        $amounts = [];
        $remainders = [];
        $rawTotal = 0;
        $allocated = 0;
        foreach ($allocations as $allocation) {
            $numerator = ($confirmed[$allocation->id] ?? 0) * (int) $allocation->impression_reserve_bps;
            $amounts[$allocation->id] = intdiv($numerator, 10_000);
            $remainders[$allocation->id] = $numerator % 10_000;
            $rawTotal += $numerator;
            $allocated += $amounts[$allocation->id];
        }
        arsort($remainders, SORT_NUMERIC);
        $ids = array_keys($remainders);
        $target = intdiv($rawTotal, 10_000);
        for ($remaining = $target - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $amounts[$ids[$index % count($ids)]]++;
        }

        return $amounts;
    }

    private function account(string $creatorId, string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => 'App\\Models\\CreatorProfile', 'owner_id' => $creatorId, 'type' => $type, 'unit' => $unit],
            ['balance' => 0],
        );
    }

    private function creatorBlocked(string $creatorId): bool
    {
        return DB::table('creator_profiles')
            ->where('creator_profiles.id', $creatorId)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_sanctions')
                ->whereColumn('user_sanctions.user_id', 'creator_profiles.user_id')
                ->where('user_sanctions.state', 'active')
                ->where(fn ($sanctions) => $sanctions
                    ->whereNull('user_sanctions.expires_at')
                    ->orWhere('user_sanctions.expires_at', '>', now())))
            ->exists();
    }

    private function systemAccount(string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => null, 'owner_id' => null, 'type' => $type, 'unit' => $unit],
            ['balance' => 0],
        );
    }
}
