<?php

namespace App\Support;

final class OnboardingCatalog
{
    /**
     * Canonical interest labels as stored for the existing Interests screen.
     *
     * @var list<string>
     */
    public const INTERESTS = [
        'news',
        'true crime',
        'comedy',
        'business',
        'health',
        'history',
        'technology',
        'sports',
        'music',
        'culture',
        'education',
        'stories',
        'lifestyle',
        'politics',
        'science',
    ];

    /**
     * Locales offered on the existing language control.
     *
     * @var list<string>
     */
    public const LOCALES = ['en', 'fr', 'yo', 'sw', 'ha'];

    public static function normalizeInterest(string $interest): ?string
    {
        $normalized = mb_strtolower(trim($interest));

        return in_array($normalized, self::INTERESTS, true) ? $normalized : null;
    }
}
