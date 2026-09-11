<?php

namespace App\Jobs;

use App\Actions\Finance\ReverseLedgerTransaction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DispatchWithdrawal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $withdrawalId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->withdrawalId;
    }

    public function handle(ReverseLedgerTransaction $reverse): void
    {
        $withdrawal = DB::table('withdrawals')->where('id', $this->withdrawalId)->where('state', 'approved')->first();
        if (! $withdrawal) {
            return;
        }
        $method = DB::table('payout_methods')->where('id', $withdrawal->payout_method_id)->first();
        $endpoint = config("services.{$method->provider}.payout_url");
        if (! $endpoint) {
            return;
        }
        $payload = ['amount' => $withdrawal->coins, 'unit' => 'ECN', 'destination' => decrypt($method->destination_encrypted)];
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $attempt = DB::table('payout_attempts')->where('withdrawal_id', $withdrawal->id)->count() + 1;
        $response = Http::withToken((string) config("services.{$method->provider}.payout_token"))->withHeader('Idempotency-Key', $withdrawal->id)->connectTimeout(3)->timeout(15)->post($endpoint, $payload);
        DB::table('payout_attempts')->insert(['id' => (string) Str::ulid(), 'withdrawal_id' => $withdrawal->id, 'attempt' => $attempt, 'state' => $response->successful() ? 'accepted' : 'failed', 'provider_reference' => $response->json('reference'), 'request_hash' => $requestHash, 'response_payload' => json_encode($response->json(), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        if (! $response->successful()) {
            DB::transaction(function () use ($withdrawal, $reverse): void {
                DB::table('withdrawals')->where('id', $withdrawal->id)->lockForUpdate()->update(['state' => 'failed', 'failure_reason' => 'Provider rejected dispatch.', 'processed_at' => now(), 'updated_at' => now()]);
                $reverse->handle($withdrawal->ledger_transaction_id, 'withdrawal-release:'.$withdrawal->id, 'Provider rejected withdrawal dispatch.');
            }, 3);

            return;
        }
        DB::table('withdrawals')->where('id', $withdrawal->id)->update(['state' => 'processing', 'provider_reference' => $response->json('reference'), 'updated_at' => now()]);
    }
}
