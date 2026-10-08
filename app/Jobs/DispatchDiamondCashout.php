<?php

namespace App\Jobs;

use App\Actions\Finance\ReverseLedgerTransaction;
use App\Mail\PelevoNotice;
use App\Services\MailPreference;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DispatchDiamondCashout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $cashoutId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->cashoutId;
    }

    public function handle(ReverseLedgerTransaction $reverse): void
    {
        $cashout = DB::table('diamond_cashouts')->where('id', $this->cashoutId)->where('state', 'approved')->first();
        if (! $cashout) {
            return;
        }
        $method = DB::table('payout_methods')->where('id', $cashout->payout_method_id)->first();
        $endpoint = $method ? config("services.{$method->provider}.payout_url") : null;
        if (! $endpoint) {
            return;
        }
        $payload = [
            'amount_minor' => (int) $cashout->net_minor,
            'currency' => $cashout->currency,
            'diamonds' => (int) $cashout->diamonds,
            'destination' => decrypt($method->destination_encrypted),
        ];
        $response = Http::withToken((string) config("services.{$method->provider}.payout_token"))
            ->withHeader('Idempotency-Key', $cashout->id)
            ->connectTimeout(3)
            ->timeout(15)
            ->post($endpoint, $payload);
        if (! $response->successful()) {
            DB::transaction(function () use ($cashout, $reverse): void {
                $current = DB::table('diamond_cashouts')->where('id', $cashout->id)->lockForUpdate()->first();
                if (! $current || $current->state !== 'approved') {
                    return;
                }
                DB::table('diamond_cashouts')->where('id', $cashout->id)->update([
                    'state' => 'failed',
                    'failure_reason' => 'Provider rejected dispatch.',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
                $reverse->handle($cashout->ledger_transaction_id, 'diamond-cashout-release:'.$cashout->id, 'Provider rejected diamond cashout dispatch.');
            }, 3);
            app(MailPreference::class)->queueToUser((string) $cashout->user_id, new PelevoNotice(
                subjectLine: 'Your Pelevo diamond cashout did not go through',
                eyebrow: 'Diamonds',
                heading: 'Cashout failed',
                intro: 'Your cashout of '.$cashout->diamonds.' diamonds could not be sent. The diamonds have been returned.',
                detail: 'Provider rejected dispatch.',
            ));

            return;
        }
        DB::table('diamond_cashouts')->where('id', $cashout->id)->where('state', 'approved')->update([
            'state' => 'processing',
            'provider_reference' => $response->json('reference'),
            'updated_at' => now(),
        ]);
    }
}
