<?php

return [
    'scheduler_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS', 180),
    'readiness_token' => env('OPERATIONS_READINESS_TOKEN'),
    'queues' => [
        'default',
        'notifications',
        'feeds',
        'rss',
        'catalog',
        'recommendations',
        'privacy',
        'ai',
        'admin-exports',
        'claims',
        'finance',
        'media',
    ],
    'manual_tasks' => [
        'catalog:poll-feeds' => 'Poll due RSS feeds',
        'horizon:snapshot' => 'Capture Horizon metrics',
    ],
];
