<?php

namespace App\Jobs;

use App\Actions\Finance\PostLedgerTransaction;
use App\Models\FinancialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccrueReelRevenue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('finance');
    }

    public function handle(PostLedgerTransaction $post): void
    {
        $fee = DB::table('fee_versions')->where('type', 'reel_platform')->where('active', true)->where('effective_at', '<=', now())->latest('effective_at')->first();
        if (! $fee) {
            return;
        }
        DB::table('reel_view_credits')->join('reels', 'reels.id', '=', 'reel_view_credits.reel_id')->join('reel_monetization_profiles', 'reel_monetization_profiles.creator_profile_id', '=', 'reels.creator_profile_id')->where('reel_monetization_profiles.eligible', true)->where('reel_monetization_profiles.opted_in', true)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('creator_revenue_events')->whereColumn('creator_revenue_events.source_id', 'reel_view_credits.reel_view_id')->where('creator_revenue_events.source_type', 'reel_view'))->select('reel_view_credits.reel_view_id', 'reel_view_credits.created_at', 'reels.creator_profile_id')->orderBy('reel_view_credits.reel_view_id')->chunk(200, function ($credits) use ($post, $fee): void {
            foreach ($credits as $credit) {
                DB::transaction(function () use ($post, $fee, $credit): void {
                    if (DB::table('creator_revenue_events')->where('source_type', 'reel_view')->where('source_id', $credit->reel_view_id)->lockForUpdate()->exists()) {
                        return;
                    }
                    $gross = (int) config('finance.reel_qualified_view_pcn', 1);
                    $feeAmount = intdiv($gross * (int) $fee->basis_points, 10_000);
                    $creator = FinancialAccount::firstOrCreate(['owner_type' => 'App\\Models\\CreatorProfile', 'owner_id' => $credit->creator_profile_id, 'type' => 'creator_balance', 'unit' => 'PCN'], ['balance' => 0]);
                    $funding = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'reel_revenue_funding', 'unit' => 'PCN'], ['balance' => 0]);
                    $platform = FinancialAccount::firstOrCreate(['owner_type' => null, 'owner_id' => null, 'type' => 'reel_platform_fee', 'unit' => 'PCN'], ['balance' => 0]);
                    $tx = $post->handle('reel.revenue', 'reel-view:'.$credit->reel_view_id, 'PCN', [['account_id' => $funding->id, 'amount' => -$gross], ['account_id' => $creator->id, 'amount' => $gross - $feeAmount], ['account_id' => $platform->id, 'amount' => $feeAmount]], ['fee_version_id' => $fee->id]);
                    DB::table('creator_revenue_events')->insert(['id' => (string) Str::ulid(), 'creator_profile_id' => $credit->creator_profile_id, 'source_type' => 'reel_view', 'source_id' => $credit->reel_view_id, 'ledger_transaction_id' => $tx->id, 'fee_version_id' => $fee->id, 'unit' => 'PCN', 'gross_amount' => $gross, 'fee_amount' => $feeAmount, 'net_amount' => $gross - $feeAmount, 'occurred_at' => $credit->created_at, 'created_at' => now(), 'updated_at' => now()]);
                }, 3);
            }
        });
    }
}
