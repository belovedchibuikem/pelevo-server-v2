<?php

namespace App\Services\Finance;

use App\Support\CountryCurrency;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DisplayFx
{
    /**
     * @return array{available_usd: string, currency: string, fx_rate: ?string, display_available: ?string}
     */
    public function present(int $usdCents, ?string $countryCode): array
    {
        $currency = CountryCurrency::currency($countryCode);
        $available = TokenValuation::formatCents($usdCents);
        if ($currency === 'USD') {
            return [
                'available_usd' => $available,
                'currency' => 'USD',
                'fx_rate' => '1.00',
                'display_available' => CountryCurrency::symbol('USD').$this->grouped($usdCents, 2),
            ];
        }

        $quote = $this->quote($currency);
        if ($quote === null) {
            return [
                'available_usd' => $available,
                'currency' => $currency,
                'fx_rate' => null,
                'display_available' => null,
            ];
        }

        $digits = CountryCurrency::fractionDigits($currency);
        $minor = $this->convertUsdCents($usdCents, (string) $quote->rate, $digits);

        return [
            'available_usd' => $available,
            'currency' => $currency,
            'fx_rate' => $this->formatRate((string) $quote->rate),
            'display_available' => CountryCurrency::symbol($currency).$this->grouped($minor, $digits),
        ];
    }

    public function quote(string $currency): ?object
    {
        $currency = strtoupper($currency);
        if ($currency === 'USD') {
            return (object) ['id' => null, 'rate' => '1.00000000'];
        }

        $maxAge = max(1, (int) config('reels_ads.fx.quote_max_age_minutes'));

        return DB::table('fx_rate_versions')
            ->where('base_unit', 'USD')
            ->where('quote_currency', $currency)
            ->where('approval_state', 'approved')
            ->where('effective_at', '<=', now())
            ->where('effective_at', '>=', now()->subMinutes($maxAge))
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    public function convertUsdCents(int $usdCents, string $rate, int $fractionDigits): int
    {
        if ($usdCents < 0 || $fractionDigits < 0 || $fractionDigits > 3) {
            throw new InvalidArgumentException('INVALID_MONEY');
        }
        if (! preg_match('/^\d+(\.\d{1,8})?$/', $rate)) {
            throw new InvalidArgumentException('INVALID_RATE');
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $scaled = ((int) $whole) * 100_000_000 + (int) str_pad(substr($fraction, 0, 8), 8, '0');
        $product = $usdCents * $scaled;
        $divisor = 100_000_000;
        if ($fractionDigits < 2) {
            $divisor *= 10 ** (2 - $fractionDigits);
        } elseif ($fractionDigits > 2) {
            $product *= 10 ** ($fractionDigits - 2);
        }
        $base = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        return $base + ($remainder * 2 >= $divisor ? 1 : 0);
    }

    public function formatRate(string $rate): string
    {
        if (! str_contains($rate, '.')) {
            return $rate.'.00';
        }
        [$whole, $fraction] = explode('.', $rate, 2);
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $whole.'.'.$fraction;
    }

    private function grouped(int $minor, int $digits): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);
        $scale = 10 ** $digits;
        $whole = $digits === 0 ? $minor : intdiv($minor, $scale);
        $body = number_format($whole, 0, '.', ',');
        if ($digits > 0) {
            $body .= '.'.str_pad((string) ($minor % $scale), $digits, '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$body;
    }
}
