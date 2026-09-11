<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostLedgerTransaction
{
    public function handle(string $eventType, string $idempotencyKey, string $unit, array $entries, array $metadata = []): LedgerTransaction
    {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }
        if (count($entries) < 2 || array_sum(array_column($entries, 'amount')) !== 0) {
            throw new InvalidArgumentException('Ledger entries must balance to zero.');
        }

        $normalized = collect($entries)->map(fn (array $entry): array => ['account_id' => (string) $entry['account_id'], 'amount' => (int) $entry['amount']])->sortBy('account_id')->values()->all();
        ksort($metadata);
        $requestHash = hash('sha256', json_encode([$eventType, $unit, $normalized, $metadata], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($eventType, $idempotencyKey, $unit, $entries, $metadata, $requestHash): LedgerTransaction {
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (data_get($existing->metadata, 'request_hash') && ! hash_equals($existing->metadata['request_hash'], $requestHash)) {
                    throw new InvalidArgumentException('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $accounts = FinancialAccount::whereIn('id', array_column($entries, 'account_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (data_get($existing->metadata, 'request_hash') && ! hash_equals($existing->metadata['request_hash'], $requestHash)) {
                    throw new InvalidArgumentException('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            foreach ($entries as $entry) {
                $account = $accounts->get($entry['account_id']) ?? throw new InvalidArgumentException('Account not found.');
                if ($account->unit !== $unit) {
                    throw new InvalidArgumentException('Ledger units must match.');
                } if (($account->balance + $entry['amount']) < 0 && in_array($account->type, ['gift_wallet', 'earn_wallet', 'creator_balance'], true)) {
                    throw new InvalidArgumentException('INSUFFICIENT_COINS');
                }
            }
            $transaction = LedgerTransaction::create(['reference' => (string) Str::ulid(), 'event_type' => $eventType, 'idempotency_key' => $idempotencyKey, 'metadata' => [...$metadata, 'request_hash' => $requestHash]]);
            foreach ($entries as $entry) {
                $account = $accounts->get($entry['account_id']);
                DB::table('ledger_entries')->insert(['id' => (string) Str::ulid(), 'ledger_transaction_id' => $transaction->id, 'financial_account_id' => $account->id, 'amount' => $entry['amount'], 'unit' => $unit, 'created_at' => now(), 'updated_at' => now()]);
                $account->increment('balance', $entry['amount']);
            }

            return $transaction;
        }, 3);
    }
}
