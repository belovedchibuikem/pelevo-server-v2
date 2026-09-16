<?php

namespace App\Integrations\Rss;

use App\Models\Show;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class RssFeedFetcher
{
    /** HTTP statuses that permanently move a feed URL. */
    private const PERMANENT_REDIRECTS = [301, 308];

    /** HTTP statuses that must be followed for this fetch only. */
    private const TEMPORARY_REDIRECTS = [302, 303, 307];

    public function __construct(private readonly FeedUrlGuard $guard) {}

    public function isSafeFeedUrl(string $url): bool
    {
        try {
            $this->guard->ensureSafe($url);

            return true;
        } catch (UnsafeFeedUrlException) {
            return false;
        }
    }

    public function fetch(Show $show, bool $bypassCache = false): array
    {
        $url = $show->rss_url;
        $canonicalUrl = $show->rss_url;
        $sawPermanent = false;
        $state = $show->feedState;
        for ($redirects = 0; $redirects <= config('rss.max_redirects'); $redirects++) {
            $this->guard->ensureSafe($url);
            $headers = $bypassCache ? ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'] : array_filter(['If-None-Match' => $state?->etag, 'If-Modified-Since' => $state?->last_modified]);
            $response = Http::withOptions(['allow_redirects' => false])->withHeaders($headers)->connectTimeout(config('rss.connect_timeout'))->timeout(config('rss.timeout'))->get($url);
            if ($response->status() === 304) {
                return [
                    'not_modified' => true,
                    'status' => 304,
                    'resolved_url' => $url,
                    'canonical_url' => $canonicalUrl,
                    'permanent_redirect' => $sawPermanent,
                ];
            }
            if (in_array($response->status(), self::PERMANENT_REDIRECTS, true)) {
                $url = $this->redirectUrl($url, (string) $response->header('Location'));
                $canonicalUrl = $url;
                $sawPermanent = true;

                continue;
            }
            if (in_array($response->status(), self::TEMPORARY_REDIRECTS, true)) {
                $url = $this->redirectUrl($url, (string) $response->header('Location'));

                continue;
            }
            $response->throw();

            return $this->parse($response, $url, $canonicalUrl, $sawPermanent);
        }
        throw new RuntimeException('RSS redirect limit exceeded.');
    }

    private function parse(Response $response, string $url, string $canonicalUrl, bool $sawPermanent): array
    {
        $body = $response->body();
        if (strlen($body) > config('rss.max_bytes')) {
            throw new RuntimeException('RSS document exceeds the configured limit.');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new RuntimeException('RSS document is malformed.');
        }

        return [
            'not_modified' => false,
            'status' => $response->status(),
            'resolved_url' => $url,
            'canonical_url' => $canonicalUrl,
            'permanent_redirect' => $sawPermanent,
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
            'content_hash' => hash('sha256', $body),
            'xml' => $xml,
        ];
    }

    private function redirectUrl(string $current, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new RuntimeException('RSS redirect is missing a Location header.');
        }
        if (str_starts_with($location, '//')) {
            $location = 'https:'.$location;
        }
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }
        $parts = parse_url($current);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new RuntimeException('Unsupported relative RSS redirect.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = $scheme.'://'.$host.$port;
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $path = $parts['path'] ?? '/';
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $origin.$directory.$location;
    }
}
