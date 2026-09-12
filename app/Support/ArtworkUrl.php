<?php

namespace App\Support;

final class ArtworkUrl
{
    public static function sanitize(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $value = trim($url);
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (! str_starts_with($value, 'https://') && ! str_starts_with($value, 'http://')) {
            return null;
        }

        // Buzzsprout and similar hosts sometimes emit empty image ids as `….jpg` → `…?.jpg`.
        if (str_contains($value, '?.') || preg_match('/\?\.(jpe?g|png|webp|gif|avif)(?:\?|$)/i', $value) === 1) {
            return null;
        }

        $path = (string) (parse_url($value, PHP_URL_PATH) ?? '');
        if (preg_match('/\?\.(jpe?g|png|webp|gif|avif)$/i', $path) === 1) {
            return null;
        }

        return $value;
    }
}
