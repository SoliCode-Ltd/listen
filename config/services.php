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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'verify_ssl' => env('GOOGLE_OAUTH_VERIFY_SSL', true),
    ],

    'google_tts' => [
        'endpoint' => env('SPEECH_TTS_ENDPOINT', env('GOOGLE_TTS_ENDPOINT', 'https://texttospeech.googleapis.com/v1/text:synthesize')),
        'api_key' => env('SPEECH_TTS_API_KEY', env('GOOGLE_TTS_API_KEY', '')),
        'project_id' => env('SPEECH_TTS_PROJECT_ID', env('GOOGLE_TTS_PROJECT_ID', env('GOOGLE_CLOUD_PROJECT', ''))),
        'token_uri' => env('SPEECH_TTS_TOKEN_URI', env('GOOGLE_TTS_TOKEN_URI', 'https://oauth2.googleapis.com/token')),
        'credentials_json' => env('SPEECH_TTS_CREDENTIALS_JSON', env('GOOGLE_TTS_CREDENTIALS_JSON', '')),
        'credentials_path' => env('SPEECH_TTS_CREDENTIALS_PATH', env('GOOGLE_TTS_CREDENTIALS_PATH', '')),
        'verify_ssl' => env('SPEECH_TTS_VERIFY_SSL', env('GOOGLE_TTS_VERIFY_SSL', true)),
        'voice_catalog' => [],
        'audio_encoding' => env('SPEECH_TTS_AUDIO_ENCODING', env('GOOGLE_TTS_AUDIO_ENCODING', 'MP3')),
        'speaking_rate' => (float) env('SPEECH_TTS_SPEAKING_RATE', env('GOOGLE_TTS_SPEAKING_RATE', 1.0)),
        'pitch' => (float) env('SPEECH_TTS_PITCH', env('GOOGLE_TTS_PITCH', 0.0)),
        'timeout_seconds' => (int) env('SPEECH_TTS_TIMEOUT_SECONDS', env('GOOGLE_TTS_TIMEOUT_SECONDS', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini TTS (Hybrid Mode)
    |--------------------------------------------------------------------------
    |
    | Gemini 2.5 Flash Preview TTS for high-quality Arabic speech synthesis.
    | API keys are dynamically loaded from .env by GeminiKeyManager.
    | Pattern: GEMINI_TTS_API_KEY, GEMINI_TTS_API_KEY_2, GEMINI_TTS_API_KEY_3, etc.
    |
    */
    'gemini_tts' => [
        'endpoint' => env('GEMINI_TTS_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'),
        'api_key' => env('GEMINI_TTS_API_KEY', ''),
        'timeout_seconds' => (int) env('GEMINI_TTS_TIMEOUT', 60),
        'verify_ssl' => env('GEMINI_TTS_VERIFY_SSL', true),
        'default_voice' => env('GEMINI_TTS_DEFAULT_VOICE', 'Kore'),
        'cost_per_million_chars' => env('GEMINI_TTS_COST_PER_MILLION_CHARS', 0),
        // Optional HTTP(S) proxy for voice replication only, e.g. when the
        // server's country is not supported. Example: http://user:pass@host:3128
        'voice_proxy' => env('GEMINI_VOICE_PROXY', ''),
        'ffmpeg_path' => env('FFMPEG_PATH', ''),
    ],

    'stripe' => [
        'model' => App\Models\User::class,
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // NOTE: Stripe Price IDs are now automatically managed via StripeProductSyncService.
        // Prices are stored in the plans table (stripe_price_monthly, stripe_price_yearly).
        // Use `php artisan stripe:sync-plans --all` to sync plans to Stripe.
        // The .env prices below are LEGACY FALLBACK only - can be removed after migration.
        'prices' => [
            'starter' => [
                'monthly' => env('STRIPE_PRICE_STARTER_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_STARTER_YEARLY'),
            ],
            'creator' => [
                'monthly' => env('STRIPE_PRICE_CREATOR_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_CREATOR_YEARLY'),
            ],
            'pro' => [
                'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
            ],
            'studio_max' => [
                'monthly' => env('STRIPE_PRICE_STUDIO_MAX_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_STUDIO_MAX_YEARLY'),
            ],
        ],
    ],

];
