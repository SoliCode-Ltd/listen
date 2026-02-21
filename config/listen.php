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
        'require_wavenet' => (bool) env('LISTEN_REQUIRE_WAVENET', true),
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
            'guest_rate_per_minute' => (int) env('DEMO_PREVIEW_GUEST_RATE_PER_MINUTE', 3),
            'user_rate_per_minute' => (int) env('DEMO_PREVIEW_USER_RATE_PER_MINUTE', 12),
            'guest_daily_requests' => (int) env('DEMO_PREVIEW_GUEST_DAILY_REQUESTS', 12),
            'user_daily_requests' => (int) env('DEMO_PREVIEW_USER_DAILY_REQUESTS', 150),
            'guest_daily_chars' => (int) env('DEMO_PREVIEW_GUEST_DAILY_CHARS', 300),
            'user_daily_chars' => (int) env('DEMO_PREVIEW_USER_DAILY_CHARS', 50000),
        ],
        'global_preview_daily_requests' => (int) env('DEMO_GLOBAL_PREVIEW_DAILY_REQUESTS', 3000),
        'global_preview_daily_chars' => (int) env('DEMO_GLOBAL_PREVIEW_DAILY_CHARS', 500000),
    ],
];
