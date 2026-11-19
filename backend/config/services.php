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

    /*
    | Customer social sign-in (D9). Web uses the OAuth redirect/callback
    | (`redirect` is the API callback URL registered with the provider); the
    | apps post the provider's identity token, accepted for any audience in
    | `app_client_ids` (Google iOS/Android client ids) or `app_ids` (Apple
    | bundle ids). Apple's client secret is an ES256 JWT signed with the .p8
    | key (`private_key` holds the PEM, or `private_key_path` a file).
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/auth/social/google/callback'),
        'app_client_ids' => array_values(array_filter(explode(',', (string) env('GOOGLE_APP_CLIENT_IDS', '')))),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'private_key_path' => env('APPLE_PRIVATE_KEY_PATH'),
        'redirect' => env('APPLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/auth/social/apple/callback'),
        'app_ids' => array_values(array_filter(explode(',', (string) env('APPLE_APP_IDS', '')))),
    ],

];
