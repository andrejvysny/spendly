<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],
    'gocardless' => [
        'access_token' => env('GOCARDLESS_ACCESS_TOKEN'),
        'secret_id' => env('GOCARDLESS_SECRET_ID'),
        'secret_key' => env('GOCARDLESS_SECRET_KEY'),
        'use_mock' => (function () {
            $value = env('GOCARDLESS_USE_MOCK');
            if ($value !== null && $value !== '') {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            return in_array(env('APP_ENV', 'production'), ['local', 'development'], true);
        })(),
        'mock_data_path' => env('GOCARDLESS_MOCK_DATA_PATH', base_path('sample_data/gocardless_bank_account_data')),

        // How many days before a 90-day consent lapses the UI starts warning about it.
        'consent_warning_days' => env('GOCARDLESS_CONSENT_WARNING_DAYS', 7),

        // How old a requisition's last status poll may be before gocardless:check-consent re-polls it.
        'consent_check_stale_hours' => env('GOCARDLESS_CONSENT_CHECK_STALE_HOURS', 24),

        // How recently an account may have synced before gocardless:dispatch-sync skips it.
        // Keeps a four-hourly schedule from spending request quota on accounts nothing changed for.
        'min_sync_interval_hours' => env('GOCARDLESS_MIN_SYNC_INTERVAL_HOURS', 8),

        // Spacing between consecutive queued account syncs. One scheduled run can cover every
        // account on the installation; without a stagger it would arrive at the bank as a burst.
        'dispatch_stagger_seconds' => env('GOCARDLESS_DISPATCH_STAGGER_SECONDS', 20),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ml' => [
        'enabled' => env('ML_ENABLED', false),
        'url' => env('ML_SERVICE_URL', 'http://ml-service:8001'),
        'token' => env('ML_SERVICE_TOKEN', ''),
        'timeout' => env('ML_TIMEOUT', 30),
    ],

];
