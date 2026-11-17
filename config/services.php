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

    // The Listen app's notifications (App\Services\Push): the Firebase project's service
    // account, as JSON (or base64 of it) or as a file's path. Empty: nothing is sent.
    'fcm' => [
        'credentials_json' => env('FCM_CREDENTIALS_JSON', ''),
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
        // A voice ready sooner than this is not announced: the member is still looking at it.
        'voice_min_seconds' => (int) env('FCM_VOICE_MIN_SECONDS', 20),
    ],

    // The Listen Android app (mobile/): its package and the SHA-256 fingerprints of the keys
    // that sign it (comma separated), served at /.well-known/assetlinks.json so that links to
    // the site open in the app.
    'android_app' => [
        'package' => env('ANDROID_APP_PACKAGE', 'studio.listenai.app'),
        'cert_fingerprints' => env('ANDROID_APP_CERT_SHA256', ''),
        // The app's page on Google Play, where the footer's badge leads.
        'store_url' => env('ANDROID_APP_STORE_URL', 'https://play.google.com/store/apps/details?id=studio.listenai.app'),
        // "hidden" (the default): the app shows nothing about plans, as Google Play's payment
        // rules ask. "web": plans show in the app and are bought in the phone's browser
        // (App\Support\InApp\InApp::plansMode).
        'plans' => env('ANDROID_APP_PLANS', 'hidden'),
    ],

    // The Listen iPhone app (mobile/ios). Plans are always hidden there (no setting, on
    // purpose: App\Support\InApp\InApp::plansMode).
    'ios_app' => [
        // "Continue with Google" and "Sign in with Apple" in the iPhone app. Apple asks for its
        // own sign-in beside any other company's, so the two are offered together or not at
        // all; off, the iPhone app signs in with email (mobile/ios/APPLE-RULES.md, 1.1).
        'social_sign_in' => (bool) env('IOS_APP_SOCIAL_SIGN_IN', true),
    ],

    // "Sign in with Apple" in the iPhone app (App\Services\Auth\AppleSignIn). Checking a
    // sign-in needs only the app's id. The key (from the Apple developer account, with "Sign
    // in with Apple" ticked: the notifications key can be the same one) lets the site tell
    // Apple to forget a member's link when they delete their account.
    'apple_sign_in' => [
        'client_id' => env('IOS_APP_BUNDLE_ID', 'studio.listenai.app'),
        'team_id' => env('APPLE_SIGNIN_TEAM_ID', env('APNS_TEAM_ID', '')),
        'key_id' => env('APPLE_SIGNIN_KEY_ID', env('APNS_KEY_ID', '')),
        'private_key' => env('APPLE_SIGNIN_PRIVATE_KEY', env('APNS_PRIVATE_KEY', '')),
        'private_key_path' => env('APPLE_SIGNIN_PRIVATE_KEY_PATH', env('APNS_PRIVATE_KEY_PATH', '')),
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
        // What the standard voices are asked for. LINEAR16 (uncompressed) is compressed
        // once here into the MP3 members get; MP3 is stored as the engine sends it.
        'audio_encoding' => env('SPEECH_TTS_AUDIO_ENCODING', env('GOOGLE_TTS_AUDIO_ENCODING', 'LINEAR16')),
        'speaking_rate' => (float) env('SPEECH_TTS_SPEAKING_RATE', env('GOOGLE_TTS_SPEAKING_RATE', 1.0)),
        'pitch' => (float) env('SPEECH_TTS_PITCH', env('GOOGLE_TTS_PITCH', 0.0)),
        'timeout_seconds' => (int) env('SPEECH_TTS_TIMEOUT_SECONDS', env('GOOGLE_TTS_TIMEOUT_SECONDS', 30)),
        // Pieces of one long text sent to Google at the same time.
        'max_parallel_requests' => (int) env('SPEECH_TTS_MAX_PARALLEL_REQUESTS', 4),
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
        'api_key' => env('GEMINI_TTS_API_KEY', ''),
        // Rewrite an Arabic script into the chosen dialect before a stock voice reads it.
        'dialect_rewrite' => (bool) env('GEMINI_TTS_DIALECT_REWRITE', true),
        'timeout_seconds' => (int) env('GEMINI_TTS_TIMEOUT', 60),
        'verify_ssl' => env('GEMINI_TTS_VERIFY_SSL', true),
        'default_voice' => env('GEMINI_TTS_DEFAULT_VOICE', 'Kore'),
        'cost_per_million_chars' => env('GEMINI_TTS_COST_PER_MILLION_CHARS', 0),
        // Per-key budgets tracked locally by GeminiKeyManager. Set them to the
        // limits of your Gemini tier; the defaults match the free tier.
        'rpm_limit' => (int) env('GEMINI_TTS_RPM_LIMIT', 10),
        'tpm_limit' => (int) env('GEMINI_TTS_TPM_LIMIT', 10000),
        'rpd_limit' => (int) env('GEMINI_TTS_RPD_LIMIT', 100),
        // Extra attempts after a 5xx or a reply without audio, before falling back.
        'transient_retries' => (int) env('GEMINI_TTS_TRANSIENT_RETRIES', 2),
        'retry_pause_ms' => (int) env('GEMINI_TTS_RETRY_PAUSE_MS', 600),
        // No retry once this many seconds have gone, so slow failures fall back at once.
        'retry_budget_seconds' => (int) env('GEMINI_TTS_RETRY_BUDGET_SECONDS', 20),
        // Longer single-voice texts are read in pieces sent at the same time.
        'max_chars_per_request' => (int) env('GEMINI_TTS_MAX_CHARS_PER_REQUEST', 900),
        'max_parallel_requests' => (int) env('GEMINI_TTS_MAX_PARALLEL_REQUESTS', 4),
        // At most this many pieces of a long text at the same time, over all the keys (4 on each).
        'max_parallel_total' => (int) env('GEMINI_TTS_MAX_PARALLEL_TOTAL', 12),
        // Each piece of a long text is checked before it is joined, and one that
        // stops early, repeats itself, stalls or strays from the voice's pace or
        // pitch is taken once more (App\Support\PieceCheck).
        'check_pieces' => (bool) env('GEMINI_TTS_CHECK_PIECES', true),

        // Dialogue pieces: a longer dialogue is made in pieces split at speaker
        // turns, about 1.5 to 2 minutes of conversation each, well under the 2,400
        // characters one dialogue request used to be allowed (the longest of those
        // came back without audio). The pieces are joined like a single voice's.
        'max_dialogue_chars_per_request' => (int) env('GEMINI_TTS_MAX_DIALOGUE_CHARS_PER_REQUEST', 1500),

        // A child speaker is a light grown-up voice directed to sound like a child,
        // and over a long piece it drifts back to its own age. A child is read in
        // shorter pieces (a dialogue with a child too), each with the child's
        // direction, and a piece pitched below a child's voice (Hz) is taken again.
        'child_max_chars_per_request' => (int) env('GEMINI_TTS_CHILD_MAX_CHARS_PER_REQUEST', 300),
        'child_max_dialogue_chars_per_request' => (int) env('GEMINI_TTS_CHILD_MAX_DIALOGUE_CHARS_PER_REQUEST', 600),
        'child_min_pitch' => (float) env('GEMINI_TTS_CHILD_MIN_PITCH', 220),

        // Optional HTTP(S) proxy for voice replication only, e.g. when the
        // server's country is not supported. Example: http://user:pass@host:3128
        'voice_proxy' => env('GEMINI_VOICE_PROXY', ''),
        // Empty: found by itself. "none": run as a server without ffmpeg.
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
