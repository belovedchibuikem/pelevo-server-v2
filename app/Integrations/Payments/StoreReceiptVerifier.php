<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StoreReceiptVerifier
{
    public function verify(string $store, string $receipt): array
    {
        $endpoint = config("services.{$store}.verification_url");
        if (! $endpoint) {
            throw new RuntimeException('IAP verification service is not configured.');
        }

        $response = Http::withToken((string) config("services.{$store}.verification_token"))
            ->connectTimeout(3)
            ->timeout(10)
            ->post($endpoint, ['receipt' => $receipt])
            ->throw();
        $result = $response->json();
        if (($result['valid'] ?? false) !== true || empty($result['original_transaction_id']) || empty($result['product_id'])) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        if (($result['revoked'] ?? false) === true || (isset($result['quantity']) && (int) $result['quantity'] !== 1)) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        $applicationId = config("services.{$store}.application_id");
        if ($applicationId && ! hash_equals((string) $applicationId, (string) ($result['application_id'] ?? ''))) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }

        return $result;
    }
}
