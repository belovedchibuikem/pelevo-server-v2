<?php

namespace App\Services\Finance;

use App\Support\CountryCurrency;
use InvalidArgumentException;

final class PayoutRoute
{
    /**
     * African creators are paid in local currency through Paystack or Flutterwave.
     * Everyone else is paid in USD through PayPal.
     *
     * @return array{currency: string, gateway: string}
     */
    public function resolve(?string $countryCode, object $method): array
    {
        $country = strtoupper(trim((string) $countryCode));
        $provider = (string) $method->provider;
        $kind = (string) $method->kind;

        $currency = CountryCurrency::currency($country);
        if (CountryCurrency::isAfrican($country) && $currency !== 'USD') {
            $localBank = $kind === 'bank' && (
                ($country === 'NG' && in_array($provider, ['paystack', 'flutterwave'], true))
                || ($country !== 'NG' && $provider === 'flutterwave')
            );
            if (! $localBank) {
                throw new InvalidArgumentException('AFRICAN_BANK_REQUIRED');
            }

            return ['currency' => $currency, 'gateway' => $provider];
        }

        if ($provider !== 'paypal') {
            throw new InvalidArgumentException('PAYPAL_REQUIRED');
        }

        return ['currency' => 'USD', 'gateway' => 'paypal'];
    }
}
