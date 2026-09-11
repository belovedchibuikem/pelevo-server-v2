<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ActivatePremiumPayment;
use App\Actions\Finance\ReverseLedgerTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WebhookController extends Controller
{
    public function __invoke(string $provider, Request $request, ReverseLedgerTransaction $reverse, ActivatePremiumPayment $activatePremium): JsonResponse
    {
        $secret = (string) config("services.{$provider}.webhook_secret");
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Pelevo-Signature'))) {
            return response()->json(['ok' => false], 401);
        } $eventId = (string) $request->header('X-Provider-Event-Id');
        if ($eventId === '') {
            return response()->json(['ok' => false], 422);
        }
        $id = (string) Str::ulid();
        $inserted = DB::table('provider_webhook_events')->insertOrIgnore(['id' => $id, 'provider' => $provider, 'provider_event_id' => $eventId, 'payload_hash' => hash('sha256', $request->getContent()), 'payload' => $request->getContent(), 'state' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        if (! $inserted) {
            return response()->json(['ok' => true, 'replayed' => true], 202);
        }
        $payload = $request->json()->all();
        $reference = data_get($payload, 'data.reference');
        if ($reference && in_array($payload['type'] ?? '', ['payment.success', 'charge.success', 'subscription.renewed'], true)) {
            try {
                $activatePremium->handle($provider, [
                    'reference' => $reference,
                    'amount_minor' => data_get($payload, 'data.amount_minor', data_get($payload, 'data.amount')),
                    'currency' => data_get($payload, 'data.currency'),
                    'invoice_id' => data_get($payload, 'data.invoice_id', $eventId),
                    'subscription_id' => data_get($payload, 'data.subscription_id', $reference),
                    'period_end' => data_get($payload, 'data.period_end'),
                    'receipt_url' => data_get($payload, 'data.receipt_url'),
                ]);
            } catch (InvalidArgumentException $exception) {
                DB::table('finance_exceptions')->insert(['id' => (string) Str::ulid(), 'type' => 'premium_webhook', 'provider' => $provider, 'reference' => $reference, 'state' => 'open', 'evidence' => json_encode(['error' => $exception->getMessage(), 'event_id' => $eventId], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('provider_webhook_events')->where('id', $id)->update(['state' => 'exception', 'processed_at' => now(), 'updated_at' => now()]);

                return response()->json(['ok' => true, 'review' => true], 202);
            }
        }
        if (($payload['type'] ?? '') === 'subscription.cancelled') {
            $subscriptionReference = (string) data_get($payload, 'data.subscription_id');
            DB::transaction(function () use ($provider, $subscriptionReference): void {
                $subscription = DB::table('premium_subscriptions')->where('provider', $provider)->where('provider_subscription_id', $subscriptionReference)->lockForUpdate()->first();
                if (! $subscription) {
                    return;
                }
                DB::table('premium_subscriptions')->where('id', $subscription->id)->update(['state' => 'cancelled', 'cancel_at' => now(), 'updated_at' => now()]);
                DB::table('premium_entitlements')->where('provider_reference', $provider.':'.$subscriptionReference)->update(['ends_at' => now(), 'state' => 'cancelled', 'updated_at' => now()]);
            }, 3);
        }
        if (($payload['type'] ?? '') === 'payment.refunded') {
            $invoiceReference = (string) data_get($payload, 'data.invoice_id');
            DB::transaction(function () use ($provider, $invoiceReference, $payload, $eventId): void {
                $invoice = DB::table('invoices')->where('provider', $provider)->where('provider_invoice_id', $invoiceReference)->lockForUpdate()->first();
                if (! $invoice) {
                    return;
                }
                DB::table('premium_refunds')->insertOrIgnore(['id' => (string) Str::ulid(), 'invoice_id' => $invoice->id, 'provider_refund_id' => (string) data_get($payload, 'data.refund_id', $eventId), 'amount_minor' => (int) data_get($payload, 'data.amount_minor', $invoice->total_minor), 'state' => 'succeeded', 'reason' => data_get($payload, 'data.reason'), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('invoices')->where('id', $invoice->id)->update(['state' => 'refunded', 'updated_at' => now()]);
                if ($invoice->premium_subscription_id) {
                    $subscription = DB::table('premium_subscriptions')->where('id', $invoice->premium_subscription_id)->first();
                    DB::table('premium_entitlements')->where('provider_reference', $provider.':'.$subscription->provider_subscription_id)->update(['state' => 'revoked', 'ends_at' => now(), 'updated_at' => now()]);
                }
            }, 3);
        }
        $states = ['transfer.success' => 'paid', 'transfer.failed' => 'failed', 'transfer.reversed' => 'reversed'];
        if ($reference && isset($states[$payload['type'] ?? ''])) {
            DB::transaction(function () use ($reference, $states, $payload, $reverse): void {
                $withdrawal = DB::table('withdrawals')->where('provider_reference', $reference)->lockForUpdate()->first();
                if (! $withdrawal) {
                    return;
                }
                $state = $states[$payload['type']];
                if (in_array($state, ['failed', 'reversed'], true)) {
                    $reverse->handle($withdrawal->ledger_transaction_id, 'withdrawal-release:'.$withdrawal->id, 'Provider webhook reported '.$state.'.');
                }
                DB::table('withdrawals')->where('id', $withdrawal->id)->update(['state' => $state, 'failure_reason' => $state === 'failed' ? (data_get($payload, 'data.reason') ?? 'Provider failure.') : null, 'processed_at' => now(), 'updated_at' => now()]);
            }, 3);
        }
        if ($reference && isset($states[$payload['type'] ?? ''])) {
            DB::transaction(function () use ($reference, $states, $payload, $reverse): void {
                $payout = DB::table('creator_payouts')->where('provider_reference', $reference)->lockForUpdate()->first();
                if (! $payout) {
                    return;
                }
                $state = $states[$payload['type']];
                if (in_array($state, ['failed', 'reversed'], true)) {
                    $reverse->handle($payout->ledger_transaction_id, 'creator-payout-release:'.$payout->id, 'Provider webhook reported '.$state.'.');
                }
                DB::table('creator_payouts')->where('id', $payout->id)->update(['state' => $state, 'failure_reason' => $state === 'failed' ? (data_get($payload, 'data.reason') ?? 'Provider failure.') : null, 'processed_at' => now(), 'updated_at' => now()]);
            }, 3);
        }
        DB::table('provider_webhook_events')->where('id', $id)->update(['state' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true], 202);
    }
}
