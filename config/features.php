<?php

return [
    'sync' => (bool) env('SYNC_ENABLED', true),
    'backups' => (bool) env('BACKUPS_ENABLED', true),
    'share_cards' => (bool) env('SHARE_CARDS_ENABLED', true),
    'referrals' => (bool) env('REFERRALS_ENABLED', true),
    'notifications' => (bool) env('NOTIFICATIONS_ENABLED', true),
    'ai' => env('AI_ENABLED') === null
        ? filled(env('AI_PROVIDER_TOKEN'))
        : filter_var(env('AI_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'recommendations' => (bool) env('RECOMMENDATIONS_ENABLED', true),
    'live' => (bool) env('LIVE_ENABLED', true),
];
