<?php

return [
    'audio' => [
        // `sync` runs generation inside the request for the fastest UX.
        // `queue` keeps async behavior and requires a running queue worker.
        'processing' => (string) env('LISTEN_AUDIO_PROCESSING', 'sync'),
        // In sync mode, texts at least this long are made after the reply, in the same
        // process (no worker needed), and the studio and the extension check on them. 0: never.
        'force_async_chars' => (int) env('LISTEN_AUDIO_FORCE_ASYNC_CHARS', 2500),
        // Seconds ffmpeg may take to package a take, plus a tenth of the take's length
        // (an hour of audio may take 6 minutes before it is encoded as it came instead).
        'encode_timeout_seconds' => (int) env('LISTEN_AUDIO_ENCODE_TIMEOUT', 30),
    ],

    'voice' => [
        'default_tier' => env('LISTEN_DEFAULT_VOICE_TIER', 'standard'),
    ],

    /*
    | What this server may do to real customers by itself: cancel or change
    | Stripe subscriptions and prices, and send reminder or welcome emails from
    | scheduled jobs (App\Support\LiveServer). Staging runs the live Stripe keys
    | on a copy of live members, so it must never do these. Unset: on only when
    | APP_URL is the live site (live_host). LISTEN_LIVE_ACTIONS=true or false
    | decides it outright.
    */
    'live_actions' => env('LISTEN_LIVE_ACTIONS'),
    'live_host' => (string) env('LISTEN_LIVE_HOST', 'listenai.studio'),

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
        // A plan's daily generation limit resets at midnight in this time zone (most members are in Egypt).
        'daily_limit_timezone' => (string) env('LISTEN_DAILY_LIMIT_TIMEZONE', 'Africa/Cairo'),
        'subscription_sync_ttl_minutes' => (int) env('LISTEN_SUBSCRIPTION_SYNC_TTL_MINUTES', 10),
        'grace_days' => (int) env('LISTEN_BILLING_GRACE_DAYS', 7),
        'webhook_email_enabled' => (bool) env('LISTEN_BILLING_WEBHOOK_EMAILS', true),
        'pre_renewal_reminder_days' => [
            7,
            1,
        ],
        // May this server cancel free trials in Stripe (the admin "Cancel trial"
        // button, and cancelling a blocked member's trial)? OFF unless set,
        // because staging runs the live Stripe keys on a copy of live members:
        // set LISTEN_STRIPE_TRIAL_ACTIONS=true on live only.
        'stripe_trial_actions' => (bool) env('LISTEN_STRIPE_TRIAL_ACTIONS', false),
        'creator_trial' => [
            'enabled' => true,
            'days' => 7,
            'plan_slug' => 'creator',
            'quota_plan_slug' => 'starter',
        ],
    ],

    /*
    | block_evasion: an account made on the same browser after another account
    | there was blocked is blocked too.
    */
    'abuse' => [
        'block_evasion' => (bool) env('LISTEN_BLOCK_EVASION', true),
    ],

    'demo' => [
        // A free allowance (the demo, the free plan's credits, a gift) waits for a
        // confirmed email (App\Support\EmailVerification). false stops waiting,
        // for when the site's email cannot be delivered.
        'require_verified_email' => (bool) env('LISTEN_REQUIRE_VERIFIED_EMAIL', true),
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

    /*
    |--------------------------------------------------------------------------
    | Developer API (/v1)
    |--------------------------------------------------------------------------
    |
    | Limits for the public API when a plan sets none of its own (Plans →
    | Developer API in the admin). Keyed by the plan's processing priority.
    | Requests a minute per key; generations running at once per member.
    |
    */
    'api' => [
        'requests_per_minute' => [
            'standard' => 60,
            'standard_plus' => 60,
            'high' => 120,
            'priority' => 180,
            'highest' => 300,
        ],
        'concurrency' => [
            'standard' => 2,
            'standard_plus' => 3,
            'high' => 5,
            'priority' => 8,
            'highest' => 10,
        ],
        // How long one synchronous generation may hold a concurrency slot.
        'slot_seconds' => (int) env('LISTEN_API_SLOT_SECONDS', 300),
        // Generations that wait for the file, at once, for the whole site: each one
        // holds a web worker, so the studio and the rest of the site keep the others.
        'site_slots' => (int) env('LISTEN_API_SITE_SLOTS', 8),
        // Share of the site's daily speech ceiling (cost_guardrails above) the API
        // may use, so the studio and the extension always keep the rest. 0 or 1: no share.
        'daily_share' => (float) env('LISTEN_API_DAILY_SHARE', 0.6),
        // Minutes after which a generation still queued or running is failed and its
        // credits given back (api:expire-stuck-generations). Well above the longest a
        // job may take with its retries (3 tries of 150 s, 180 s apart).
        'stuck_processing_minutes' => (int) env('LISTEN_API_STUCK_PROCESSING_MINUTES', 30),
        'stuck_queued_minutes' => (int) env('LISTEN_API_STUCK_QUEUED_MINUTES', 60),
    ],

    'dubbing' => [
        // "Dub my video" (App\Http\Controllers\VideoDubController).
        'min_seconds' => (int) env('LISTEN_DUB_MIN_SECONDS', 3),
        'max_seconds' => (int) env('LISTEN_DUB_MAX_SECONDS', 60),
        'max_upload_mb' => (int) env('LISTEN_DUB_MAX_UPLOAD_MB', 100),
        // Hours the videos are kept before their files are deleted (dubs:prune).
        'keep_hours' => (int) env('LISTEN_DUB_KEEP_HOURS', 48),
        // Free marked previews for the whole site a day, so a rush of sign-ups cannot run up the bill.
        'free_per_day' => (int) env('LISTEN_DUB_FREE_PER_DAY', 50),
        // Minutes after which a dub still working is failed and its credits given back.
        'stuck_minutes' => (int) env('LISTEN_DUB_STUCK_MINUTES', 20),
        // What a dub costs us, for the admin's figures only (US dollars a million tokens).
        // The speech engine bills its voice by the second, about 25 tokens each.
        'prices' => [
            'text_in' => (float) env('LISTEN_DUB_PRICE_TEXT_IN', 0.5),
            'audio_in' => (float) env('LISTEN_DUB_PRICE_AUDIO_IN', 1.0),
            'text_out' => (float) env('LISTEN_DUB_PRICE_TEXT_OUT', 3.0),
            'voice_out' => (float) env('LISTEN_DUB_PRICE_VOICE_OUT', 9.0),
            'voice_tokens_per_second' => (int) env('LISTEN_DUB_VOICE_TOKENS_PER_SECOND', 25),
        ],
    ],
];
