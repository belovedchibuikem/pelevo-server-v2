<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Http;

final class PayoutDestinationVerifier
{
    public function verify(string $provider, string $kind, array $destination): array
    {
        $endpoint = config("services.{$provider}.payout_verification_url");
        if (! $endpoint) {
            return ['verified' => false, 'reference' => null, 'label' => null];
        }

        $response = Http::withToken((string) config("services.{$provider}.payout_verification_token"))
            ->connectTimeout(3)->timeout(10)->post($endpoint, ['kind' => $kind, 'destination' => $destination])->throw()->json();

        return ['verified' => ($response['verified'] ?? false) === true, 'reference' => $response['reference'] ?? null, 'label' => $response['account_name'] ?? null];
    }
}
