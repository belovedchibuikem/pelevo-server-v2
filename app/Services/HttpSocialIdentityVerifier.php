<?php

namespace App\Services;

use App\Contracts\SocialIdentityVerifier;
use App\Data\SocialIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class HttpSocialIdentityVerifier implements SocialIdentityVerifier
{
    public function verify(string $provider, string $token): ?SocialIdentity
    {
        return match ($provider) {
            'google' => $this->verifyGoogle($token),
            'apple' => $this->verifyApple($token),
            default => null,
        };
    }

    private function verifyGoogle(string $token): ?SocialIdentity
    {
        if (substr_count($token, '.') === 2) {
            $clientIds = $this->googleClientIds();
            if ($clientIds === []) {
                return null;
            }
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $token]);
            } catch (Throwable) {
                return null;
            }
            if (! $response->successful()) {
                return null;
            }
            $issuer = (string) $response->json('iss');
            if (! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
                return null;
            }
            $audience = (string) $response->json('aud');
            if (! in_array($audience, $clientIds, true)) {
                return null;
            }
            $expires = (int) $response->json('exp');
            if ($expires > 0 && $expires < time()) {
                return null;
            }

            return $this->identity($response->json());
        }

        $endpoint = config('services.google.identity_url');
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }
        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(3)->timeout(8)->get($endpoint);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        return $this->identity($response->json());
    }

    private function verifyApple(string $token): ?SocialIdentity
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }
        $kid = $header['kid'] ?? '';
        if (! is_string($kid) || $kid === '') {
            return null;
        }
        $pem = $this->applePublicKey($kid);
        if ($pem === null) {
            return null;
        }
        $signature = $this->base64UrlDecode($parts[2]);
        if ($signature === '' || openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            return null;
        }
        $audiences = $this->appleAudiences();
        if ($audiences === [] || ! in_array((string) ($payload['aud'] ?? ''), $audiences, true)) {
            return null;
        }
        $expires = (int) ($payload['exp'] ?? 0);
        if ($expires < time()) {
            return null;
        }
        $nonce = request()->input('nonce');
        if (is_string($nonce) && $nonce !== '') {
            $claim = (string) ($payload['nonce'] ?? '');
            if ($claim === '' || ! hash_equals(hash('sha256', $nonce), $claim)) {
                return null;
            }
        }
        $subject = $payload['sub'] ?? null;
        if (! is_string($subject) || $subject === '') {
            return null;
        }
        $email = $payload['email'] ?? null;

        return new SocialIdentity(
            $subject,
            is_string($email) ? Str::lower($email) : null,
            filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            null,
        );
    }

    private function identity(mixed $payload): ?SocialIdentity
    {
        if (! is_array($payload)) {
            return null;
        }
        $subject = $payload['sub'] ?? $payload['id'] ?? null;
        if (! is_string($subject) || $subject === '') {
            return null;
        }
        $email = $payload['email'] ?? null;

        return new SocialIdentity(
            $subject,
            is_string($email) ? Str::lower($email) : null,
            filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            is_string($payload['name'] ?? null) ? $payload['name'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private function googleClientIds(): array
    {
        $ids = config('services.google.client_ids');

        return is_array($ids) ? array_values(array_filter($ids, fn (mixed $id): bool => is_string($id) && $id !== '')) : [];
    }

    /**
     * @return list<string>
     */
    private function appleAudiences(): array
    {
        return array_values(array_filter([
            config('services.apple.application_id'),
            config('services.apple.service_id'),
        ], fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    private function applePublicKey(string $kid): ?string
    {
        $keys = Cache::remember('apple_sign_in_jwks', now()->addHours(12), function (): array {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://appleid.apple.com/auth/keys');
            } catch (Throwable) {
                return [];
            }
            $keys = $response->successful() ? $response->json('keys') : [];

            return is_array($keys) ? $keys : [];
        });
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
                return $this->pemFromRsaJwk($key);
            }
        }
        Cache::forget('apple_sign_in_jwks');

        return null;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function pemFromRsaJwk(array $jwk): ?string
    {
        $modulus = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
        $exponent = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($modulus === '' || $exponent === '') {
            return null;
        }
        $rsa = $this->asn1Sequence($this->asn1Integer($modulus).$this->asn1Integer($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if (! is_string($algorithm)) {
            return null;
        }
        $body = $this->asn1Sequence($algorithm."\x03".$this->asn1Length(strlen($rsa) + 1)."\x00".$rsa);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($body), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $bytes): string
    {
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        return "\x30".$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }
}
