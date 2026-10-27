<?php

namespace App\Services\Finance;

/**
 * Immutable retail anchor from the v1.2 token valuation spec.
 * 300 coins = $0.38. Local store tiers are display prices from Apple and Google.
 * Diamond rates already include the store fee and the platform's 30% of net.
 */
final class TokenValuation
{
    public const ANCHOR_COINS = 300;

    public const ANCHOR_USD_CENTS = 38;

    public const SCALE = 10_000_000;

    public const GROSS_COIN_USD_SCALED = 12_667;

    public const DIAMOND_USD_STANDARD = '0.0006207';

    public const DIAMOND_USD_SMALL_BUSINESS = '0.0007537';

    public const GIFT_MIN_USD_CENTS = 1_500;

    public const AD_MIN_USD_CENTS = 10_000;

    public const AD_HOLD_HOURS = 720;

    /**
     * @return array{gross_scaled: int, store_fee_scaled: int, net_scaled: int, platform_scaled: int, creator_scaled: int}
     */
    public static function split(int $storeFeeBasisPoints): array
    {
        $store = intdiv(self::GROSS_COIN_USD_SCALED * $storeFeeBasisPoints, 10_000);
        $net = self::GROSS_COIN_USD_SCALED - $store;
        $platform = intdiv($net * 3_000, 10_000);

        return [
            'gross_scaled' => self::GROSS_COIN_USD_SCALED,
            'store_fee_scaled' => $store,
            'net_scaled' => $net,
            'platform_scaled' => $platform,
            'creator_scaled' => $net - $platform,
        ];
    }

    public static function standard(): array
    {
        return self::split(3_000);
    }

    public static function smallBusiness(): array
    {
        return self::split(1_500);
    }

    public static function anchorUsdCents(int $coins): int
    {
        if ($coins < 0) {
            throw new \InvalidArgumentException('Coins cannot be negative.');
        }
        $numerator = $coins * self::ANCHOR_USD_CENTS;
        $base = intdiv($numerator, self::ANCHOR_COINS);
        $remainder = $numerator % self::ANCHOR_COINS;

        return $base + ($remainder * 2 >= self::ANCHOR_COINS ? 1 : 0);
    }

    public static function formatScaled(int $scaled): string
    {
        $whole = intdiv($scaled, self::SCALE);
        $fraction = str_pad((string) ($scaled % self::SCALE), 7, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.($fraction === '' ? '0' : $fraction);
    }

    public static function formatCents(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
