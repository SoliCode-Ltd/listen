<?php

/*
|--------------------------------------------------------------------------
| Admin analytics: API cost rates
|--------------------------------------------------------------------------
|
| Used by the admin dashboard to estimate what text-to-speech usage costs
| when a generation has no recorded cost (for example Gemini, which logs $0
| unless GEMINI_TTS_COST_PER_MILLION_CHARS is set). Check them against your
| Google Cloud / Gemini billing and adjust through .env when prices change.
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

    // Gemini TTS is billed per token: text in, audio out. Paid-tier list prices
    // in USD per 1M tokens; a model whose name contains 'pro' uses the pro rates.
    'gemini_tts' => [
        // Set to true while all Gemini keys are on the free tier (cost is $0).
        'free_tier' => (bool) env('ANALYTICS_GEMINI_FREE_TIER', false),
        'audio_tokens_per_second' => (float) env('ANALYTICS_GEMINI_AUDIO_TOKENS_PER_SECOND', 32),
        'chars_per_text_token' => (float) env('ANALYTICS_GEMINI_CHARS_PER_TOKEN', 4),
        'flash' => [
            'input_per_million_tokens' => (float) env('ANALYTICS_GEMINI_FLASH_INPUT_PER_M', 0.50),
            'output_per_million_tokens' => (float) env('ANALYTICS_GEMINI_FLASH_OUTPUT_PER_M', 10.00),
        ],
        'pro' => [
            'input_per_million_tokens' => (float) env('ANALYTICS_GEMINI_PRO_INPUT_PER_M', 1.00),
            'output_per_million_tokens' => (float) env('ANALYTICS_GEMINI_PRO_OUTPUT_PER_M', 20.00),
        ],
    ],

];
