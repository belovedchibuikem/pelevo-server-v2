<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Earn is available only when the connection the API sees is in the United States.
 * The gate is the exit country. It does not reject an address for belonging to a tunnel.
 */
final class EarnRegion
{
    public function allows(Request $request): bool
    {
        return $this->country($request) === 'US';
    }

    public function country(Request $request): ?string
    {
        if (app()->runningUnitTests()) {
            $code = strtoupper(trim((string) $request->header('X-Test-Country', 'US')));

            return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
        }

        if (config('finance.earn_trust_cloudflare_country')) {
            $cloudflare = strtoupper(trim((string) $request->header('CF-IPCountry', '')));
            if (preg_match('/^[A-Z]{2}$/', $cloudflare) && ! in_array($cloudflare, ['XX', 'T1'], true)) {
                return $cloudflare;
            }
        }

        $ip = (string) $request->ip();
        if ($ip === '' || $this->unroutable($ip)) {
            return null;
        }

        $key = 'earn_country:'.hash('sha256', $ip);
        $cached = Cache::get($key);
        if (is_string($cached) && preg_match('/^[A-Z]{2}$/', $cached)) {
            return $cached;
        }

        $resolved = $this->lookup($ip);
        if (is_string($resolved)) {
            Cache::put($key, $resolved, now()->addHours(12));
        }

        return $resolved;
    }

    private function lookup(string $ip): ?string
    {
        try {
            $response = Http::timeout(3)->acceptJson()->get('https://ipwho.is/'.$ip);
            $code = strtoupper((string) $response->json('country_code'));
            if (! $response->ok() || $response->json('success') === false || ! preg_match('/^[A-Z]{2}$/', $code)) {
                return null;
            }

            return $code;
        } catch (Throwable) {
            return null;
        }
    }

    private function unroutable(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
