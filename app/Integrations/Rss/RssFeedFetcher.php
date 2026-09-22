<?php

namespace App\Integrations\Rss;

use App\Models\Show;
use App\Support\PelevoHttpUserAgent;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        $conditional = ! $bypassCache;
        $state = $show->feedState;
        $hops = 0;
        $maxHops = (int) config('rss.max_redirects');

        while ($hops <= $maxHops) {
            $this->guard->ensureSafe($url);
            $headers = $conditional
                ? array_filter([
                    'If-None-Match' => $state?->etag,
                    'If-Modified-Since' => $state?->last_modified,
                ])
                : ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'];
            $response = Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->withHeaders($headers)
                ->withUserAgent(PelevoHttpUserAgent::crawler())
                ->connectTimeout(config('rss.connect_timeout'))
                ->timeout(config('rss.timeout'))
                ->get($url);

            if ($response->status() === 304) {
                // ETags are per-URL. After a 301/308 the destination must be
                // fetched unconditionally so the new host's XML (title, artwork,
                // itunes:new-feed-url) is actually parsed.
                if ($sawPermanent && $conditional) {
                    $conditional = false;

                    continue;
                }

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
                $conditional = false;
                $hops++;

                continue;
            }

            if (in_array($response->status(), self::TEMPORARY_REDIRECTS, true)) {
                $url = $this->redirectUrl($url, (string) $response->header('Location'));
                $conditional = false;
                $hops++;

                continue;
            }

            $response->throw();

            return $this->parse($response, $url, $canonicalUrl, $sawPermanent);
        }

        throw new RuntimeException('RSS redirect limit exceeded.');
    }

    private function parse(Response $response, string $url, string $canonicalUrl, bool $sawPermanent): array
    {
        $body = $this->readLimitedBody($response);
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

    private function readLimitedBody(Response $response): string
    {
        $max = max(4096, (int) config('rss.max_bytes', 15728640));
        try {
            $stream = $response->toPsrResponse()->getBody();
        } catch (Throwable) {
            $stream = null;
        }

        if ($stream === null || ! $stream->isReadable()) {
            $body = (string) $response->body();

            return strlen($body) > $max ? $this->trimOversizedFeed($body, $max) : $body;
        }

        $buffer = '';
        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            if (strlen($buffer) >= $max) {
                return $this->trimOversizedFeed($buffer, $max);
            }
        }

        return $buffer;
    }

    /**
     * News/daily feeds often exceed the byte cap. RSS is newest-first, so keep
     * complete <item>/<entry> nodes from the start and close the document.
     */
    private function trimOversizedFeed(string $body, int $max): string
    {
        $body = substr($body, 0, $max);
        $itemEnd = strripos($body, '</item>');
        $entryEnd = strripos($body, '</entry>');
        $cutAt = max($itemEnd === false ? -1 : $itemEnd, $entryEnd === false ? -1 : $entryEnd);
        if ($cutAt < 0) {
            throw new RuntimeException('RSS document exceeds the configured limit.');
        }
        $tag = ($itemEnd !== false && $cutAt === $itemEnd) ? '</item>' : '</entry>';
        $trimmed = substr($body, 0, $cutAt + strlen($tag));
        if (stripos($trimmed, '<feed') !== false) {
            return $trimmed.'</feed>';
        }

        return $trimmed.'</channel></rss>';
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
