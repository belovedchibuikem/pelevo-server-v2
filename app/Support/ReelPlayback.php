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

    public static function muxThumbnailFromPlayback(?string $playbackUrl): ?string
    {
        if (! is_string($playbackUrl) || $playbackUrl === '') {
            return null;
        }
        if (preg_match('#(?:stream|image)\.mux\.com/([A-Za-z0-9]+)#', $playbackUrl, $matches) !== 1) {
            return null;
        }

        return 'https://image.mux.com/'.$matches[1].'/thumbnail.jpg';
    }

    public static function keepUploadedCover(?string $current): bool
    {
        if (! is_string($current) || $current === '') {
            return false;
        }
        if (str_starts_with($current, 'http://') || str_starts_with($current, 'https://')) {
            return ! str_contains($current, 'mux.com');
        }

        return ! str_starts_with($current, 'mux/');
    }

    public static function presentedThumbnail(?string $storedThumb, ?string $playbackUrl, string $reelId, Request $request): ?string
    {
        if (is_string($storedThumb) && str_starts_with($storedThumb, 'http')) {
            return $storedThumb;
        }
        $mux = self::muxThumbnailFromPlayback($playbackUrl);
        if ($mux !== null && ! self::keepUploadedCover($storedThumb)) {
            return $mux;
        }
        if (is_string($storedThumb) && $storedThumb !== '') {
            return self::thumbnailUrl($reelId, $request);
        }

        return $mux;
    }

    private static function absoluteSigned(string $name, array $parameters, Request $request): string
    {
        $relative = URL::temporarySignedRoute($name, now()->addHours(6), $parameters, absolute: false);

        return rtrim($request->getSchemeAndHttpHost(), '/').$relative;
    }
}
