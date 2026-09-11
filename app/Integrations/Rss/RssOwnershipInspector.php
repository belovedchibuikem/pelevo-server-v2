<?php

namespace App\Integrations\Rss;

use App\Models\Show;

final class RssOwnershipInspector
{
    public function __construct(private readonly RssFeedFetcher $fetcher) {}

    public function inspect(Show $show): array
    {
        $result = $this->fetcher->fetch($show, true);
        $channel = $result['xml']->channel ?? $result['xml'];
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $candidates = [(string) ($itunes->owner->email ?? ''), (string) ($channel->managingEditor ?? ''), (string) ($channel->webMaster ?? '')];
        $email = null;
        foreach ($candidates as $candidate) {
            if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $candidate, $match)) {
                $email = strtolower($match[0]);
                break;
            }
        }

        return ['email' => $email, 'description' => (string) ($channel->description ?? ''), 'status' => $result['status'], 'resolved_url' => $result['resolved_url'], 'fetched_at' => now()->toIso8601String()];
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
}
