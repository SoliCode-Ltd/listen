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
        'voice_catalog' => [
            'ar' => [
                ['name' => 'ar-XA-Wavenet-A', 'label' => 'Alya', 'gender' => 'female'],
                ['name' => 'ar-XA-Wavenet-B', 'label' => 'Faris', 'gender' => 'male'],
                ['name' => 'ar-XA-Wavenet-C', 'label' => 'Rami', 'gender' => 'male'],
                ['name' => 'ar-XA-Wavenet-D', 'label' => 'Lina', 'gender' => 'female'],
            ],
            'en' => [
                ['name' => 'en-US-Wavenet-A', 'label' => 'Noah', 'gender' => 'male'],
                ['name' => 'en-US-Wavenet-B', 'label' => 'Liam', 'gender' => 'male'],
                ['name' => 'en-US-Wavenet-C', 'label' => 'Emma', 'gender' => 'female'],
                ['name' => 'en-US-Wavenet-D', 'label' => 'James', 'gender' => 'male'],
                ['name' => 'en-US-Wavenet-E', 'label' => 'Olivia', 'gender' => 'female'],
                ['name' => 'en-US-Wavenet-F', 'label' => 'Sophia', 'gender' => 'female'],
                ['name' => 'en-US-Wavenet-G', 'label' => 'Ava', 'gender' => 'female'],
                ['name' => 'en-US-Wavenet-H', 'label' => 'Mia', 'gender' => 'female'],
                ['name' => 'en-US-Wavenet-I', 'label' => 'Ethan', 'gender' => 'male'],
                ['name' => 'en-US-Wavenet-J', 'label' => 'Lucas', 'gender' => 'male'],
            ],
        ],
        'audio_encoding' => env('GOOGLE_TTS_AUDIO_ENCODING', 'MP3'),
        'speaking_rate' => (float) env('GOOGLE_TTS_SPEAKING_RATE', 1.0),
        'pitch' => (float) env('GOOGLE_TTS_PITCH', 0.0),
        'timeout_seconds' => (int) env('GOOGLE_TTS_TIMEOUT_SECONDS', 30),
    ],

];
