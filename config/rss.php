<?php

return [
    'connect_timeout' => (int) env('RSS_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('RSS_TIMEOUT', 20),
    'max_bytes' => (int) env('RSS_MAX_BYTES', 15728640),
    'max_redirects' => (int) env('RSS_MAX_REDIRECTS', 3),
    'failure_stale_threshold' => (int) env('RSS_FAILURE_STALE_THRESHOLD', 5),
    'upsert_chunk' => (int) env('RSS_UPSERT_CHUNK', 100),
    // Re-download the RSS body on a cadence so artwork/title/new-feed-url
    // still refresh when hosts keep serving 304 for an unchanged ETag.
    'channel_refresh_hours' => (int) env('RSS_CHANNEL_REFRESH_HOURS', 24),
    // IAB crawler identity for RSS fetches and server-side media proxying.
    'user_agent' => env('PELEVO_CRAWLER_USER_AGENT', 'Pelevo/1.0.0 (podcast-sync; +https://pelevo.com)'),
];
