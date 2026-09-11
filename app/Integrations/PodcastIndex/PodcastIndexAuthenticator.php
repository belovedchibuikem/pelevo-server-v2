<?php

namespace App\Integrations\PodcastIndex;

use Carbon\CarbonImmutable;

final class PodcastIndexAuthenticator
{
    public function headers(?CarbonImmutable $now = null): array
    {
        $timestamp = (string) ($now ?? CarbonImmutable::now())->timestamp;
        $key = (string) config('services.podcast_index.api_key');
        $secret = (string) config('services.podcast_index.api_secret');

        return ['X-Auth-Key' => $key, 'X-Auth-Date' => $timestamp, 'Authorization' => sha1($key.$secret.$timestamp), 'User-Agent' => (string) config('services.podcast_index.user_agent')];
    }
}
