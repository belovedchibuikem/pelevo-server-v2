<?php

namespace App\Integrations\Rss;

use App\Models\Show;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class RssFeedFetcher
{
    public function __construct(private readonly FeedUrlGuard $guard) {}

    public function fetch(Show $show, bool $bypassCache = false): array
    {
        $url = $show->rss_url;
        $state = $show->feedState;
        for ($redirects = 0; $redirects <= config('rss.max_redirects'); $redirects++) {
            $this->guard->ensureSafe($url);
            $headers = $bypassCache ? ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'] : array_filter(['If-None-Match' => $state?->etag, 'If-Modified-Since' => $state?->last_modified]);
            $response = Http::withOptions(['allow_redirects' => false])->withHeaders($headers)->connectTimeout(config('rss.connect_timeout'))->timeout(config('rss.timeout'))->get($url);
            if ($response->status() === 304) {
                return ['not_modified' => true, 'resolved_url' => $url];
            }
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $url = $this->redirectUrl($url, (string) $response->header('Location'));

                continue;
            }
            $response->throw();

            return $this->parse($response, $url);
        }
        throw new RuntimeException('RSS redirect limit exceeded.');
    }

    private function parse(Response $response, string $url): array
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

        return ['not_modified' => false, 'status' => $response->status(), 'resolved_url' => $url, 'etag' => $response->header('ETag'), 'last_modified' => $response->header('Last-Modified'), 'content_hash' => hash('sha256', $body), 'xml' => $xml];
    }

    private function redirectUrl(string $current, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }
        $parts = parse_url($current);
        if (! str_starts_with($location, '/')) {
            throw new RuntimeException('Unsupported relative RSS redirect.');
        }

        return $parts['scheme'].'://'.$parts['host'].$location;
    }
}
