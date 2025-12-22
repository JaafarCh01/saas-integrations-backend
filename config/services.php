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

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL'),
        'webhook_secret' => env('N8N_WEBHOOK_SECRET'),
        'whatsapp_webhook_url' => env('N8N_WHATSAPP_WEBHOOK_URL'),
        'prompt_webhook_url' => env('N8N_PROMPT_WEBHOOK_URL'),
    ],

    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_webhook_url' => env('APP_URL') . '/api/webhooks/whatsapp',
    ],

    'unipile' => [
        'api_key' => env('UNIPILE_API_KEY'),
        'api_url' => env('UNIPILE_API_URL', 'https://api.unipile.com'),
    ],

    'n8n_instagram' => [
        'webhook_url' => env('N8N_INSTAGRAM_WEBHOOK_URL'),
    ],

    // Cloud Scheduler secret for cron endpoints
    'cron_secret' => env('CRON_SECRET'),

    // Gemini AI (for Email Agent)
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    // n8n Email Webhook (Hybrid Polling)
    'n8n_email' => [
        'webhook_url' => env('N8N_EMAIL_WEBHOOK_URL'),
    ],

];
