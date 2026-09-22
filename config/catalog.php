<?php

return [
    'rss_poll_batch_size' => (int) env('CATALOG_RSS_POLL_BATCH_SIZE', 80),
    'rss_queue_max_depth' => (int) env('CATALOG_RSS_QUEUE_MAX_DEPTH', 200),
    'rss_followed_only_depth' => (int) env('CATALOG_RSS_FOLLOWED_ONLY_DEPTH', 40),
    'feed_state_backfill_batch_size' => (int) env('CATALOG_FEED_STATE_BACKFILL_BATCH_SIZE', 1000),
    'feed_sync_run_retention_days' => (int) env('CATALOG_SYNC_RUN_RETENTION_DAYS', 90),
];
