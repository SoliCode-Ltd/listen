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

    'google_tts' => [
        'endpoint' => env('GOOGLE_TTS_ENDPOINT', 'https://texttospeech.googleapis.com/v1/text:synthesize'),
        'api_key' => env('GOOGLE_TTS_API_KEY', ''),
        'token_uri' => env('GOOGLE_TTS_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'credentials_json' => env('GOOGLE_TTS_CREDENTIALS_JSON', ''),
        'credentials_path' => env('GOOGLE_TTS_CREDENTIALS_PATH', ''),
        'voice_ar' => env('GOOGLE_TTS_VOICE_AR', 'ar-XA-Wavenet-D'),
        'voice_en' => env('GOOGLE_TTS_VOICE_EN', 'en-US-Wavenet-D'),
        'audio_encoding' => env('GOOGLE_TTS_AUDIO_ENCODING', 'MP3'),
        'speaking_rate' => (float) env('GOOGLE_TTS_SPEAKING_RATE', 1.0),
        'pitch' => (float) env('GOOGLE_TTS_PITCH', 0.0),
        'timeout_seconds' => (int) env('GOOGLE_TTS_TIMEOUT_SECONDS', 30),
    ],

];
