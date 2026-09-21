<?php

namespace App\Support;

final class PelevoHttpUserAgent
{
    public const DEFAULT_CRAWLER = 'Pelevo/1.0.0 (podcast-sync; +https://pelevo.com)';

    /**
     * Identifies Pelevo's server-side RSS/media crawler. Register this string
     * with the IAB Tech Lab Spiders and Bots List so hosts do not treat it as
     * malicious traffic or as a human download.
     */
    public static function crawler(): string
    {
        $value = trim((string) config('rss.user_agent', self::DEFAULT_CRAWLER));

        return $value !== '' ? $value : self::DEFAULT_CRAWLER;
    }
}
