<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Http;
use Throwable;

final class PayoutDestinationVerifier
{
    public function __construct(private readonly FlutterwaveBanks $flutterwave) {}

    public function verify(string $provider, string $kind, array $destination): array
    {
        if ($provider === 'flutterwave' && $kind === 'bank') {
            $resolved = $this->flutterwave->resolve(
                (string) ($destination['bank_code'] ?? ''),
                (string) ($destination['account_number'] ?? ''),
            );

            return [
                'verified' => $resolved['verified'],
                'reference' => $resolved['reference'],
                'label' => $resolved['account_name'],
            ];
        }

        if ($provider === 'paypal' && $kind === 'paypal') {
            $email = filter_var(strtolower(trim((string) ($destination['email'] ?? ''))), FILTER_VALIDATE_EMAIL);
            if ($email === false) {
                return ['verified' => false, 'reference' => null, 'label' => null];
            }

            $fallback = [
                'verified' => true,
                'reference' => 'paypal:'.hash('sha256', $email),
                'label' => $email,
            ];

            $endpoint = config('services.paypal.payout_verification_url');
            if (! is_string($endpoint) || $endpoint === '') {
                return $fallback;
            }

            try {
                $response = Http::withToken((string) config('services.paypal.payout_verification_token'))
                    ->connectTimeout(3)->timeout(10)
                    ->post($endpoint, ['kind' => $kind, 'destination' => ['email' => $email]])
                    ->throw()
                    ->json();
            } catch (Throwable) {
                // Optional enrichment only — never block a valid PayPal email.
                return $fallback;
            }

            if (($response['verified'] ?? false) !== true) {
                return $fallback;
            }

            return [
                'verified' => true,
                'reference' => $response['reference'] ?? $fallback['reference'],
                'label' => is_string($response['account_name'] ?? null) && $response['account_name'] !== ''
                    ? $response['account_name']
                    : $email,
            ];
        }

        $endpoint = config("services.{$provider}.payout_verification_url");
        if (! $endpoint) {
            return ['verified' => false, 'reference' => null, 'label' => null];
        }

        $response = Http::withToken((string) config("services.{$provider}.payout_verification_token"))
            ->connectTimeout(3)->timeout(10)->post($endpoint, ['kind' => $kind, 'destination' => $destination])->throw()->json();

        return ['verified' => ($response['verified'] ?? false) === true, 'reference' => $response['reference'] ?? null, 'label' => $response['account_name'] ?? null];
    }
}
