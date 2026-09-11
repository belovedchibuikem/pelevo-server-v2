<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'podcast_index' => [
        'enabled' => (bool) env('PODCAST_INDEX_ENABLED', false),
        'base_url' => env('PODCAST_INDEX_BASE_URL', 'https://api.podcastindex.org/api/1.0'),
        'api_key' => env('PODCAST_INDEX_API_KEY'),
        'api_secret' => env('PODCAST_INDEX_API_SECRET'),
        'user_agent' => env('PODCAST_INDEX_USER_AGENT', 'Pelevo/1.3'),
        'timeout' => (int) env('PODCAST_INDEX_TIMEOUT', 5),
    ],

    'paystack' => ['webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'), 'payout_verification_url' => env('PAYSTACK_PAYOUT_VERIFICATION_URL'), 'payout_verification_token' => env('PAYSTACK_SECRET_KEY'), 'payout_url' => env('PAYSTACK_PAYOUT_URL'), 'payout_token' => env('PAYSTACK_SECRET_KEY'), 'checkout_url' => env('PAYSTACK_CHECKOUT_URL'), 'checkout_token' => env('PAYSTACK_SECRET_KEY'), 'restore_url' => env('PAYSTACK_VERIFY_URL')],
    'paypal' => ['payout_verification_url' => env('PAYPAL_PAYOUT_VERIFICATION_URL'), 'payout_verification_token' => env('PAYPAL_ACCESS_TOKEN'), 'payout_url' => env('PAYPAL_PAYOUT_URL'), 'payout_token' => env('PAYPAL_ACCESS_TOKEN')],
    'flutterwave' => ['webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'), 'checkout_url' => env('FLUTTERWAVE_CHECKOUT_URL'), 'checkout_token' => env('FLUTTERWAVE_SECRET_KEY'), 'restore_url' => env('FLUTTERWAVE_VERIFY_URL')],
    'apple' => ['identity_url' => env('APPLE_IDENTITY_URL'), 'webhook_secret' => env('APPLE_WEBHOOK_SECRET'), 'verification_url' => env('APPLE_IAP_VERIFICATION_URL'), 'verification_token' => env('APPLE_IAP_VERIFICATION_TOKEN'), 'application_id' => env('APPLE_BUNDLE_ID')],
    'google' => ['identity_url' => env('GOOGLE_IDENTITY_URL', 'https://openidconnect.googleapis.com/v1/userinfo'), 'webhook_secret' => env('GOOGLE_WEBHOOK_SECRET'), 'verification_url' => env('GOOGLE_IAP_VERIFICATION_URL'), 'verification_token' => env('GOOGLE_IAP_VERIFICATION_TOKEN'), 'application_id' => env('GOOGLE_PACKAGE_NAME')],
    'earn_integrity' => [
        'url' => env('EARN_INTEGRITY_URL'),
        'token' => env('EARN_INTEGRITY_TOKEN'),
        'allow_jwt_passthrough' => (bool) env('EARN_INTEGRITY_ALLOW_JWT_PASSTHROUGH', false),
    ],
    'ai' => ['url' => env('AI_PROVIDER_URL'), 'token' => env('AI_PROVIDER_TOKEN'), 'provider' => env('AI_PROVIDER', 'configured'), 'model' => env('AI_MODEL')],
    'mux' => ['token_id' => env('MUX_TOKEN_ID'), 'token_secret' => env('MUX_TOKEN_SECRET'), 'signing_key' => env('MUX_SIGNING_KEY')],
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase/service-account.json')),
    ],

];
