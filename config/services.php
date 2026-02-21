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
        'endpoint' => env('GOOGLE_TTS_ENDPOINT', 'https://texttospeech.googleapis.com/v1/text:synthesize'),
        'api_key' => env('GOOGLE_TTS_API_KEY', ''),
        'token_uri' => env('GOOGLE_TTS_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'credentials_json' => env('GOOGLE_TTS_CREDENTIALS_JSON', ''),
        'credentials_path' => env('GOOGLE_TTS_CREDENTIALS_PATH', ''),
        'verify_ssl' => env('GOOGLE_TTS_VERIFY_SSL', true),
        'voice_ar' => env('GOOGLE_TTS_VOICE_AR', 'ar-XA-Wavenet-A'),
        'voice_en' => env('GOOGLE_TTS_VOICE_EN', 'en-US-Wavenet-F'),
        'voice_catalog' => [
            'ar' => [
                ['key' => 'dai', 'name' => 'ar-XA-Wavenet-A', 'label' => 'Dai', 'gender' => 'female', 'avatar' => 'female-a', 'recommended' => true],
                ['key' => 'rami', 'name' => 'ar-XA-Wavenet-B', 'label' => 'Rami', 'gender' => 'male', 'avatar' => 'male-a', 'recommended' => false],
                ['key' => 'maha', 'name' => 'ar-XA-Wavenet-D', 'label' => 'Maha', 'gender' => 'female', 'avatar' => 'female-b', 'recommended' => false],
                ['key' => 'kareem', 'name' => 'ar-XA-Wavenet-C', 'label' => 'Kareem', 'gender' => 'male', 'avatar' => 'male-b', 'recommended' => false],
            ],
            'en' => [
                ['key' => 'ember', 'name' => 'en-US-Wavenet-F', 'label' => 'Ember', 'gender' => 'female', 'avatar' => 'female-c', 'recommended' => true],
                ['key' => 'cade', 'name' => 'en-US-Wavenet-D', 'label' => 'Cade', 'gender' => 'male', 'avatar' => 'male-c', 'recommended' => true],
                ['key' => 'vexa', 'name' => 'en-US-Wavenet-H', 'label' => 'Vexa', 'gender' => 'female', 'avatar' => 'female-d', 'recommended' => false],
                ['key' => 'lyric', 'name' => 'en-US-Wavenet-C', 'label' => 'Lyric', 'gender' => 'female', 'avatar' => 'female-e', 'recommended' => false],
                ['key' => 'orbit', 'name' => 'en-US-Wavenet-I', 'label' => 'Orbit', 'gender' => 'male', 'avatar' => 'male-d', 'recommended' => false],
                ['key' => 'quill', 'name' => 'en-US-Wavenet-J', 'label' => 'Quill', 'gender' => 'male', 'avatar' => 'male-e', 'recommended' => false],
            ],
        ],
        'audio_encoding' => env('GOOGLE_TTS_AUDIO_ENCODING', 'MP3'),
        'speaking_rate' => (float) env('GOOGLE_TTS_SPEAKING_RATE', 1.0),
        'pitch' => (float) env('GOOGLE_TTS_PITCH', 0.0),
        'timeout_seconds' => (int) env('GOOGLE_TTS_TIMEOUT_SECONDS', 30),
    ],

];
