<?php

namespace App\Actions\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Closes a diamond cashout from a Flutterwave transfer result.
 * Funds are returned only after Flutterwave reports failure or reversal.
 */
final class SettleDiamondCashout
{
    public function __construct(private readonly ReverseLedgerTransaction $reverse) {}

    public function apply(string $reference, string $state, ?string $reason = null): bool
    {
        $reference = trim($reference);
        if ($reference === '' || ! in_array($state, ['paid', 'failed', 'reversed'], true)) {
            return false;
        }

        return DB::transaction(function () use ($reference, $state, $reason): bool {
            $cashout = DB::table('diamond_cashouts')->where('provider_reference', $reference)->lockForUpdate()->first();
            if (! $cashout || $cashout->state === $state) {
                return false;
            }
            if (in_array($cashout->state, ['rejected', 'failed', 'reversed'], true)) {
                return false;
            }
            if (in_array($state, ['failed', 'reversed'], true)) {
                $this->reverse->handle($cashout->ledger_transaction_id, 'diamond-cashout-release:'.$cashout->id, $reason ?: 'Flutterwave reported '.$state.'.');
            }
            DB::table('diamond_cashouts')->where('id', $cashout->id)->update([
                'state' => $state,
                'failure_reason' => $state === 'paid' ? null : ($reason ?: 'Flutterwave reported '.$state.'.'),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }
}
