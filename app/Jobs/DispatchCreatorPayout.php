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

final class DispatchCreatorPayout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $payoutId)
    {
        $this->onQueue('finance');
    }

    public function uniqueId(): string
    {
        return $this->payoutId;
    }

    public function handle(ReverseLedgerTransaction $reverse): void
    {
        $payout = DB::table('creator_payouts')->where('id', $this->payoutId)->where('state', 'approved')->first();
        if (! $payout) {
            return;
        }
        $method = DB::table('payout_methods')->where('id', $payout->payout_method_id)->first();
        $endpoint = config("services.{$method->provider}.payout_url");
        if (! $endpoint) {
            return;
        }
        $response = Http::withToken((string) config("services.{$method->provider}.payout_token"))->withHeader('Idempotency-Key', $payout->id)->connectTimeout(3)->timeout(15)->post($endpoint, ['amount' => $payout->amount_minor, 'currency' => $payout->currency, 'destination' => decrypt($method->destination_encrypted)]);
        DB::transaction(function () use ($payout, $response, $reverse): void {
            $locked = DB::table('creator_payouts')->where('id', $payout->id)->lockForUpdate()->first();
            if (! $response->successful()) {
                $reverse->handle($locked->ledger_transaction_id, 'creator-payout-release:'.$locked->id, 'Provider rejected creator payout.');
                DB::table('creator_payouts')->where('id', $locked->id)->update(['state' => 'failed', 'failure_reason' => 'Provider rejected dispatch.', 'processed_at' => now(), 'updated_at' => now()]);

                return;
            }
            DB::table('creator_payouts')->where('id', $locked->id)->update(['state' => 'processing', 'provider_reference' => $response->json('reference'), 'updated_at' => now()]);
        }, 3);
        $amount = number_format(((int) $payout->amount_minor) / 100, 2).' '.strtoupper((string) $payout->currency);
        $failed = ! $response->successful();
        app(MailPreference::class)->queueToCreator((string) $payout->creator_profile_id, new PelevoNotice(
            subjectLine: $failed ? 'Your Pelevo creator payout failed' : 'Your Pelevo creator payout is on the way',
            eyebrow: 'Creator payout',
            heading: $failed ? 'Payout failed' : 'Payout processing',
            intro: $failed
                ? 'We could not send your payout of '.$amount.'. The amount remains available for a later payout.'
                : 'We sent your payout of '.$amount.' to your saved payout method.',
            detail: $failed ? 'Provider rejected dispatch.' : null,
        ));
    }
}
