<?php

namespace App\Integrations\PodcastIndex;

use Carbon\CarbonImmutable;

final class PodcastIndexAuthenticator
{
    public function headers(?CarbonImmutable $now = null): array
    {
        $timestamp = (string) ($now ?? CarbonImmutable::now('UTC'))->timestamp;
        $key = trim((string) config('services.podcast_index.api_key'));
        $secret = trim((string) config('services.podcast_index.api_secret'));

        if ($key === '' || $secret === '') {
            throw new PodcastIndexException('Podcast Index API key or secret is missing.');
        }

        return [
            'User-Agent' => (string) config('services.podcast_index.user_agent'),
            'X-Auth-Key' => $key,
            'X-Auth-Date' => $timestamp,
            'Authorization' => sha1($key.$secret.$timestamp),
        ];
    }
}
