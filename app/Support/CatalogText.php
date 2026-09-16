<?php

namespace App\Support;

final class CatalogText
{
    /**
     * Postgres rejects invalid UTF-8 (e.g. truncated Hebrew from RSS or SQL errors).
     */
    public static function utf8(mixed $value, int $maxChars = 10000): string
    {
        $text = is_string($value) ? $value : (string) ($value ?? '');
        $text = str_replace("\0", '', $text);
        if (function_exists('mb_scrub')) {
            $text = mb_scrub($text, 'UTF-8');
        } elseif ($text !== '' && ! mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8,Windows-1255,ISO-8859-8,Windows-1252,ISO-8859-1');
            $text = is_string($converted) ? $converted : '';
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            return mb_substr($text, 0, $maxChars);
        }

        return $text;
    }
}
