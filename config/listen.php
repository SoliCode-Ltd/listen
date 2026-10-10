<?php

return [
    'audio' => [
        // `sync` runs generation inside the request for the fastest UX.
        // `queue` keeps async behavior and requires a running queue worker.
        'processing' => (string) env('LISTEN_AUDIO_PROCESSING', 'sync'),
        // When sync mode is enabled, requests above this char count are routed async.
        'force_async_chars' => (int) env('LISTEN_AUDIO_FORCE_ASYNC_CHARS', 2500),
    ],

    'voice' => [
        'default_tier' => env('LISTEN_DEFAULT_VOICE_TIER', 'standard'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TTS Architecture (Hybrid Mode)
    |--------------------------------------------------------------------------
    |
    | mode: 'google' (Google Cloud WaveNet & Chirp) or 'hybrid' (Free=WaveNet, Paid=Gemini)
    | fallback_enabled: If Gemini fails, gracefully fall back to Google Cloud
    | telemetry_channel: Log channel for TTS telemetry
    |
    */
    'tts' => [
        'mode' => env('LISTEN_TTS_MODE', 'google'),
        'fallback_enabled' => (bool) env('LISTEN_TTS_FALLBACK_ENABLED', true),
        'telemetry_channel' => env('LISTEN_TTS_TELEMETRY_CHANNEL', 'stack'),
        'gemini_timeout_seconds' => (int) env('LISTEN_GEMINI_TIMEOUT_SECONDS', 60),
    ],

    'billing' => [
        'subscription_sync_ttl_minutes' => (int) env('LISTEN_SUBSCRIPTION_SYNC_TTL_MINUTES', 10),
        'grace_days' => (int) env('LISTEN_BILLING_GRACE_DAYS', 7),
        'webhook_email_enabled' => (bool) env('LISTEN_BILLING_WEBHOOK_EMAILS', true),
        'pre_renewal_reminder_days' => [
            7,
            1,
        ],
        'creator_trial' => [
            'enabled' => true,
            'days' => 7,
            'plan_slug' => 'creator',
            'quota_plan_slug' => 'starter',
        ],
    ],

    /*
    | When a member is blocked:
    | - block_cancels_trial: cancel their unpaid free trial in Stripe so they
    |   are not charged for an account they cannot use. OFF unless set, because
    |   staging runs the live Stripe keys on a copy of live members: set
    |   LISTEN_BLOCK_CANCELS_TRIAL=true on live only.
    | - block_evasion: an account made on the same browser after another
    |   account there was blocked is blocked too.
    */
    'abuse' => [
        'block_cancels_trial' => (bool) env('LISTEN_BLOCK_CANCELS_TRIAL', false),
        'block_evasion' => (bool) env('LISTEN_BLOCK_EVASION', true),
    ],

    'demo' => [
        'guest_char_allowance' => (int) env('DEMO_GUEST_CHAR_ALLOWANCE', 300),
        'user_char_allowance' => (int) env('DEMO_USER_CHAR_ALLOWANCE', 30000),
        'check' => [
            'guest_rate_per_minute' => (int) env('DEMO_CHECK_GUEST_RATE_PER_MINUTE', 30),
            'user_rate_per_minute' => (int) env('DEMO_CHECK_USER_RATE_PER_MINUTE', 120),
            'guest_daily_requests' => (int) env('DEMO_CHECK_GUEST_DAILY_REQUESTS', 200),
            'user_daily_requests' => (int) env('DEMO_CHECK_USER_DAILY_REQUESTS', 1000),
            'guest_daily_chars' => (int) env('DEMO_CHECK_GUEST_DAILY_CHARS', 300),
            'user_daily_chars' => (int) env('DEMO_CHECK_USER_DAILY_CHARS', 200000),
        ],
        'preview' => [
            'guest_rate_per_minute' => (int) env('DEMO_PREVIEW_GUEST_RATE_PER_MINUTE', 30),
            // Each preview is a real speech request; a person needs a few a minute at most.
            'user_rate_per_minute' => (int) env('DEMO_PREVIEW_USER_RATE_PER_MINUTE', 10),
            'guest_daily_requests' => (int) env('DEMO_PREVIEW_GUEST_DAILY_REQUESTS', 12),
            'user_daily_requests' => (int) env('DEMO_PREVIEW_USER_DAILY_REQUESTS', 150),
            'guest_daily_chars' => (int) env('DEMO_PREVIEW_GUEST_DAILY_CHARS', 300),
            'user_daily_chars' => (int) env('DEMO_PREVIEW_USER_DAILY_CHARS', 50000),
        ],
        'global_preview_daily_requests' => (int) env('DEMO_GLOBAL_PREVIEW_DAILY_REQUESTS', 3000),
        'global_preview_daily_chars' => (int) env('DEMO_GLOBAL_PREVIEW_DAILY_CHARS', 500000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Guardrails
    |--------------------------------------------------------------------------
    |
    | Admin-configurable limits to prevent unexpected cost spikes.
    | These are checked in addition to per-plan limits.
    |
    */
    'cost_guardrails' => [
        'daily_gemini_char_limit' => (int) env('LISTEN_DAILY_GEMINI_CHAR_LIMIT', 5_000_000),
        'daily_total_char_limit' => (int) env('LISTEN_DAILY_TOTAL_CHAR_LIMIT', 20_000_000),
        'daily_gemini_request_limit' => (int) env('LISTEN_DAILY_GEMINI_REQUEST_LIMIT', 10_000),
    ],
];
