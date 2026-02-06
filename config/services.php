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
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
