<?php

return [
    'rss_poll_batch_size' => (int) env('CATALOG_RSS_POLL_BATCH_SIZE', 500),
    'rss_queue_max_depth' => (int) env('CATALOG_RSS_QUEUE_MAX_DEPTH', 5000),
    'feed_state_backfill_batch_size' => (int) env('CATALOG_FEED_STATE_BACKFILL_BATCH_SIZE', 1000),
    'feed_sync_run_retention_days' => (int) env('CATALOG_SYNC_RUN_RETENTION_DAYS', 90),
];
