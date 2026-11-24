<?php

/*
|--------------------------------------------------------------------------
| Admin analytics: API cost rates
|--------------------------------------------------------------------------
|
| Gemini calls are priced from these list prices and the token counts in each
| reply (App\Support\GeminiSpend), and the admin dashboard uses them to
| estimate generations made before that. Google Cloud voices are priced per
| character. Update them when Google's prices change.
|
*/

return [

    // Google Cloud Text-to-Speech: USD per 1M characters, matched against the
    // voice name (first match wins). Anything unmatched uses 'default'.
    'google_cloud_tts' => [
        'per_million_chars' => [
            'chirp3-hd' => (float) env('ANALYTICS_GOOGLE_CHIRP3_HD_PER_M', 30),
            'chirp' => (float) env('ANALYTICS_GOOGLE_CHIRP_PER_M', 30),
            'studio' => (float) env('ANALYTICS_GOOGLE_STUDIO_PER_M', 160),
            'neural2' => (float) env('ANALYTICS_GOOGLE_NEURAL2_PER_M', 16),
            'wavenet' => (float) env('ANALYTICS_GOOGLE_WAVENET_PER_M', 16),
            'polyglot' => (float) env('ANALYTICS_GOOGLE_POLYGLOT_PER_M', 16),
            'standard' => (float) env('ANALYTICS_GOOGLE_STANDARD_PER_M', 4),
        ],
        'default_per_million_chars' => (float) env('ANALYTICS_GOOGLE_DEFAULT_PER_M', 4),
    ],

    // Gemini is billed per token. Since token metering (App\Support\GeminiSpend) each
    // voice's cost is worked out from the token counts Google sends back with every
    // reply; these settings estimate the voices made before that.
    'gemini_tts' => [
        // Set to true while all Gemini keys are on the free tier (cost is $0).
        'free_tier' => (bool) env('ANALYTICS_GEMINI_FREE_TIER', false),
        // "Audio tokens correspond to 25 tokens per second of audio" (Gemini API pricing page).
        'audio_tokens_per_second' => (float) env('ANALYTICS_GEMINI_AUDIO_TOKENS_PER_SECOND', 25),
        'chars_per_text_token' => (float) env('ANALYTICS_GEMINI_CHARS_PER_TOKEN', 4),
    ],

    /*
    | Gemini API paid-tier list prices, USD per 1M tokens, from
    | https://ai.google.dev/gemini-api/docs/pricing (read 2026-11-01). Each model
    | lists its prices from the date they start ('from', a day in Google's
    | billing time, America/Los_Angeles); the latest one on or before the day of
    | the call applies. 'output' includes thinking tokens; 'cached' is the price
    | of the cached part of a prompt (the full input price when not listed).
    | Model names are matched without "-preview" and dates, so
    | gemini-2.5-flash-preview-tts uses gemini-2.5-flash-tts.
    */
    'gemini_prices' => [
        'gemini-3.8-flash-tts' => [
            ['from' => null, 'input' => 0.50, 'output' => 9.00],
            ['from' => '2027-01-01', 'input' => 1.00, 'output' => 18.00],
        ],
        'gemini-3.8-flash-lite-tts' => [
            ['from' => null, 'input' => 0.50, 'output' => 6.00],
            ['from' => '2027-01-01', 'input' => 1.00, 'output' => 12.00],
        ],
        'gemini-3.1-flash-tts' => [
            ['from' => null, 'input' => 1.00, 'output' => 20.00],
        ],
        'gemini-2.5-flash-tts' => [
            ['from' => null, 'input' => 0.50, 'output' => 10.00],
        ],
        'gemini-2.5-pro-tts' => [
            ['from' => null, 'input' => 1.00, 'output' => 20.00],
        ],
        // The dialect rewrite and translation (VoiceCloningService::TRANSCRIPT_MODELS).
        'gemini-3.6-flash' => [
            ['from' => null, 'input' => 0.75, 'cached' => 0.075, 'output' => 3.75],
            ['from' => '2027-01-01', 'input' => 1.50, 'cached' => 0.15, 'output' => 7.50],
        ],
        // The automatic pronunciation step (App\Support\Tashkeel\AutoReadings): three short requests for a text.
        // Output includes thinking tokens. From the pricing page, read 2026-11-24.
        'gemini-3.8-flash' => [
            ['from' => null, 'input' => 0.75, 'cached' => 0.075, 'output' => 3.75],
            ['from' => '2027-01-01', 'input' => 1.50, 'cached' => 0.15, 'output' => 7.50],
        ],
        // No longer on Google's pricing page: the app's own figure for it (as for dubbing,
        // listen.dubbing.prices). Check it against the Cloud Billing report and set it here.
        'gemini-3.5-flash' => [
            ['from' => null,
                'input' => (float) env('ANALYTICS_GEMINI_35_FLASH_INPUT_PER_M', 0.50),
                'cached' => (float) env('ANALYTICS_GEMINI_35_FLASH_CACHED_PER_M', 0.05),
                'output' => (float) env('ANALYTICS_GEMINI_35_FLASH_OUTPUT_PER_M', 3.00)],
        ],
    ],

];
