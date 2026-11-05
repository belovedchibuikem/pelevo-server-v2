<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verifies Google Play purchase tokens via the Android Publisher API.
 *
 * Credentials: Play Console → Setup → API access → service account JSON
 * (GOOGLE_PLAY_CREDENTIALS). The account must be granted financial access.
 */
class GooglePlayDeveloperClient
{
    /**
     * @return array{
     *     valid: bool,
     *     original_transaction_id: string,
     *     product_id: string,
     *     application_id: string,
     *     quantity: int,
     *     base_plan_id?: string|null,
     *     expires_at?: string|null,
     *     environment?: string|null,
     *     revoked?: bool
     * }
     */
    public function verifyPurchase(string $receipt, ?string $hintProductId = null): array
    {
        $parsed = $this->parseReceipt($receipt, $hintProductId);
        $packageName = (string) config('services.google.application_id');
        if ($packageName === '') {
            throw new RuntimeException('IAP verification service is not configured.');
        }

        $token = $this->accessToken();
        $subscription = $this->fetchSubscription($packageName, $parsed['token'], $token);
        if ($subscription !== null) {
            return $this->normalizeSubscription($subscription, $packageName, $parsed['token']);
        }

        $productId = $parsed['product_id'];
        if ($productId === null || $productId === '') {
            throw new RuntimeException('IAP_UNVERIFIED');
        }

        $product = $this->fetchProduct($packageName, $productId, $parsed['token'], $token);
        if ($product === null) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }

        return $this->normalizeProduct($product, $packageName, $productId, $parsed['token']);
    }

    /**
     * @return array{token: string, product_id: ?string}
     */
    private function parseReceipt(string $receipt, ?string $hintProductId): array
    {
        $trimmed = trim($receipt);
        if ($trimmed === '') {
            throw new RuntimeException('IAP_UNVERIFIED');
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $token = $decoded['purchaseToken']
                ?? $decoded['purchase_token']
                ?? $decoded['token']
                ?? null;
            $productId = $decoded['productId']
                ?? $decoded['product_id']
                ?? $hintProductId;
            if (is_string($token) && $token !== '') {
                return [
                    'token' => $token,
                    'product_id' => is_string($productId) && $productId !== '' ? $productId : null,
                ];
            }
        }

        return [
            'token' => $trimmed,
            'product_id' => is_string($hintProductId) && $hintProductId !== '' ? $hintProductId : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSubscription(string $packageName, string $purchaseToken, string $accessToken): ?array
    {
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s',
            rawurlencode($packageName),
            rawurlencode($purchaseToken),
        );
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->get($url);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException(
                $response->status() === 401 || $response->status() === 403
                    ? 'IAP verification service is not configured.'
                    : 'IAP_UNVERIFIED'
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProduct(string $packageName, string $productId, string $purchaseToken, string $accessToken): ?array
    {
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/products/%s/tokens/%s',
            rawurlencode($packageName),
            rawurlencode($productId),
            rawurlencode($purchaseToken),
        );
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->get($url);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException(
                $response->status() === 401 || $response->status() === 403
                    ? 'IAP verification service is not configured.'
                    : 'IAP_UNVERIFIED'
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array{
     *     valid: bool,
     *     original_transaction_id: string,
     *     product_id: string,
     *     application_id: string,
     *     quantity: int,
     *     base_plan_id?: string|null,
     *     expires_at?: string|null,
     *     environment?: string|null,
     *     revoked?: bool
     * }
     */
    private function normalizeSubscription(array $subscription, string $packageName, string $purchaseToken): array
    {
        $state = (string) ($subscription['subscriptionState'] ?? '');
        if ($state === 'SUBSCRIPTION_STATE_PENDING') {
            throw new RuntimeException('IAP_UNVERIFIED');
        }

        $lineItems = $subscription['lineItems'] ?? null;
        if (! is_array($lineItems) || $lineItems === []) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        $line = $lineItems[0];
        if (! is_array($line)) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        $productId = (string) ($line['productId'] ?? '');
        if ($productId === '') {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        $basePlanId = data_get($line, 'offerDetails.basePlanId');
        $expiresAt = isset($line['expiryTime']) ? (string) $line['expiryTime'] : null;
        $original = (string) ($subscription['latestOrderId'] ?? $purchaseToken);
        $revoked = in_array($state, [
            'SUBSCRIPTION_STATE_EXPIRED',
            'SUBSCRIPTION_STATE_REVOKED',
            'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED',
        ], true);

        return [
            'valid' => true,
            'original_transaction_id' => $original !== '' ? $original : $purchaseToken,
            'product_id' => $productId,
            'base_plan_id' => is_string($basePlanId) && $basePlanId !== '' ? $basePlanId : null,
            'application_id' => $packageName,
            'quantity' => 1,
            'expires_at' => $expiresAt,
            'environment' => array_key_exists('testPurchase', $subscription) ? 'sandbox' : 'production',
            'revoked' => $revoked,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{
     *     valid: bool,
     *     original_transaction_id: string,
     *     product_id: string,
     *     application_id: string,
     *     quantity: int,
     *     environment?: string|null,
     *     revoked?: bool
     * }
     */
    private function normalizeProduct(array $product, string $packageName, string $productId, string $purchaseToken): array
    {
        $purchaseState = (int) ($product['purchaseState'] ?? -1);
        // 0 purchased, 1 canceled, 2 pending
        if ($purchaseState !== 0) {
            throw new RuntimeException('IAP_UNVERIFIED');
        }
        $orderId = (string) ($product['orderId'] ?? $purchaseToken);
        $quantity = (int) ($product['quantity'] ?? 1);
        $environment = ((int) ($product['purchaseType'] ?? -1) === 0) ? 'sandbox' : 'production';

        return [
            'valid' => true,
            'original_transaction_id' => $orderId !== '' ? $orderId : $purchaseToken,
            'product_id' => (string) ($product['productId'] ?? $productId),
            'application_id' => $packageName,
            'quantity' => max(1, $quantity),
            'environment' => $environment,
            'revoked' => false,
        ];
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $email = (string) ($credentials['client_email'] ?? '');
        $cacheKey = 'google_play.access_token.'.hash('sha256', $email);

        return Cache::remember($cacheKey, 3000, function () use ($credentials, $email): string {
            $privateKey = $credentials['private_key'] ?? null;
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            if ($email === '' || ! is_string($privateKey) || $privateKey === '') {
                throw new RuntimeException('IAP verification service is not configured.');
            }

            $now = time();
            $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claim = $this->b64url(json_encode([
                'iss' => $email,
                'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claim;
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) {
                throw new RuntimeException('IAP verification service is not configured.');
            }
            $signature = '';
            if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('IAP verification service is not configured.');
            }
            $jwt = $unsigned.'.'.$this->b64url($signature);
            $response = Http::asForm()->connectTimeout(3)->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('IAP verification service is not configured.');
            }

            return $response->json('access_token');
        });
    }

    /**
     * @return array{type?: string, project_id?: string, private_key?: string, client_email?: string, token_uri?: string}
     */
    private function credentials(): array
    {
        $configured = config('services.google.play_credentials');
        if (is_string($configured) && $configured !== '' && str_starts_with(ltrim($configured), '{')) {
            $decoded = json_decode($configured, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('IAP verification service is not configured.');
            }

            return $decoded;
        }

        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : storage_path('app/google-play/service-account.json');
        if (! is_file($path)) {
            $fromBase = base_path($path);
            $path = is_file($fromBase) ? $fromBase : $path;
        }
        if (! is_file($path)) {
            throw new RuntimeException('IAP verification service is not configured.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('IAP verification service is not configured.');
        }

        return $decoded;
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
