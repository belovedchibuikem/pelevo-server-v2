<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 1-month Pelevo Plus trial.
 *
 * The banner is capped at one impression per 8 days, and only on every 8th
 * app session. Both gates have to pass so a burst of opens cannot spam anyone.
 */
final class PremiumTrialOffer
{
    public const SLUG = 'plus-1-month-trial';

    public const LEGACY_SLUG = 'plus-3-month-trial';

    public const TRIAL_MONTHS = 1;

    public const COOLDOWN_DAYS = 8;

    public const SESSION_INTERVAL = 8;

    public const YEARLY_PRODUCT_ID = 'pelevo_plus_yearly';

    public const MONTHLY_PRODUCT_ID = 'pelevo_plus_monthly';

    /** Google Play umbrella subscription (monthly + yearly base plans). */
    public const GOOGLE_PRODUCT_ID = 'pelevo_plus_individual';

    public const MONTHLY_BASE_PLAN_ID = 'monthly';

    public const YEARLY_BASE_PLAN_ID = 'yearly';

    public const PLAY_MONTHLY_OFFER_ID = 'one-month-free-trial-monthly';

    public const PLAY_YEARLY_OFFER_ID = 'one-month-free-trial-yearly';

    public static function matchesSlug(?string $slug): bool
    {
        return $slug === self::SLUG || $slug === self::LEGACY_SLUG;
    }

    public static function storeProductId(object $plan): string
    {
        return self::isYearlyPlan($plan)
            ? self::YEARLY_PRODUCT_ID
            : self::MONTHLY_PRODUCT_ID;
    }

    public static function isYearlyPlan(object $plan): bool
    {
        $interval = strtolower((string) $plan->interval);

        return str_contains($interval, 'year') || str_contains($interval, 'annual');
    }

    /**
     * App Store uses monthly/yearly SKUs. Play uses {@see GOOGLE_PRODUCT_ID}
     * with base plans; accept either the logical id or the Play product when
     * the base plan (if present) matches the selected plan.
     *
     * @param  array<string, mixed>  $verified
     */
    public static function matchesVerifiedProduct(object $plan, array $verified): bool
    {
        $expected = self::storeProductId($plan);
        $productId = (string) ($verified['product_id'] ?? '');
        if ($productId === $expected) {
            return true;
        }
        if ($productId !== self::GOOGLE_PRODUCT_ID) {
            return false;
        }
        $basePlanId = strtolower((string) (
            $verified['base_plan_id']
            ?? $verified['basePlanId']
            ?? ''
        ));
        if ($basePlanId === '') {
            return true;
        }
        if (self::isYearlyPlan($plan)) {
            return $basePlanId === self::YEARLY_BASE_PLAN_ID
                || str_contains($basePlanId, 'year')
                || str_contains($basePlanId, 'annual');
        }

        return $basePlanId === self::MONTHLY_BASE_PLAN_ID
            || str_contains($basePlanId, 'month');
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
