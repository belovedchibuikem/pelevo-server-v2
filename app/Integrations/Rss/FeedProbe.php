<?php

namespace App\Integrations\Rss;

use App\Support\PelevoHttpUserAgent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads just enough of a public feed to decide whether it is alive.
 * A 404 or 410, or a 2xx body that is not a feed, is dead. Timeouts,
 * 429, and 5xx are unreachable and can be retried.
 */
final class FeedProbe
{
    public function __construct(private readonly FeedUrlGuard $guard) {}

    /**
     * @return array{health: string, title: ?string, author: ?string, artwork: ?string, message: ?string}
     */
    public function inspect(string $url): array
    {
        return $this->read($url, 0);
    }

    /**
     * @return array{health: string, title: ?string, author: ?string, artwork: ?string, message: ?string}
     */
    private function read(string $url, int $hops): array
    {
        $empty = ['health' => 'unreachable', 'title' => null, 'author' => null, 'artwork' => null, 'message' => null];

        try {
            $this->guard->ensureSafe($url);
        } catch (UnsafeFeedUrlException $exception) {
            return [...$empty, 'health' => 'dead', 'message' => $exception->getMessage()];
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->withHeaders(['Accept' => 'application/rss+xml, application/xml, text/xml, */*'])
                ->withUserAgent(PelevoHttpUserAgent::crawler())
                ->connectTimeout(5)
                ->timeout(8)
                ->get($url);
        } catch (ConnectionException) {
            return [...$empty, 'message' => 'This podcast feed didn’t respond. Try again in a moment.'];
        }

        $status = $response->status();
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $next = $this->redirectUrl($url, (string) $response->header('Location'));
            $response->toPsrResponse()->getBody()->close();
            if ($next === null || $next === $url || $hops >= 3) {
                return [...$empty, 'message' => 'This podcast feed didn’t respond. Try again in a moment.'];
            }

            return $this->read($next, $hops + 1);
        }

        if (in_array($status, [404, 410], true)) {
            return [...$empty, 'health' => 'dead', 'message' => 'This podcast feed is unavailable, so it can’t be added.'];
        }

        if ($status === 408 || $status === 429 || $status >= 500 || $status < 200 || $status >= 300) {
            return [...$empty, 'message' => 'This podcast feed didn’t respond. Try again in a moment.'];
        }

        $body = $response->toPsrResponse()->getBody();
        $chunk = $body->read(65536);
        if ($chunk === '' && $body->isSeekable()) {
            $body->rewind();
            $chunk = $body->getContents();
            if (strlen($chunk) > 65536) {
                $chunk = substr($chunk, 0, 65536);
            }
        }
        $body->close();

        $sample = strtolower($chunk);
        $alive = str_contains($sample, '<rss') || str_contains($sample, '<feed') || str_contains($sample, '<channel');
        if (! $alive) {
            return [...$empty, 'health' => 'dead', 'message' => 'This podcast feed is unavailable, so it can’t be added.'];
        }

        $channel = $this->channelSlice($chunk);

        return [
            'health' => 'alive',
            'title' => $this->firstTag($channel, 'title'),
            'author' => $this->firstTag($channel, 'itunes:author') ?? $this->firstTag($channel, 'author'),
            'artwork' => $this->artwork($channel),
            'message' => null,
        ];
    }

    private function redirectUrl(string $current, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }

    private function channelSlice(string $chunk): string
    {
        $lower = strtolower($chunk);
        $item = strpos($lower, '<item');
        $entry = strpos($lower, '<entry');
        $cut = match (true) {
            $item === false && $entry === false => strlen($chunk),
            $item === false => $entry,
            $entry === false => $item,
            default => min($item, $entry),
        };

        return substr($chunk, 0, $cut);
    }

    private function firstTag(string $xml, string $tag): ?string
    {
        $pattern = '/<'.preg_quote($tag, '/').'\b[^>]*>(.*?)<\/'.preg_quote($tag, '/').'>/is';
        if (! preg_match($pattern, $xml, $match)) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private function artwork(string $xml): ?string
    {
        if (preg_match('/<itunes:image\b[^>]*\bhref=(["\'])(.*?)\1/i', $xml, $match)) {
            $url = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
        }

        return null;
    }
}
