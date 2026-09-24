<?php

namespace App\Support;

final class ReelLimits
{
    public static function maxDurationMs(): int
    {
        return (int) config('media.max_reel_duration_ms', 180000);
    }

    public static function cappedDurationMs(int $durationMs): int
    {
        return max(0, min($durationMs, self::maxDurationMs()));
    }

    public static function tooLongMessage(): string
    {
        $minutes = max(1, (int) ceil(self::maxDurationMs() / 60000));

        return "Reels may not exceed {$minutes} minutes.";
    }

    public static function truncatedMessage(): string
    {
        $minutes = max(1, (int) ceil(self::maxDurationMs() / 60000));

        return "Your video was longer than {$minutes} minutes, so we kept the first {$minutes} minutes and discarded the rest.";
    }
}
