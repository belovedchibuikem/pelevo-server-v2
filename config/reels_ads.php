<?php

return [
    'enabled' => filter_var(env('REELS_ADS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'allocation_enabled' => filter_var(env('REELS_ADS_ALLOCATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'legacy_pcn_accrual_enabled' => filter_var(env('REELS_LEGACY_PCN_ACCRUAL_ENABLED', false), FILTER_VALIDATE_BOOL),
    'valid_view_ms' => (int) env('REELS_ADS_VALID_VIEW_MS', 30_000),
    'dedup_hours' => (int) env('REELS_ADS_DEDUP_HOURS', 24),
    'heartbeat_interval_ms' => (int) env('REELS_ADS_HEARTBEAT_INTERVAL_MS', 8_000),
    'heartbeat_grace_ms' => (int) env('REELS_ADS_HEARTBEAT_GRACE_MS', 2_000),
    'threshold_min' => (int) env('REELS_ADS_THRESHOLD_MIN', 5),
    'threshold_max' => (int) env('REELS_ADS_THRESHOLD_MAX', 8),
    'cycle_ttl_seconds' => (int) env('REELS_ADS_CYCLE_TTL_SECONDS', 7_200),
    'pending_ttl_seconds' => (int) env('REELS_ADS_PENDING_TTL_SECONDS', 600),
    'payout_pool_usd_micros' => (int) env('REELS_ADS_PAYOUT_POOL_USD_MICROS', 200),
    'hold_days' => (int) env('REELS_ADS_HOLD_DAYS', 30),
    'reserve_bps' => (int) env('REELS_ADS_RESERVE_BPS', 500),
    'ad_unit_keys' => [
        'android' => env('ADMOB_REELS_ANDROID_AD_UNIT_KEY', 'reels-native-android'),
        'ios' => env('ADMOB_REELS_IOS_AD_UNIT_KEY', 'reels-native-ios'),
    ],
    'supported_payout_currencies' => ['USD', 'NGN'],
    'admob_account_id' => env('ADMOB_ACCOUNT_ID'),
    'settlement_timezone' => env('ADMOB_SETTLEMENT_TIMEZONE', 'America/Los_Angeles'),
    'fx' => [
        'usd_ngn_rate' => (string) env('REELS_ADS_USD_NGN_RATE', ''),
        'source' => env('REELS_ADS_FX_SOURCE', 'manual'),
        'quote_max_age_minutes' => (int) env('REELS_ADS_FX_QUOTE_MAX_AGE_MINUTES', 1_440),
        'spread_bps' => (int) env('REELS_ADS_FX_SPREAD_BPS', 0),
    ],
];
