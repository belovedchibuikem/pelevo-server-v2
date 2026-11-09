<?php

namespace App\Jobs;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When an allocation's held_until time is reached, confirm (if still pending)
 * and release funds into reels_ad_available so creators can withdraw.
 */
final class ReleaseMaturedReelAdAllocations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('finance');
    }

    public function handle(PostLedgerTransaction $post): void
    {
        $ids = DB::table('creator_ad_revenue_allocations')
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('held_until', '<=', now())
            ->orderBy('held_until')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $this->releaseOne((string) $id, $post);
            } catch (\Throwable $error) {
                Log::warning('reels_ad.maturity_release_failed', [
                    'allocation_id' => $id,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    private function releaseOne(string $allocationId, PostLedgerTransaction $post): void
    {
        DB::transaction(function () use ($allocationId, $post): void {
            $allocation = DB::table('creator_ad_revenue_allocations')
                ->where('id', $allocationId)
                ->lockForUpdate()
                ->first();
            if (! $allocation || ! in_array($allocation->status, ['pending', 'confirmed'], true)) {
                return;
            }
            if (CarbonImmutable::parse($allocation->held_until)->isFuture()) {
                return;
            }

            $impression = DB::table('ad_impressions')->where('id', $allocation->ad_impression_id)->first();
            if (! $impression || $impression->status !== 'watched' || $impression->fraud_state !== 'clear') {
                return;
            }
            if ($this->creatorBlocked((string) $allocation->creator_profile_id)) {
                return;
            }

            $confirmedMicros = (int) $allocation->confirmed_usd_micros;
            if ($allocation->status === 'pending') {
                $confirmedMicros = (int) $allocation->gross_usd_micros;
                $this->confirm($allocation, $confirmedMicros, $post);
                $allocation->status = 'confirmed';
                $allocation->confirmed_usd_micros = $confirmedMicros;
            }

            if ($confirmedMicros <= 0) {
                DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $reserveBps = (int) ($impression->reserve_bps ?? config('reels_ads.reserve_bps', 500));
            $reserve = intdiv($confirmedMicros * $reserveBps, 10_000);
            $this->release($allocation, $confirmedMicros, $reserve, $reserveBps, $post);
        }, 3);
    }

    private function confirm(object $allocation, int $confirmedMicros, PostLedgerTransaction $post): void
    {
        $gross = (int) $allocation->gross_usd_micros;
        $pending = $this->account((string) $allocation->creator_profile_id, 'reels_ad_pending', 'USD');
        $confirmed = $this->account((string) $allocation->creator_profile_id, 'reels_ad_confirmed', 'USD');
        $funding = $this->systemAccount('reels_ad_revenue_funding', 'USD');
        $entries = array_values(array_filter([
            ['account_id' => $pending->id, 'amount' => -$gross],
            ['account_id' => $confirmed->id, 'amount' => $confirmedMicros],
            ['account_id' => $funding->id, 'amount' => $gross - $confirmedMicros],
        ], fn (array $entry): bool => $entry['amount'] !== 0));

        $post->handle(
            'reel.ad.reconciled',
            "reel-ad-allocation:{$allocation->id}:confirm-matured",
            'USD',
            $entries,
            [
                'allocation_id' => $allocation->id,
                'source' => 'hold_maturity',
                'gross_usd_micros' => $gross,
                'confirmed_usd_micros' => $confirmedMicros,
            ],
        );

        DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
            'status' => 'confirmed',
            'confirmed_usd_micros' => $confirmedMicros,
            'updated_at' => now(),
        ]);
    }

    private function release(
        object $allocation,
        int $confirmedMicros,
        int $reserve,
        int $reserveBps,
        PostLedgerTransaction $post,
    ): void {
        $offset = $this->account((string) $allocation->creator_profile_id, 'reels_ad_clawback_offset', 'USD');
        $offsetBalance = (int) DB::table('financial_accounts')->where('id', $offset->id)->lockForUpdate()->value('balance');
        $clawback = min($confirmedMicros, max(0, -$offsetBalance));
        $reserve = min($reserve, $confirmedMicros - $clawback);
        $availableUsd = $confirmedMicros - $reserve - $clawback;
        $confirmed = $this->account((string) $allocation->creator_profile_id, 'reels_ad_confirmed', 'USD');
        $reserveAccount = $this->account((string) $allocation->creator_profile_id, 'reels_ad_reserve', 'USD');
        $entries = [
            ['account_id' => $confirmed->id, 'amount' => -$confirmedMicros],
            ['account_id' => $offset->id, 'amount' => $clawback],
            ['account_id' => $reserveAccount->id, 'amount' => $reserve],
        ];

        if ($availableUsd === 0) {
            $release = $post->handle(
                'reel.ad.released_to_clawback',
                "reel-ad-allocation:{$allocation->id}:release-matured",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                [
                    'allocation_id' => $allocation->id,
                    'source' => 'hold_maturity',
                    'reserve_bps' => $reserveBps,
                    'clawback_usd_micros' => $clawback,
                ],
            );
        } else {
            $available = $this->account((string) $allocation->creator_profile_id, 'reels_ad_available', 'USD');
            $entries[] = ['account_id' => $available->id, 'amount' => $availableUsd];
            $release = $post->handle(
                'reel.ad.released',
                "reel-ad-allocation:{$allocation->id}:release-matured",
                'USD',
                array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0)),
                [
                    'allocation_id' => $allocation->id,
                    'source' => 'hold_maturity',
                    'reserve_bps' => $reserveBps,
                    'clawback_usd_micros' => $clawback,
                ],
            );
        }

        DB::table('creator_ad_revenue_allocations')->where('id', $allocation->id)->update([
            'status' => 'released',
            'confirmed_usd_micros' => $confirmedMicros,
            'reserve_usd_micros' => $reserve,
            'clawback_usd_micros' => $clawback,
            'available_currency' => 'USD',
            'available_amount_minor' => $availableUsd,
            'release_ledger_transaction_id' => $release->id,
            'released_at' => now(),
            'updated_at' => now(),
        ]);
        Log::info('reels_ad.revenue_released_on_maturity', [
            'allocation_id' => $allocation->id,
            'confirmed_usd_micros' => $confirmedMicros,
            'available_amount_minor' => $availableUsd,
        ]);
    }

    private function account(string $creatorId, string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => 'App\\Models\\CreatorProfile', 'owner_id' => $creatorId, 'type' => $type, 'unit' => $unit],
            ['balance' => 0],
        );
    }

    private function systemAccount(string $type, string $unit): FinancialAccount
    {
        return FinancialAccount::firstOrCreate(
            ['owner_type' => null, 'owner_id' => null, 'type' => $type, 'unit' => $unit],
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
}
