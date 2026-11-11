<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ActivatePremiumPayment;
use App\Actions\Finance\ReverseLedgerTransaction;
use App\Actions\Finance\SettleDiamondCashout;
use App\Mail\PelevoNotice;
use App\Services\MailPreference;
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
        if ($provider === 'flutterwave' && $request->hasHeader('verif-hash')) {
            return $this->flutterwaveTransfer($request, $secret);
        }
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
            $withdrawalMail = null;
            $diamondMail = null;
            DB::transaction(function () use ($reference, $states, $payload, $reverse, &$withdrawalMail, &$diamondMail): void {
                $withdrawal = DB::table('withdrawals')->where('provider_reference', $reference)->lockForUpdate()->first();
                if (! $withdrawal) {
                    $state = $states[$payload['type']];
                    $reasonText = (string) (data_get($payload, 'data.reason') ?? 'Provider failure.');
                    $changed = app(SettleDiamondCashout::class)->apply((string) $reference, $state, $reasonText);
                    if ($changed && in_array($state, ['paid', 'failed'], true)) {
                        $cashout = DB::table('diamond_cashouts')->where('provider_reference', $reference)->first();
                        if ($cashout) {
                            $diamondMail = [$cashout->user_id, $state, (int) $cashout->diamonds, (int) $cashout->net_minor, $cashout->currency, $state === 'failed' ? $reasonText : null];
                        }
                    }

                    return;
                }
                $state = $states[$payload['type']];
                if (in_array($state, ['failed', 'reversed'], true)) {
                    $reverse->handle($withdrawal->ledger_transaction_id, 'withdrawal-release:'.$withdrawal->id, 'Provider webhook reported '.$state.'.');
                }
                DB::table('withdrawals')->where('id', $withdrawal->id)->update(['state' => $state, 'failure_reason' => $state === 'failed' ? (data_get($payload, 'data.reason') ?? 'Provider failure.') : null, 'processed_at' => now(), 'updated_at' => now()]);
                if (in_array($state, ['paid', 'failed'], true)) {
                    $withdrawalMail = [$withdrawal->user_id, $state, (int) $withdrawal->coins, $state === 'failed' ? (string) (data_get($payload, 'data.reason') ?? 'Provider failure.') : null];
                }
            }, 3);
            if (is_array($withdrawalMail)) {
                [$userId, $state, $coins, $reason] = $withdrawalMail;
                $paid = $state === 'paid';
                app(MailPreference::class)->queueToUser((string) $userId, new PelevoNotice(
                    subjectLine: $paid ? 'Your Pelevo withdrawal was paid' : 'Your Pelevo withdrawal did not go through',
                    eyebrow: 'Wallet',
                    heading: $paid ? 'Withdrawal paid' : 'Withdrawal failed',
                    intro: $paid
                        ? 'We sent your withdrawal of '.$coins.' coins.'
                        : 'Your withdrawal of '.$coins.' coins could not be completed.',
                    detail: $paid ? null : $reason,
                ));
            }
            if (is_array($diamondMail)) {
                [$userId, $state, $diamonds, $netMinor, $currency, $reason] = $diamondMail;
                $paid = $state === 'paid';
                app(MailPreference::class)->queueToUser((string) $userId, new PelevoNotice(
                    subjectLine: $paid ? 'Your Pelevo diamond cashout was paid' : 'Your Pelevo diamond cashout did not go through',
                    eyebrow: 'Diamonds',
                    heading: $paid ? 'Diamond cashout paid' : 'Diamond cashout failed',
                    intro: $paid
                        ? 'We sent '.$currency.' '.number_format($netMinor / 100, 2, '.', '').' for '.$diamonds.' diamonds.'
                        : 'Your cashout of '.$diamonds.' diamonds could not be completed. The diamonds have been returned.',
                    detail: $paid ? null : $reason,
                ));
            }
        }
        if ($reference && isset($states[$payload['type'] ?? ''])) {
            $payoutMail = null;
            DB::transaction(function () use ($reference, $states, $payload, $reverse, &$payoutMail): void {
                $payout = DB::table('creator_payouts')->where('provider_reference', $reference)->lockForUpdate()->first();
                if (! $payout) {
                    return;
                }
                $state = $states[$payload['type']];
                if (in_array($state, ['failed', 'reversed'], true)) {
                    $reverse->handle($payout->ledger_transaction_id, 'creator-payout-release:'.$payout->id, 'Provider webhook reported '.$state.'.');
                }
                DB::table('creator_payouts')->where('id', $payout->id)->update(['state' => $state, 'failure_reason' => $state === 'failed' ? (data_get($payload, 'data.reason') ?? 'Provider failure.') : null, 'processed_at' => now(), 'updated_at' => now()]);
                if (in_array($state, ['paid', 'failed'], true)) {
                    $payoutMail = [$payout->creator_profile_id, $state, (int) $payout->amount_minor, (string) $payout->currency, $state === 'failed' ? (string) (data_get($payload, 'data.reason') ?? 'Provider failure.') : null];
                }
            }, 3);
            if (is_array($payoutMail)) {
                [$creatorProfileId, $state, $amountMinor, $currency, $reason] = $payoutMail;
                $amount = number_format($amountMinor / 100, 2).' '.strtoupper($currency);
                $paid = $state === 'paid';
                app(MailPreference::class)->queueToCreator((string) $creatorProfileId, new PelevoNotice(
                    subjectLine: $paid ? 'Your Pelevo creator payout was paid' : 'Your Pelevo creator payout failed',
                    eyebrow: 'Creator payout',
                    heading: $paid ? 'Payout paid' : 'Payout failed',
                    intro: $paid
                        ? 'Your payout of '.$amount.' has been paid.'
                        : 'Your payout of '.$amount.' could not be completed.',
                    detail: $paid ? null : $reason,
                ));
            }
        }
        DB::table('provider_webhook_events')->where('id', $id)->update(['state' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true], 202);
    }

    private function flutterwaveTransfer(Request $request, string $secret): JsonResponse
    {
        $hash = (string) $request->header('verif-hash');
        if ($secret === '' || ! hash_equals($secret, $hash)) {
            return response()->json(['ok' => false], 401);
        }
        $payload = $request->json()->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = trim((string) ($data['reference'] ?? ''));
        $event = (string) ($payload['event'] ?? $payload['event.type'] ?? '');
        $status = strtoupper((string) ($data['status'] ?? ''));
        $state = match ($status) {
            'SUCCESSFUL', 'SUCCESS' => 'paid',
            'FAILED' => 'failed',
            'REVERSED' => 'reversed',
            default => $status === '' && $event === 'transfer.completed' ? 'paid' : null,
        };
        if ($reference === '' || $state === null || ! str_contains($event, 'transfer')) {
            return response()->json(['ok' => true, 'ignored' => true], 202);
        }
        $eventId = (string) ($data['id'] ?? hash('sha256', $request->getContent()));
        $reason = (string) ($data['complete_message'] ?? $data['reason'] ?? 'Flutterwave reported '.$state.'.');
        $changed = app(SettleDiamondCashout::class)->apply($reference, $state, $reason);
        $inserted = DB::table('provider_webhook_events')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'provider' => 'flutterwave',
            'provider_event_id' => 'transfer:'.$eventId,
            'payload_hash' => hash('sha256', $request->getContent()),
            'payload' => $request->getContent(),
            'state' => 'processed',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $inserted) {
            return response()->json(['ok' => true, 'replayed' => true], 202);
        }
        if ($changed && in_array($state, ['paid', 'failed'], true)) {
            $cashout = DB::table('diamond_cashouts')->where('provider_reference', $reference)->first();
            if ($cashout) {
                $paid = $state === 'paid';
                $digits = \App\Support\CountryCurrency::fractionDigits((string) $cashout->currency);
                $amount = number_format(((int) $cashout->net_minor) / (10 ** $digits), $digits, '.', ',');
                app(MailPreference::class)->queueToUser((string) $cashout->user_id, new PelevoNotice(
                    subjectLine: $paid ? 'Your Pelevo diamond cashout was paid' : 'Your Pelevo diamond cashout did not go through',
                    eyebrow: 'Diamonds',
                    heading: $paid ? 'Diamond cashout paid' : 'Diamond cashout failed',
                    intro: $paid
                        ? 'We sent '.$cashout->currency.' '.$amount.' for '.$cashout->diamonds.' diamonds.'
                        : 'Your cashout of '.$cashout->diamonds.' diamonds could not be completed. The diamonds have been returned.',
                    detail: $paid ? null : $reason,
                ));
            }
        }

        return response()->json(['ok' => true], 202);
    }
}
