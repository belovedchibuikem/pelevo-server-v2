<?php

namespace Tests\Unit;

use App\Services\HttpSocialIdentityVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SocialIdentityVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.google.client_ids' => ['client.apps.googleusercontent.com'],
            'services.apple.application_id' => 'com.Podemeraldltd.pelevo',
            'services.apple.service_id' => null,
        ]);
    }

    public function test_google_id_token_requires_a_matching_audience(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'iss' => 'https://accounts.google.com',
                'aud' => 'client.apps.googleusercontent.com',
                'sub' => 'google-subject',
                'email' => 'Ada@Example.com',
                'email_verified' => 'true',
                'name' => 'Ada',
                'exp' => (string) (time() + 600),
            ]),
        ]);

        $identity = app(HttpSocialIdentityVerifier::class)->verify('google', 'header.payload.signature');

        $this->assertNotNull($identity);
        $this->assertSame('google-subject', $identity->subject);
        $this->assertSame('ada@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Ada', $identity->name);
    }

    public function test_google_id_token_rejects_a_different_audience(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'iss' => 'https://accounts.google.com',
                'aud' => 'other.apps.googleusercontent.com',
                'sub' => 'google-subject',
                'email' => 'ada@example.com',
                'email_verified' => 'true',
                'exp' => (string) (time() + 600),
            ]),
        ]);

        $this->assertNull(app(HttpSocialIdentityVerifier::class)->verify('google', 'header.payload.signature'));
    }

    public function test_google_id_token_fails_closed_without_configured_client_ids(): void
    {
        config(['services.google.client_ids' => []]);

        $this->assertNull(app(HttpSocialIdentityVerifier::class)->verify('google', 'header.payload.signature'));
        Http::assertNothingSent();
    }

    public function test_apple_identity_token_verifies_signature_audience_and_nonce(): void
    {
        $key = $this->rsaKey();
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $jwk = [
            'kty' => 'RSA',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
        Http::fake([
            'appleid.apple.com/*' => Http::response(['keys' => [$jwk]]),
        ]);
        $nonce = 'nonce-raw-value';
        $token = $this->appleToken($key, [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.Podemeraldltd.pelevo',
            'sub' => 'apple-subject',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'exp' => time() + 600,
            'nonce' => hash('sha256', $nonce),
        ]);
        request()->merge(['nonce' => $nonce]);

        $identity = app(HttpSocialIdentityVerifier::class)->verify('apple', $token);

        $this->assertNotNull($identity);
        $this->assertSame('apple-subject', $identity->subject);
        $this->assertSame('ada@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);

        request()->merge(['nonce' => 'different-nonce']);
        Cache::flush();
        $this->assertNull(app(HttpSocialIdentityVerifier::class)->verify('apple', $token));
    }

    private function rsaKey()
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        if ($key !== false) {
            return $key;
        }
        $configs = [
            'C:/wamp64/bin/php/php8.3.28/extras/ssl/openssl.cnf',
            'C:/wamp64/bin/php/php8.4.15/extras/ssl/openssl.cnf',
            getenv('OPENSSL_CONF') ?: '',
        ];
        foreach ($configs as $config) {
            if (! is_string($config) || $config === '' || ! is_file($config)) {
                continue;
            }
            $key = openssl_pkey_new($options + ['config' => $config]);
            if ($key !== false) {
                return $key;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appleToken($key, array $payload): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$body, $signature, $key, OPENSSL_ALGO_SHA256);

        return $header.'.'.$body.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
