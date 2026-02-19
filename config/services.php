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
                ['key' => 'soli_noor', 'name' => 'ar-XA-Wavenet-A', 'label' => 'Soli Noor', 'gender' => 'female', 'avatar' => '🟣', 'recommended' => true],
                ['key' => 'soli_fares', 'name' => 'ar-XA-Wavenet-B', 'label' => 'Soli Fares', 'gender' => 'male', 'avatar' => '🟠', 'recommended' => false],
                ['key' => 'soli_zaid', 'name' => 'ar-XA-Wavenet-C', 'label' => 'Soli Zaid', 'gender' => 'male', 'avatar' => '🔷', 'recommended' => true],
                ['key' => 'soli_layla', 'name' => 'ar-XA-Wavenet-D', 'label' => 'Soli Layla', 'gender' => 'female', 'avatar' => '✨', 'recommended' => true],
            ],
            'en' => [
                ['key' => 'soli_atlas', 'name' => 'en-US-Wavenet-A', 'label' => 'Soli Atlas', 'gender' => 'male', 'avatar' => '🛡️', 'recommended' => false],
                ['key' => 'soli_orion', 'name' => 'en-US-Wavenet-B', 'label' => 'Soli Orion', 'gender' => 'male', 'avatar' => '🌌', 'recommended' => true],
                ['key' => 'soli_iris', 'name' => 'en-US-Wavenet-C', 'label' => 'Soli Iris', 'gender' => 'female', 'avatar' => '🌸', 'recommended' => false],
                ['key' => 'soli_nova', 'name' => 'en-US-Wavenet-D', 'label' => 'Soli Nova', 'gender' => 'male', 'avatar' => '🚀', 'recommended' => true],
                ['key' => 'soli_luna', 'name' => 'en-US-Wavenet-E', 'label' => 'Soli Luna', 'gender' => 'female', 'avatar' => '🌙', 'recommended' => true],
                ['key' => 'soli_clara', 'name' => 'en-US-Wavenet-F', 'label' => 'Soli Clara', 'gender' => 'female', 'avatar' => '💎', 'recommended' => true],
                ['key' => 'soli_aria', 'name' => 'en-US-Wavenet-G', 'label' => 'Soli Aria', 'gender' => 'female', 'avatar' => '🎵', 'recommended' => false],
                ['key' => 'soli_violet', 'name' => 'en-US-Wavenet-H', 'label' => 'Soli Violet', 'gender' => 'female', 'avatar' => '🪻', 'recommended' => false],
                ['key' => 'soli_miles', 'name' => 'en-US-Wavenet-I', 'label' => 'Soli Miles', 'gender' => 'male', 'avatar' => '⚡', 'recommended' => false],
                ['key' => 'soli_leon', 'name' => 'en-US-Wavenet-J', 'label' => 'Soli Leon', 'gender' => 'male', 'avatar' => '🦁', 'recommended' => false],
            ],
        ],
        'audio_encoding' => env('GOOGLE_TTS_AUDIO_ENCODING', 'MP3'),
        'speaking_rate' => (float) env('GOOGLE_TTS_SPEAKING_RATE', 1.0),
        'pitch' => (float) env('GOOGLE_TTS_PITCH', 0.0),
        'timeout_seconds' => (int) env('GOOGLE_TTS_TIMEOUT_SECONDS', 30),
    ],

];
