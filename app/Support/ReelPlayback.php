<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class ReelPlayback
{
    public static function videoUrl(string $reelId, Request $request): string
    {
        return self::absoluteSigned('api.reels.video', ['reel' => $reelId], $request);
    }

    public static function thumbnailUrl(string $reelId, Request $request): string
    {
        return self::absoluteSigned('api.reels.thumbnail', ['reel' => $reelId], $request);
    }

    public static function resolve(?string $stored, string $fallbackSigned): string
    {
        if (is_string($stored) && str_starts_with($stored, 'http')) {
            return $stored;
        }

        return $fallbackSigned;
    }

    private static function absoluteSigned(string $name, array $parameters, Request $request): string
    {
        $relative = URL::temporarySignedRoute($name, now()->addHours(6), $parameters, absolute: false);

        return rtrim($request->getSchemeAndHttpHost(), '/').$relative;
    }
}
