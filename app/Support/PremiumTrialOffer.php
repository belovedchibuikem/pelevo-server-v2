<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 3-month Pelevo Plus trial.
 *
 * The banner is capped at one impression per 8 days, and only on every 8th
 * app session. Both gates have to pass so a burst of opens cannot spam anyone.
 */
final class PremiumTrialOffer
{
    public const SLUG = 'plus-3-month-trial';

    public const TRIAL_MONTHS = 3;

    public const COOLDOWN_DAYS = 8;

    public const SESSION_INTERVAL = 8;

    public const YEARLY_PRODUCT_ID = 'pelevo_plus_yearly';

    public const MONTHLY_PRODUCT_ID = 'pelevo_plus_monthly';

    public static function storeProductId(object $plan): string
    {
        $interval = strtolower((string) $plan->interval);

        return str_contains($interval, 'year') || str_contains($interval, 'annual')
            ? self::YEARLY_PRODUCT_ID
            : self::MONTHLY_PRODUCT_ID;
    }

    public static function eligible(bool $premium, int $sessionCount, ?CarbonInterface $lastShownAt, CarbonInterface $now): bool
    {
        if ($premium || $sessionCount < self::SESSION_INTERVAL || $sessionCount % self::SESSION_INTERVAL !== 0) {
            return false;
        }
        if ($lastShownAt === null) {
            return true;
        }

        return $lastShownAt->lessThanOrEqualTo($now->copy()->subDays(self::COOLDOWN_DAYS));
    }
}
