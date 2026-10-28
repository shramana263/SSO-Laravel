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

    'sms' => [
        'username' => env('SMS_GATEWAY_USERNAME'),
        'password' => env('SMS_GATEWAY_PASSWORD'),
        'sender_id' => env('SMS_SENDER_ID', 'STARCM'),
        'template_id' => env('SMS_TEMPLATE_ID'),
    ],

    'starsfa' => [
        'url' => env('STAR_SFA_URL', 'https://starsfa.starcement.co.in'),
    ],

    'starlink' => [
        'url' => env('STAR_LINK_URL', 'https://starlinkinfluencers.in'),
    ],

    'starsaathi' => [
        'url' => env('STAR_SAATHI_URL', 'https://starsaathi.starcement.co.in'),
    ],

    'starstellar' => [
        'url' => env('STAR_STELLAR_URL', 'https://starstellar.com'),
    ],

    'skillmine' => [
        'base_url'      => env('SKILLMINE_BASE_URL', 'https://uat-smtcauth-api.starcement.co.in/'),
        'client_id'     => env('SKILLMINE_CLIENT_ID'),
        'client_secret' => env('SKILLMINE_CLIENT_SECRET'),
        'redirect_uri'  => env('SKILLMINE_REDIRECT_URI'),
        'manual_mode'   => env('SKILLMINE_MANUAL_MODE', false),
    ],

];

