<?php

namespace App\Integrations\Rss;

/**
 * Canonical podcast feed address. http and https copies of the same path are
 * one podcast, and tracking parameters are ignored.
 */
final class FeedAddress
{
    private const TRACKING = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'fbclid',
        'gclid',
        'mc_cid',
        'mc_eid',
        'igshid',
    ];

    public function __construct(
        public readonly string $url,
        public readonly string $key,
    ) {}

    public static function looksLikeUrl(string $raw): bool
    {
        $parts = parse_url(trim($raw));

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    public static function tryParse(string $raw): ?self
    {
        $raw = trim($raw);
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! self::isPublicHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $dropPort = $port === null
            || ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);
        $path = (string) ($parts['path'] ?? '');
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        if ($path === '') {
            $path = '/';
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $parsed);
            foreach ($parsed as $name => $value) {
                if (! is_string($name) || ! is_scalar($value)) {
                    continue;
                }
                $text = trim((string) $value);
                if ($text === '' || in_array(strtolower($name), self::TRACKING, true)) {
                    continue;
                }
                $query[$name] = $text;
            }
            ksort($query);
        }

        $queryString = $query === [] ? '' : http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $portSuffix = $dropPort ? '' : ':'.$port;
        $suffix = $path.($queryString !== '' ? '?'.$queryString : '');

        return new self($scheme.'://'.$host.$portSuffix.$suffix, $host.$portSuffix.$suffix);
    }

    public function httpsUrl(): string
    {
        return str_starts_with($this->url, 'http://')
            ? 'https://'.substr($this->url, strlen('http://'))
            : $this->url;
    }

    public static function isPublicHost(string $host): bool
    {
        $value = strtolower(rtrim($host, '.'));
        if ($value === ''
            || $value === 'localhost'
            || str_ends_with($value, '.local')
            || str_ends_with($value, '.localhost')
            || str_ends_with($value, '.internal')
            || str_ends_with($value, '.localdomain')) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            $first = (int) explode('.', $value)[0];

            return $first !== 0 && $first < 224;
        }

        if (str_contains($value, ':')) {
            return false;
        }

        return str_contains($value, '.');
    }
}
