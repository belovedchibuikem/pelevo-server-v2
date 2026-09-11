<?php

namespace App\Actions\Finance;

use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReverseLedgerTransaction
{
    public function __construct(private readonly PostLedgerTransaction $post) {}

    public function handle(string $transactionId, string $idempotencyKey, string $reason): LedgerTransaction
    {
        return DB::transaction(function () use ($transactionId, $idempotencyKey, $reason): LedgerTransaction {
            $original = LedgerTransaction::whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $existing = LedgerTransaction::where('reverses_id', $original->id)->first();
            if ($existing) {
                return $existing;
            }
            $entries = DB::table('ledger_entries')->where('ledger_transaction_id', $original->id)->orderBy('financial_account_id')->get();
            if ($entries->isEmpty()) {
                throw new InvalidArgumentException('Transaction has no entries.');
            }
            $reversal = $this->post->handle('ledger.reversed', $idempotencyKey, $entries->first()->unit, $entries->map(fn ($entry): array => ['account_id' => $entry->financial_account_id, 'amount' => -$entry->amount])->all(), ['reason' => $reason, 'original_transaction_id' => $original->id]);
            $reversal->update(['reverses_id' => $original->id]);

            return $reversal;
        }, 3);
    }
}
