<?php

namespace App\Actions\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ActivatePremiumPayment
{
    public function handle(string $provider, array $payment): object
    {
        return DB::transaction(function () use ($provider, $payment): object {
            $reference = (string) ($payment['reference'] ?? '');
            $checkout = DB::table('premium_checkouts')->where('provider', $provider)->where('provider_reference', $reference)->lockForUpdate()->first();
            if (! $checkout) {
                throw new InvalidArgumentException('PREMIUM_CHECKOUT_NOT_FOUND');
            }
            if ((int) ($payment['amount_minor'] ?? -1) !== (int) $checkout->amount_minor || strtoupper((string) ($payment['currency'] ?? '')) !== $checkout->currency) {
                throw new InvalidArgumentException('PREMIUM_PAYMENT_MISMATCH');
            }
            $invoiceReference = (string) ($payment['invoice_id'] ?? $reference);
            $existing = DB::table('invoices')->where('provider', $provider)->where('provider_invoice_id', $invoiceReference)->first();
            if ($existing) {
                return $existing;
            }
            $periodStart = now();
            $periodEnd = isset($payment['period_end']) ? now()->parse($payment['period_end']) : now()->addMonth();
            $subscriptionReference = (string) ($payment['subscription_id'] ?? $reference);
            $subscriptionValues = ['user_id' => $checkout->user_id, 'premium_plan_id' => $checkout->premium_plan_id, 'state' => 'active', 'current_period_start' => $periodStart, 'current_period_end' => $periodEnd, 'updated_at' => now()];
            $subscription = DB::table('premium_subscriptions')->where('provider', $provider)->where('provider_subscription_id', $subscriptionReference)->first();
            if ($subscription) {
                DB::table('premium_subscriptions')->where('id', $subscription->id)->update($subscriptionValues);
            } else {
                DB::table('premium_subscriptions')->insert([...$subscriptionValues, 'id' => (string) Str::ulid(), 'provider' => $provider, 'provider_subscription_id' => $subscriptionReference, 'created_at' => now()]);
            }
            $subscription = DB::table('premium_subscriptions')->where('provider', $provider)->where('provider_subscription_id', $subscriptionReference)->first();
            $invoiceId = (string) Str::ulid();
            DB::table('invoices')->insert(['id' => $invoiceId, 'user_id' => $checkout->user_id, 'premium_subscription_id' => $subscription->id, 'premium_checkout_id' => $checkout->id, 'provider' => $provider, 'provider_invoice_id' => $invoiceReference, 'subtotal_minor' => $checkout->amount_minor, 'tax_minor' => 0, 'total_minor' => $checkout->amount_minor, 'currency' => $checkout->currency, 'state' => 'paid', 'receipt_url' => $payment['receipt_url'] ?? null, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $entitlementReference = $provider.':'.$subscriptionReference;
            $entitlementValues = ['user_id' => $checkout->user_id, 'premium_plan_id' => $checkout->premium_plan_id, 'starts_at' => $periodStart, 'ends_at' => $periodEnd, 'state' => 'active', 'updated_at' => now()];
            if (DB::table('premium_entitlements')->where('provider_reference', $entitlementReference)->exists()) {
                DB::table('premium_entitlements')->where('provider_reference', $entitlementReference)->update($entitlementValues);
            } else {
                DB::table('premium_entitlements')->insert([...$entitlementValues, 'id' => (string) Str::ulid(), 'provider_reference' => $entitlementReference, 'created_at' => now()]);
            }
            DB::table('premium_checkouts')->where('id', $checkout->id)->update(['state' => 'paid', 'updated_at' => now()]);

            return DB::table('invoices')->find($invoiceId);
        }, 3);
    }
}
