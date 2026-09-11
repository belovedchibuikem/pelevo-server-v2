<?php

namespace App\Integrations\Rss;

class FeedUrlGuard
{
    public function ensureSafe(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeFeedUrlException('Feed URL must be an unauthenticated HTTPS URL.');
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            throw new UnsafeFeedUrlException('Feed host is not publicly routable.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($addresses === []) {
            throw new UnsafeFeedUrlException('Feed host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new UnsafeFeedUrlException('Feed host resolves to a private or reserved address.');
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        // DNS_A|DNS_AAAA is unreliable on many Linux builds; query separately.
        $addresses = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            if (! is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $addresses[] = $ip;
                }
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host) ?: [];
            foreach ($fallback as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $addresses[] = $ip;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
