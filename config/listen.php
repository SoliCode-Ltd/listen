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

    'mcp' => [
        // The MCP server's text_to_speech (routes/ai.php) makes speech on the queue and
        // waits this long for it before answering with its history item instead: well
        // inside the minute AI apps usually give a tool.
        'wait_seconds' => (int) env('LISTEN_MCP_WAIT_SECONDS', 40),
        // Such waits at once, for the whole site: each holds a web worker. When all are
        // in use, the tool answers at once with the history item to check later.
        'waiting_slots' => (int) env('LISTEN_MCP_WAITING_SLOTS', 4),
    ],

    'dubbing' => [
        // "Dub my video" (App\Http\Controllers\VideoDubController).
        'min_seconds' => (int) env('LISTEN_DUB_MIN_SECONDS', 3),
        // The longest free video, and a plan's when it sets none (the admin can change it in Video Dubbing → Settings).
        'max_seconds' => (int) env('LISTEN_DUB_MAX_SECONDS', 60),
        // The admin can change it (Video Dubbing → Settings); the server's own PHP limits still apply.
        'max_upload_mb' => (int) env('LISTEN_DUB_MAX_UPLOAD_MB', 300),
        // Hours a free dub stays on the member's dashboard before its files are deleted (Video Dubbing → Settings).
        // A plan's dubs stay as long as the plan keeps voices, in Google Drive once it is connected (dubs:prune).
        'free_keep_hours' => (int) env('LISTEN_DUB_FREE_KEEP_HOURS', 24),
        // Taking the old voices out (App\Services\Dubbing\VoiceSeparator): this server's Python 3 when it is
        // not in the usual places, the processor threads one separation uses, and where it is set up.
        'separator' => [
            'python' => env('LISTEN_DUB_SEPARATOR_PYTHON', ''),
            // 0: all but one of the server's cores, four at most.
            'threads' => (int) env('LISTEN_DUB_SEPARATOR_THREADS', 0),
            // It takes about 1.4 GB while it runs: it starts only with this much free, else the dub uses the filters.
            'min_free_mb' => (int) env('LISTEN_DUB_SEPARATOR_MIN_FREE_MB', 2048),
            'dir' => env('LISTEN_DUB_SEPARATOR_DIR', ''),
        ],
        // Lines written phrase by phrase on the phrases found on the sound (VideoDubber). Off: on a real video the
        // text model put the words one or two phrases early (it hears two short phrases as one), up to 5 s off the lips;
        // the model times its own sentences, which are then set on the phrases' starts and ends.
        'phrase_lines' => (bool) env('LISTEN_DUB_PHRASE_LINES', false),
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

    /*
    | Speech to text ("Transcribe", App\Services\Transcription\Transcriber).
    | What each plan includes is set on the plan (Admin → Plans → Transcribe).
    | These are the site's own limits and Listen's cost figures until they are
    | saved in Admin → Transcribe → Settings (TranscriptionSettings).
    */
    'transcription' => [
        // What a minute of audio costs Listen, as measured on staging on 16 November 2026
        // (transcribe:measure): plain text, and with timings or speaker names (0.0041-0.0044 with the dialect guide, measured
        // on the 25 Arabic country files). For the admin's figures only.
        'cost_per_minute' => [
            'plain' => (float) env('LISTEN_TRANSCRIBE_COST_PLAIN', 0.0022),
            'timed' => (float) env('LISTEN_TRANSCRIBE_COST_TIMED', 0.0042),
        ],
        // The free try in the demo: one recording up to this long, and this many for the whole site a day.
        'free_try_seconds' => (int) env('LISTEN_TRANSCRIBE_FREE_SECONDS', 60),
        'free_per_day' => (int) env('LISTEN_TRANSCRIBE_FREE_PER_DAY', 50),
        // Minutes of audio the whole site may transcribe in a day (members, API and MCP together). 0: no ceiling.
        'daily_minutes' => (int) env('LISTEN_TRANSCRIBE_DAILY_MINUTES', 3000),
        // The largest upload, and whether each part is heard a second time with its draft (Transcribe → Settings).
        'max_upload_mb' => (int) env('LISTEN_TRANSCRIBE_MAX_UPLOAD_MB', 500),
        'review' => (bool) env('LISTEN_TRANSCRIBE_REVIEW', false),
        // Days the uploaded audio is kept (the text stays until the member deletes it).
        'keep_audio_days' => (int) env('LISTEN_TRANSCRIBE_KEEP_AUDIO_DAYS', 7),
        // Minutes after which a job still working is failed and its minutes given back.
        'stuck_minutes' => (int) env('LISTEN_TRANSCRIBE_STUCK_MINUTES', 30),
    ],

    /*
    | Listen Agents: voice agents that answer a business's customers on its
    | website, its link page or a QR code (App\Support\Agents).
    |
    | A call minute uses credits_per_minute from the member's plan, from the
    | same credits as audio (900 = one audio minute; 8,100 = nine, so a call
    | keeps 40% on the plan whose credits cost least, its yearly price too;
    | an agent with a great deal to know uses more on a long call,
    | App\Support\Agents\AgentCost::rateFor). The
    | owner's first test calls are free up to free_test_seconds. Calls end on
    | their own at max_call_seconds (a plan or the owner can set less). Admin →
    | Agents → Settings overrides the rate and the free seconds; which plans
    | include agents, and their limits, are set on each plan.
    */
    'agents' => [
        'credits_per_minute' => (int) env('LISTEN_AGENT_CREDITS_PER_MINUTE', 8100),
        'free_test_seconds' => (int) env('LISTEN_AGENT_FREE_TEST_SECONDS', 120),
        'max_call_seconds' => (int) env('LISTEN_AGENT_MAX_CALL_SECONDS', 600),
        'max_agents' => (int) env('LISTEN_AGENT_MAX_AGENTS', 10),
        // Calls at the same time across all of a member's agents, when their plan sets none.
        'concurrent_calls' => (int) env('LISTEN_AGENT_CONCURRENT_CALLS', 3),
        // Calls a visitor (or a browser) may start an hour, and a day, on one agent.
        'calls_per_visitor_hour' => (int) env('LISTEN_AGENT_CALLS_PER_VISITOR_HOUR', 6),
        'calls_per_visitor_day' => (int) env('LISTEN_AGENT_CALLS_PER_VISITOR_DAY', 15),
        // A caller who stays quiet this long is asked if they are still there; twice as long ends the call.
        'silence_seconds' => (int) env('LISTEN_AGENT_SILENCE_SECONDS', 20),
        // The conversation the live voice keeps in mind besides its instructions (older turns drop
        // out): every turn is billed again for all of it, so this bounds what a long call costs.
        'history_tokens' => (int) env('LISTEN_AGENT_HISTORY_TOKENS', 8000),
        // What Google charges for the real-time voice (per million tokens), to keep each call's cost.
        'price_per_million' => [
            'text_in' => (float) env('LISTEN_AGENT_PRICE_TEXT_IN', 0.75),
            'audio_in' => (float) env('LISTEN_AGENT_PRICE_AUDIO_IN', 3.0),
            'text_out' => (float) env('LISTEN_AGENT_PRICE_TEXT_OUT', 4.5),
            'audio_out' => (float) env('LISTEN_AGENT_PRICE_AUDIO_OUT', 12.0),
        ],
        // The written chat (AgentChats). A reply's credits when Agents → Settings sets none and no
        // paid plan gives a rate, and what one conversation, one visitor and one message may take.
        'chat_credits_per_reply' => (int) env('LISTEN_AGENT_CHAT_CREDITS_PER_REPLY', 60),
        // What the text model charges per million tokens, as its price list says (read on
        // 13 November 2026): what it reads, what it writes (its thinking counted), and what it
        // reads again from its own cache. The same list doubles each of them on 1 January 2027;
        // "chat_price_changes" carries every dated change, so a reply's cost and the credits it
        // needs follow by themselves on the day. A price set in the environment is used as it
        // is, with no dated change.
        'chat_price_per_million' => [
            'in' => (float) env('LISTEN_AGENT_CHAT_PRICE_IN', 0.75),
            'out' => (float) env('LISTEN_AGENT_CHAT_PRICE_OUT', 3.75),
            'cached' => (float) env('LISTEN_AGENT_CHAT_PRICE_CACHED', 0.075),
        ],
        'chat_price_changes' => env('LISTEN_AGENT_CHAT_PRICE_IN') !== null || env('LISTEN_AGENT_CHAT_PRICE_OUT') !== null ? [] : [
            ['from' => '2027-01-01', 'in' => 1.5, 'out' => 7.5, 'cached' => 0.15],
        ],
        // How much the text model thinks before a chat reply (minimal, low, medium, high; empty: its own).
        'chat_thinking' => (string) env('LISTEN_AGENT_CHAT_THINKING', 'low'),
        'chat_replies_per_conversation' => (int) env('LISTEN_AGENT_CHAT_REPLIES', 40),
        'chat_message_chars' => (int) env('LISTEN_AGENT_CHAT_MESSAGE_CHARS', 1000),
        'chats_per_visitor_hour' => (int) env('LISTEN_AGENT_CHATS_PER_VISITOR_HOUR', 6),
        'chats_per_visitor_day' => (int) env('LISTEN_AGENT_CHATS_PER_VISITOR_DAY', 15),
        // Replies one visitor may have on one agent in an hour and a day (a network: three times
        // these; on Listen's own agents, which answer free, a third of them).
        'chat_replies_per_visitor_hour' => (int) env('LISTEN_AGENT_CHAT_REPLIES_PER_VISITOR_HOUR', 80),
        'chat_replies_per_visitor_day' => (int) env('LISTEN_AGENT_CHAT_REPLIES_PER_VISITOR_DAY', 250),
        // A chat opened and never written in is closed after this many minutes.
        'chat_empty_minutes' => (int) env('LISTEN_AGENT_CHAT_EMPTY_MINUTES', 3),
        // A chat nobody wrote in for this long is closed: its unused credits go back to the owner.
        'chat_idle_minutes' => (int) env('LISTEN_AGENT_CHAT_IDLE_MINUTES', 15),
    ],
];
