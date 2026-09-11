<?php

return [
    'connect_timeout' => (int) env('RSS_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('RSS_TIMEOUT', 10),
    'max_bytes' => (int) env('RSS_MAX_BYTES', 5242880),
    'max_redirects' => (int) env('RSS_MAX_REDIRECTS', 3),
    'failure_stale_threshold' => (int) env('RSS_FAILURE_STALE_THRESHOLD', 5),
];
