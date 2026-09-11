<?php

namespace App\Integrations\Payments;

use Illuminate\Support\Facades\Http;
use Throwable;

final class EarnIntegrityVerifier
{
    public function valid(string $token, string $deviceIdentifier, string $sessionId, int $sequence, string $nonce): bool
    {
        if ($nonce === '' || $token === '') {
            return false;
        }

        $endpoint = config('services.earn_integrity.url');
        if ($endpoint) {
            try {
                return Http::withToken((string) config('services.earn_integrity.token'))
                    ->connectTimeout(3)->timeout(8)
                    ->post($endpoint, [
                        'integrity_token' => $token,
                        'device_id' => $deviceIdentifier,
                        'session_id' => $sessionId,
                        'sequence' => $sequence,
                        'nonce' => $nonce,
                    ])
                    ->successful();
            } catch (Throwable) {
                return false;
            }
        }

        $secret = (string) config('services.earn_integrity.token');
        if ($secret !== '' && str_starts_with($token, 'v1.')) {
            $expected = 'v1.'.hash_hmac('sha256', implode('|', [$deviceIdentifier, $sessionId, (string) $sequence, $nonce]), $secret);

            return hash_equals($expected, $token);
        }

        // Firebase App Check / Play Integrity JWTs are opaque here; require the
        // external verifier URL in production. Accept only well-formed JWTs in
        // local when explicitly enabled.
        if (app()->environment('local') && (bool) config('services.earn_integrity.allow_jwt_passthrough', false)) {
            return substr_count($token, '.') === 2 && strlen($token) > 40;
        }

        return false;
    }
}
