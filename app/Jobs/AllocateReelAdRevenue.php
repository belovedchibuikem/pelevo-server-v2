<?php

namespace App\Jobs;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AllocateReelAdRevenue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $adImpressionId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->adImpressionId;
    }

    public function handle(PostLedgerTransaction $post): void
    {
        if (! config('reels_ads.allocation_enabled')) {
            return;
        }

        DB::transaction(function () use ($post): void {
            $impression = DB::table('ad_impressions')
                ->where('id', $this->adImpressionId)
                ->lockForUpdate()
                ->first();
            if (! $impression || $impression->status !== 'watched' || $impression->fraud_state !== 'clear') {
                return;
            }

            $contributions = DB::table('ad_impression_attributions')
                ->where('ad_impression_id', $this->adImpressionId)
                ->groupBy('creator_profile_id')
                ->select('creator_profile_id', DB::raw('count(*) as contribution_count'))
                ->orderBy('creator_profile_id')
                ->get();
            $total = (int) $contributions->sum('contribution_count');
            if ($total < 1) {
                DB::table('ad_impressions')->where('id', $this->adImpressionId)->update([
                    'fraud_state' => 'review',
                    'updated_at' => now(),
                ]);

                return;
            }

            $pool = (int) $impression->payout_pool_usd_micros;
            $allocations = [];
            $allocated = 0;
            foreach ($contributions as $contribution) {
                $numerator = $pool * (int) $contribution->contribution_count;
                $amount = intdiv($numerator, $total);
                $allocations[] = [
                    'creator_profile_id' => (string) $contribution->creator_profile_id,
                    'amount' => $amount,
                    'remainder' => $numerator % $total,
                ];
                $allocated += $amount;
            }
            usort($allocations, function (array $a, array $b): int {
                $remainder = $b['remainder'] <=> $a['remainder'];

                return $remainder !== 0
                    ? $remainder
                    : $a['creator_profile_id'] <=> $b['creator_profile_id'];
            });
            for ($remaining = $pool - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
                $allocations[$index % count($allocations)]['amount']++;
            }

            foreach ($allocations as $allocation) {
                if ($allocation['amount'] < 1) {
                    continue;
                }
                if (DB::table('creator_ad_revenue_allocations')
                    ->where('ad_impression_id', $this->adImpressionId)
                    ->where('creator_profile_id', $allocation['creator_profile_id'])
                    ->exists()) {
                    continue;
                }

                $funding = FinancialAccount::firstOrCreate(
                    ['owner_type' => null, 'owner_id' => null, 'type' => 'reels_ad_revenue_funding', 'unit' => 'USD'],
                    ['balance' => 0],
                );
                $pending = FinancialAccount::firstOrCreate(
                    [
                        'owner_type' => 'App\\Models\\CreatorProfile',
                        'owner_id' => $allocation['creator_profile_id'],
                        'type' => 'reels_ad_pending',
                        'unit' => 'USD',
                    ],
                    ['balance' => 0],
                );
                $idempotencyKey = "reel-ad:{$this->adImpressionId}:creator:{$allocation['creator_profile_id']}:pending";
                $transaction = $post->handle(
                    'reel.ad.pending',
                    $idempotencyKey,
                    'USD',
                    [
                        ['account_id' => $funding->id, 'amount' => -$allocation['amount']],
                        ['account_id' => $pending->id, 'amount' => $allocation['amount']],
                    ],
                    ['ad_impression_id' => $this->adImpressionId],
                );

                DB::table('creator_ad_revenue_allocations')->insert([
                    'id' => (string) Str::ulid(),
                    'ad_impression_id' => $this->adImpressionId,
                    'creator_profile_id' => $allocation['creator_profile_id'],
                    'status' => 'pending',
                    'gross_usd_micros' => $allocation['amount'],
                    'confirmed_usd_micros' => 0,
                    'reserve_usd_micros' => 0,
                    'available_currency' => null,
                    'available_amount_minor' => 0,
                    'pending_ledger_transaction_id' => $transaction->id,
                    'idempotency_key' => $idempotencyKey,
                    'held_until' => now()->addDays((int) config('reels_ads.hold_days')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Log::info('reels_ad.revenue_allocated', [
                'ad_impression_id' => $this->adImpressionId,
                'creator_count' => count($allocations),
                'allocated_usd_micros' => array_sum(array_column($allocations, 'amount')),
            ]);
        }, 3);
    }
}
