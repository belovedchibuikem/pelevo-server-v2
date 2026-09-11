<?php

return [
    'public_enabled' => (bool) env('FINANCE_PUBLIC_ENABLED', false),
    'earn_min_withdraw_coins' => (int) env('EARN_MIN_WITHDRAW_COINS', 1250),
    'earn_daily_completion_cap' => (int) env('EARN_DAILY_COMPLETION_CAP', 20),
    'reel_qualified_view_pcn' => (int) env('REEL_QUALIFIED_VIEW_PCN', 1),
];
