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
use Illuminate\Support\Str;
use RuntimeException;

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

        $preference = DB::table('creator_payout_settings')
            ->where('creator_profile_id', $allocation->creator_profile_id)
            ->value('ad_payout_currency');
        if (! in_array($preference, config('reels_ads.supported_payout_currencies'), true)) {
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
        } elseif ($preference === 'USD') {
            $available = $this->account($allocation->creator_profile_id, 'reels_ad_available', 'USD');
            $entries[] = ['account_id' => $available->id, 'amount' => $availableUsd];
            $release = $post->handle(
                'reel.ad.released',
                "reel-ad-allocation:{$allocation->id}:release",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                ['allocation_id' => $allocation->id, 'reconciliation_batch_id' => $batch->id, 'reserve_bps' => $reserveBps, 'clawback_usd_micros' => $clawback],
            );
        } else {
            $rate = DB::table('fx_rate_versions')
                ->where('base_unit', 'USD')
                ->where('quote_currency', 'NGN')
                ->where('approval_state', 'approved')
                ->where('effective_at', '<=', now())
                ->where('effective_at', '>=', now()->subMinutes((int) config('reels_ads.fx.quote_max_age_minutes')))
                ->latest('effective_at')
                ->first();
            if (! $rate) {
                return;
            }
            $spreadBps = max(0, min(10_000, (int) config('reels_ads.fx.spread_bps')));
            $grossRateScaled = $this->decimalToScaled((string) $rate->rate, 6);
            $netRateScaled = intdiv($grossRateScaled * (10_000 - $spreadBps), 10_000);
            $availableMinor = $this->usdMicrosToNgnKobo($availableUsd, $netRateScaled);
            $usdClearing = $this->systemAccount('reels_ad_fx_clearing', 'USD');
            $entries[] = ['account_id' => $usdClearing->id, 'amount' => $availableUsd];
            $release = $post->handle(
                'reel.ad.released_for_fx',
                "reel-ad-allocation:{$allocation->id}:release",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                ['allocation_id' => $allocation->id, 'reconciliation_batch_id' => $batch->id, 'reserve_bps' => $reserveBps, 'clawback_usd_micros' => $clawback],
            );

            $ngnClearing = $this->systemAccount('reels_ad_fx_clearing', 'NGN');
            $ngnAvailable = $this->account($allocation->creator_profile_id, 'reels_ad_available', 'NGN');
            $destination = $post->handle(
                'reel.ad.fx_settled',
                "reel-ad-allocation:{$allocation->id}:fx-ngn",
                'NGN',
                [
                    ['account_id' => $ngnClearing->id, 'amount' => -$availableMinor],
                    ['account_id' => $ngnAvailable->id, 'amount' => $availableMinor],
                ],
                ['allocation_id' => $allocation->id, 'source_usd_micros' => $availableUsd],
            );
            $conversionId = (string) Str::ulid();
            DB::table('currency_conversions')->insert([
                'id' => $conversionId,
                'creator_profile_id' => $allocation->creator_profile_id,
                'source_currency' => 'USD',
                'source_amount_minor' => $availableUsd,
                'destination_currency' => 'NGN',
                'destination_amount_minor' => $availableMinor,
                'gross_rate' => $this->scaledToDecimal($grossRateScaled, 6),
                'net_rate' => $this->scaledToDecimal($netRateScaled, 6),
                'spread_bps' => $spreadBps,
                'rate_source' => $rate->source,
                'provider_reference' => $rate->id,
                'quoted_at' => $rate->effective_at,
                'quote_expires_at' => CarbonImmutable::parse($rate->effective_at)->addMinutes((int) config('reels_ads.fx.quote_max_age_minutes')),
                'reason' => 'ad_revenue_release',
                'idempotency_key' => "reel-ad-allocation:{$allocation->id}:fx",
                'source_ledger_transaction_id' => $release->id,
                'destination_ledger_transaction_id' => $destination->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
            'status' => 'released',
            'confirmed_usd_micros' => $confirmedMicros,
            'reserve_usd_micros' => $reserve,
            'clawback_usd_micros' => $clawback,
            'available_currency' => $preference,
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
            'available_currency' => $preference,
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

    private function decimalToScaled(string $value, int $scale): int
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new RuntimeException('Invalid FX rate.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return ((int) $whole * (10 ** $scale)) + (int) $fraction;
    }

    private function scaledToDecimal(int $value, int $scale): string
    {
        $base = 10 ** $scale;

        return intdiv($value, $base).'.'.str_pad((string) ($value % $base), $scale, '0', STR_PAD_LEFT);
    }

    private function usdMicrosToNgnKobo(int $usdMicros, int $rateScaled): int
    {
        // USD micros / 1e6 * NGN/USD (scaled 1e6) * 100 kobo.
        return intdiv(($usdMicros * $rateScaled) + 5_000_000_000, 10_000_000_000);
    }
}
