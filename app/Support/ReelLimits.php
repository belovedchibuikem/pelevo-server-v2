<?php

namespace App\Support;

final class ReelLimits
{
    public static function maxDurationMs(): int
    {
        return (int) config('media.max_reel_duration_ms', 180000);
    }

    public static function tooLongMessage(): string
    {
        $minutes = max(1, (int) ceil(self::maxDurationMs() / 60000));

        return "Reels may not exceed {$minutes} minutes.";
    }
}
