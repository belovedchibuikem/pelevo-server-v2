<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PushDispatch
{
    /** @param array{type: string, title: string, body: string, data?: array} $message */
    public function notifyUser(string $userId, array $message): int
    {
        $preferences = DB::table('notification_preferences')->where('user_id', $userId)->first();
        if ($preferences && ! ($preferences->push_enabled ?? true)) {
            return 0;
        }

        $tokens = DB::table('push_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('provider', 'fcm')
            ->get();
        if ($tokens->isEmpty()) {
            return 0;
        }

        try {
            $accessToken = $this->accessToken();
            $projectId = $this->projectId();
        } catch (\Throwable $error) {
            Log::warning('push.credentials_unavailable', ['error' => $error->getMessage()]);

            return 0;
        }

        $sent = 0;
        foreach ($tokens as $row) {
            try {
                $deviceToken = Crypt::decryptString($row->token_encrypted);
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                        'message' => [
                            'token' => $deviceToken,
                            'notification' => [
                                'title' => $message['title'],
                                'body' => $message['body'],
                            ],
                            'data' => $this->dataPayload($message),
                            'android' => [
                                'priority' => 'HIGH',
                                'notification' => [
                                    'channel_id' => 'pelevo_alerts',
                                ],
                            ],
                            'apns' => [
                                'headers' => ['apns-priority' => '10'],
                                'payload' => [
                                    'aps' => [
                                        'sound' => 'default',
                                    ],
                                ],
                            ],
                        ],
                    ]);
                if ($response->successful()) {
                    $sent++;
                } else {
                    Log::warning('push.dispatch_rejected', [
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);
                }
            } catch (\Throwable $error) {
                Log::warning('push.dispatch_failed', ['user_id' => $userId, 'error' => $error->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @param  array{type: string, title?: string, body?: string, data?: array}  $message
     * @return array<string, string>
     */
    private function dataPayload(array $message): array
    {
        $payload = array_merge(
            ['type' => (string) $message['type']],
            is_array($message['data'] ?? null) ? $message['data'] : [],
        );
        $data = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $data[(string) $key] = $value ? '1' : '0';
                continue;
            }
            if (is_scalar($value)) {
                $data[(string) $key] = (string) $value;
            }
        }

        return $data;
    }

    private function projectId(): string
    {
        $credentials = $this->credentials();
        $projectId = $credentials['project_id'] ?? null;
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('Firebase service account is missing project_id.');
        }

        return $projectId;
    }

    /** @return array{type?: string, project_id?: string, private_key?: string, client_email?: string, token_uri?: string} */
    private function credentials(): array
    {
        $path = config('services.fcm.credentials');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('FCM credentials file is not configured.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('FCM credentials file is invalid JSON.');
        }

        return $decoded;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3000, function (): string {
            $credentials = $this->credentials();
            $email = $credentials['client_email'] ?? null;
            $privateKey = $credentials['private_key'] ?? null;
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            if (! is_string($email) || ! is_string($privateKey) || $email === '' || $privateKey === '') {
                throw new RuntimeException('Firebase service account is incomplete.');
            }

            $now = time();
            $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claim = $this->b64url(json_encode([
                'iss' => $email,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claim;
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) {
                throw new RuntimeException('Firebase private key could not be loaded.');
            }
            $signature = '';
            if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Firebase JWT could not be signed.');
            }
            $jwt = $unsigned.'.'.$this->b64url($signature);
            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Firebase access token request failed.');
            }

            return $response->json('access_token');
        });
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
