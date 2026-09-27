<?php

return [
    // Public coin writes (earn sessions, withdrawals, gifts, IAP). Keep false in
    // production until finance sign-off, then set FINANCE_PUBLIC_ENABLED=true.
    'public_enabled' => filter_var(
        env('FINANCE_PUBLIC_ENABLED', env('APP_ENV') === 'local' ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'earn_min_withdraw_coins' => (int) env('EARN_MIN_WITHDRAW_COINS', 1250),
    'earn_daily_completion_cap' => (int) env('EARN_DAILY_COMPLETION_CAP', 20),
    'earn_coin_usd' => (string) env('EARN_COIN_USD', '0.004'),
    'earn_ip_user_cap' => (int) env('EARN_IP_USER_CAP', 12),
    'earn_trust_cloudflare_country' => filter_var(env('EARN_TRUST_CF_COUNTRY', false), FILTER_VALIDATE_BOOLEAN),
    'reel_qualified_view_pcn' => (int) env('REEL_QUALIFIED_VIEW_PCN', 1),
];
