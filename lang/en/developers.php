<?php

/*
 * Developer API: the dashboard section (keys and usage) and the documentation.
 * Text between backticks is shown as code (names, values, paths) and is the
 * only place a word of the other language may appear: the Arabic file keeps
 * every other word in Arabic, this one every other word in English.
 */

return [
    'nav' => 'Developer API',
    'footer_link' => 'API documentation',

    'scopes' => [
        'text_to_speech' => 'Text to speech',
        'voices_read' => 'Read voices and models',
        'history_read' => 'Read history and audio',
        'history_write' => 'Delete history',
        'user_read' => 'Read account and usage',
    ],

    'keys' => [
        'not_in_plan_title' => 'Your plan does not include the developer API',
        'not_in_plan_body' => 'The developer API comes with :plans. You are on :plan.',
        'not_in_plan_body_none' => 'Your current plan (:plan) does not include the developer API.',
        'trial_title' => 'The developer API starts after your first payment',
        'trial_body' => 'You are on a free trial. Keys work once your first payment has gone through; your trial credits stay for the studio and the extension.',
        'upgrade_cta' => 'Compare plans',
        'create_title' => 'Create a key',
        'name_label' => 'Name',
        'name_placeholder' => 'For example: Production assistant',
        'permissions_label' => 'Permissions',
        'limit_label' => 'Monthly credit limit',
        'limit_placeholder' => 'No limit',
        'limit_help' => 'Optional. The key stops for the rest of the month once it has used this many credits. Your plan\'s limit always applies.',
        'create_button' => 'Create key',
        'cancel' => 'Cancel',
        'new_key_title' => 'Your new key',
        'new_key_body' => 'Copy it now and keep it somewhere safe. For your security it will not be shown again.',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'list_title' => 'Your keys',
        'keys_count' => ':count of :max',
        'empty' => 'No keys yet. Create one to make your first request.',
        'never' => 'Never used',
        'last_used' => 'Used :time',
        'created_on' => 'Created :date',
        'all_permissions' => 'All permissions',
        'credits_of_limit' => ':used of :limit credits this month',
        'credits_used' => ':used credits this month',
        'requests_count' => ':count requests',
        'edit' => 'Edit',
        'save' => 'Save',
        'revoke' => 'Revoke',
        'revoke_confirm' => 'Revoke this key? Apps using it will stop working at once. This cannot be undone.',
        'revoked_title' => 'Revoked keys',
        'revoked_on' => 'Revoked :date',
        'flash_created' => 'Your key was created.',
        'flash_updated' => 'The key was updated.',
        'flash_revoked' => 'The key was revoked.',
        'max_keys' => 'You can have up to :count active keys. Revoke one to create another.',
        'name_required' => 'Give the key a name.',
        'scopes_required' => 'Choose at least one permission.',
        'limit_invalid' => 'The credit limit must be a whole number above zero.',
        'security_body' => 'Keep keys secret: use them from your server only, never in a web page or a mobile app. If a key leaks, revoke it here and create a new one.',
    ],

    'dashboard' => [
        'menu' => 'Developer API',
        'menu_short' => 'API',
        'title' => 'Developer API',
        'subtitle' => 'Your voices in your own app, website or AI assistant.',
        'ring_caption' => 'of credits',
        'ring_label' => 'The developer API used :percent% of this month\'s credits',
        'hero_label' => 'Credits used through the API',
        'hero_of' => 'of :total this month',
        'hero_of_pack' => 'of :total in your pack',
        'split_api' => 'Developer API',
        'split_app' => 'Studio and extension',
        'split_left' => 'Left',
        'renews' => 'Credits reset on :date',
        'plan_ends' => 'Plan active until :date',
        'pack_ends' => 'Your pack ends on :date',
        'docs_cta' => 'Read the docs',
        'tile_requests' => 'API requests this month',
        'tile_requests_foot' => 'Active keys: :count',
        'tile_rpm' => 'Requests a minute',
        'tile_rpm_foot' => 'For the whole account (all keys together), with up to :count generations at the same time.',
        'tile_chars' => 'Characters a request',
        'tile_chars_foot' => 'Up to :count in the background. Audio formats:',
        'features_title' => 'In your plan',
        'feature_included' => 'Included',
        'feature_missing' => 'Not in your plan',
        'features' => [
            'style_prompt' => 'Custom direction',
            'audio_effect' => 'Sound environments',
            'dialogue' => 'Dialogue',
            'cloned_voices' => 'Cloned voices',
        ],
        'shared_note' => 'The studio, the extension and the API share one balance. Usage stops at your plan\'s limit, so there is never an overage charge.',
    ],

    'docs' => [
        'seo_title' => 'Text to Speech API for Arabic and English',
        'seo_description' => "The Listen text to speech API: 140+ Arabic dialects, English accents, delivery styles and emotions, metered on your plan's credits with no overage.",

        'title' => 'Listen API',
        'subtitle' => 'Natural Arabic and English speech for your apps and assistants. The studio\'s voices, :options, from one request.',
        'cta_keys' => 'Get your API key',
        'cta_quickstart' => 'Read the quickstart',
        'base_url' => 'Base URL',
        'hero_pause' => 'Pause',
        'hero_voice_dialect' => ':name, :dialect',
        'hero_result' => 'The audio this request returned',
        'hero_play_result' => 'Play the audio this request returned',
        'hero_voice' => ':name, premium voice',
        'search' => 'Search the docs',
        'no_results' => 'Nothing matches your search.',
        'on_this_page' => 'Contents',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'request' => 'Request',
        'response' => 'Response',
        'required' => 'Required',
        'optional' => 'Optional',
        'some_plans' => 'Some plans',
        'permission' => 'Permission',
        'header_params' => 'Headers',
        'path_params' => 'Path parameters',
        'query_params' => 'Query parameters',
        'body_params' => 'Body',
        'response_headers' => 'Response headers',

        'types' => [
            'string' => 'text',
            'integer' => 'whole number',
            'number' => 'number',
            'boolean' => 'true or false',
            'object' => 'object',
            'array' => 'list',
        ],

        // Lists joined into sentences (ApiDocs::listed). An item keyed by a
        // feature shows only while some plan includes that feature.
        'lists' => [
            'subtitle' => ['140+ Arabic dialects', 'English accents', 'emotions', 'sound_environments' => 'sound environments'],
            'intro_features' => ['sound_environments' => 'sound environments', 'dialogue' => 'dialogue with several voices'],
            'plan_features' => ['custom_direction' => 'custom direction (`style_prompt`)', 'sound_environments' => 'sound environments (`audio_effect`)', 'dialogue' => 'dialogue (`dialogue`)', 'cloned_voices' => 'cloned voices', 'some audio formats'],
            'options_fields' => ['`dialect`', '`style`', '`emotion`', 'sound_environments' => '`audio_effect`'],
            'models_options' => ['dialects', 'accents', 'styles', 'emotions', 'sound tags', 'sound_environments' => 'sound environments', 'dialogue' => 'dialogue'],
        ],

        'groups' => [
            'start' => 'Getting started',
            'guides' => 'Guides',
            'reference' => 'API reference',
            'more' => 'More',
        ],

        'intro' => [
            'title' => 'Introduction',
            'body' => 'The Listen API turns text into natural speech. It is a web API that takes JSON and answers with JSON, except for audio, which comes back as the file itself. It uses the same voices, studio options and credits as your Listen dashboard: anything you can make in the studio, you can make from code.',
            // The first point, with the formats some plan includes.
            'point_formats' => 'One request turns text into an audio file (`mp3`, or :formats on the plans that include them).',
            'point_formats_mp3_only' => 'One request turns text into an audio file (`mp3`).',
            'points' => [
                'Every Arabic dialect and English accent of the studio, with delivery styles, emotions, sound tags, speed and pitch.',
                'Usage is counted on your plan\'s credits and stops at your limit: there are no surprise bills.',
            ],
            // After the dialects point, when some plan includes one of them.
            'points_features' => ':options, on the plans that include them.',
            'code_note' => 'Field names, values and messages of the API are in English, as they are part of the code.',
        ],

        'quickstart' => [
            'title' => 'Quickstart',
            'step1' => 'Create a key in your dashboard and copy it: it is shown only once.',
            'step2' => 'Keep it in an environment variable named `LISTEN_API_KEY`, never in your code.',
            'step3' => 'Send your text and save the audio that comes back. Choose your language on the code.',
        ],

        'auth' => [
            'title' => 'Authentication',
            'body' => 'Send your key with every request in the `X-Listen-Api-Key` header. The standard `Authorization: Bearer` header works too.',
            'security' => 'Keys are secret. Call the API from your server only; never put a key in a web page or a mobile app. If a key may have leaked, revoke it in the dashboard and create a new one. Listen keys start with `lsk_live_`, so secret scanners can find them.',
            'scopes_title' => 'Permissions',
            'scopes_body' => 'Each key can be limited to some permissions. A request that needs a permission the key lacks is refused with `403` and `missing_permission`.',
        ],

        'plans' => [
            'title' => 'Plans and limits',
            'body' => 'The developer API comes with the plans below. It starts once your first payment has gone through (not during a free trial). This table is read from the plans as they are now, and the API applies exactly these limits, for your whole account (all its keys together).',
            'none' => 'The developer API is being enabled on plans. Contact us to get access.',
            'row_credits' => 'Credits a month',
            'row_credits_pack' => 'Credits in the pack',
            'row_monthly_requests' => 'Requests a month',
            'row_monthly_none' => 'No monthly limit',
            'row_daily' => 'Generations a day',
            'row_daily_none' => 'No daily limit',
            'row_rpm' => 'Generation requests a minute',
            'row_reads' => 'Other requests a minute',
            'row_concurrency' => 'Generations at once, waiting for the file',
            'row_queue' => 'Background generations waiting',
            'row_max_chars' => 'Characters a request, waiting for the file',
            'row_max_chars_async' => 'Characters a request, in the background',
            'row_formats' => 'Audio formats',
            'features_heading' => 'Studio options',
            'included' => 'Included',
            'not_included' => 'Not included',
            'features' => [
                'custom_direction' => 'Custom direction',
                'sound_environments' => 'Sound environments',
                'dialogue' => 'Dialogue',
                'cloned_voices' => 'Cloned voices',
            ],
        ],

        'credits' => [
            'title' => 'Credits and billing',
            'points' => [
                'The studio, the browser extension and the developer API share one balance: your plan\'s credits for the month. Each costs exactly the same.',
                'Credits pay for audio: 15 credits for each second (900 a minute).',
                'Before generating, credits are reserved from an estimate of the text. When the audio is ready, the charge is corrected to its real length.',
                'If a generation fails, every credit it reserved is given back.',
                'Usage stops at your plan\'s limit. A request that would go over it is refused with `402` and `quota_exceeded`; it is never charged as overage. Your plan\'s credits start again each month on the day the plan started (the 14th for a plan that started on the 14th), and again when you subscribe again; `credits.resets_at` in `GET /v1/user/subscription` gives the exact time.',
                'You can give each key its own monthly credit limit in the dashboard, to cap what one app or customer can spend (`key_credit_limit_exceeded`).',
            ],
        ],

        'dialects' => [
            'title' => 'Dialects and accents',
            'body' => 'Every voice can speak any dialect or accent of its language. Send the dialect code in `dialect`: Sumaya in Lebanese, for example, is her voice id with `ar-LB`, and an English voice in British English takes `en-GB`. Write the text the way it should be said; the voice follows the dialect you choose. The full list is at `GET /v1/voice-options`.',
        ],

        'styles' => [
            'title' => 'Styles, emotions and sound tags',
            'body' => 'Choose a delivery style (`style`, such as `news` or `assistant`) and an emotion (`emotion`, such as `warm` or `calm`). Left out, they follow the studio\'s defaults for the language. `voice_settings.speed` and `voice_settings.pitch` fine-tune the voice.',
            'prompt' => 'On plans with custom direction, `style_prompt` takes your own direction in words.',
            'tags' => 'Sound tags in the text add a momentary sound where they stand, for example `<laugh>`, `<sigh>` or `<short pause>`. A text needs words as well as tags.',
        ],

        'environments' => [
            'title' => 'Sound environments',
            'body' => 'Place the voice somewhere with `audio_effect`: a small room, a concert hall, a phone call, an old radio and more. The whole recording is processed and brought to an even, broadcast loudness. Sound environments are on some plans.',
            'col_value' => 'Value',
            'col_name' => 'Environment',
        ],

        'dialogue' => [
            'title' => 'Dialogue',
            'body' => 'Have several voices talk in one recording. List from 2 to 6 speakers in `dialogue.speakers`, each with a `name` and a `voice_id` (and, if you like, its own `dialect`), then start each line of the text with a speaker\'s name and a colon. Every speaker speaks the language of the text. Dialogue is on some plans.',
        ],

        'plan_features' => [
            'title' => 'Plan features',
            'body' => 'Every studio option works through the API. A few are on some plans only: :options.',
            'refusal' => 'A request that uses an option your plan does not include is refused before anything is charged, with `403` and `feature_not_in_plan`. The message names the plans that include it, and `available_on_plans` lists them for your code. `GET /v1/user/subscription` says in `plan.features` which options your plan has.',
        ],

        'long' => [
            'title' => 'Long texts',
            'body' => 'A request waits until the audio is ready, usually a few seconds, and carries up to :sync characters. Longer texts, up to :async characters, go to the background endpoint: it answers at once, and you check the history item until it is ready. Your plan may set a lower limit (see Plans and limits). Split longer texts at sentence or paragraph ends.',
            'dialogue' => 'A dialogue carries up to :dialogue characters a request.',
        ],

        'formats' => [
            'title' => 'Audio formats',
            'body' => 'Ask for a file type with `output_format`. `mp3` is always accepted; :formats work on the plans that include them (see Plans and limits). If the server\'s audio converter is unavailable, the audio may come back as WAV even when you ask for `mp3`. The `Content-Type` and `X-Output-Format` headers always say what the body is.',
            'body_mp3_only' => 'Ask for a file type with `output_format`: `mp3` is available. If the server\'s audio converter is unavailable, the audio may come back as WAV instead. The `Content-Type` and `X-Output-Format` headers always say what the body is.',
        ],

        'idempotency' => [
            'title' => 'Safe retries',
            'body' => 'Networks fail. To retry a generation without paying twice, send an `Idempotency-Key` header with a value of your own (a random id, for example) on the text to speech requests.',
            'points' => [
                'Sent again within 24 hours with the same key and body, the request returns the first result, marked `Idempotent-Replayed: true`, and nothing is charged again.',
                'The same key with a different body is refused with `422` and `idempotency_key_reused`.',
                'While the first request is still running, a retry gets `409` and `idempotent_request_in_progress`: wait, then send it again.',
                'If the first generation failed, the key can be used again: nothing was charged for it.',
                'If you deleted the first generation, a retry gets `410` and `idempotent_result_deleted` and nothing is made or charged: send a new key to generate it again.',
            ],
        ],

        'retries' => [
            'title' => 'Errors and retries',
            'body' => 'On `429` and on `5xx` answers, wait and try again with a growing delay (for example 1, 2 then 4 seconds, up to five tries), and follow `Retry-After` when it is sent. Send an `Idempotency-Key` so a retry never generates twice. Do not retry other `4xx` answers without changing the request: they will fail the same way.',
        ],

        'practices' => [
            'title' => 'Best practices',
            'points' => [
                'Keep the audio you generate (or its `history_item_id`) instead of generating the same text twice: every generation uses credits.',
                'Check `GET /v1/user/subscription` before large batches, and give keys you hand to customers or test systems their own credit limit.',
                'Log the `X-Request-Id` of failed requests: support can find exactly what happened.',
                'Use one key for each app or environment, so you can revoke one without stopping the others.',
            ],
        ],

        'endpoints' => [
            'tts' => [
                'title' => 'Convert text to speech',
                'summary' => 'Generates speech and returns the audio file in the body. The request waits until the audio is ready.',
            ],
            'tts_async' => [
                'title' => 'Convert in the background',
                'summary' => 'Takes the same body, but answers at once with `202` and a history item. Check `GET /v1/history/{history_item_id}` every few seconds until `status` is `completed` (then download `audio_url`) or `failed` (the credits are given back).',
            ],
            'voices_list' => [
                'title' => 'List voices',
                'summary' => 'The studio voices and your own ready cloned voices. `preview_url` plays a sample.',
                'summary_studio' => 'The studio voices. `preview_url` plays a sample.',
            ],
            'voices_get' => [
                'title' => 'Get a voice',
                'summary' => 'One voice by its id.',
            ],
            'options' => [
                'title' => 'List voice options',
                'summary' => 'Every value :fields accept, the defaults used when they are left out, the output formats your plan can ask for, and which plan options you have.',
            ],
            'models' => [
                'title' => 'List models',
                'summary' => 'One model: `listen_tts_premium_v1`, the studio\'s expressive voices with :options. `supports` says which options your plan includes. The version in its id means a future model will sit beside it and never change what this one does.',
            ],
            'history_list' => [
                'title' => 'List history',
                'summary' => 'Your generations from the developer API, the studio and the extension, newest first. `request_source` says where each came from. Pass `next_cursor` as `before` to get the next page.',
            ],
            'history_get' => [
                'title' => 'Get a history item',
                'summary' => 'One generation, with its status, cost and audio address.',
            ],
            'history_audio' => [
                'title' => 'Download history audio',
                'summary' => 'The audio file of a finished generation, in any format your plan includes.',
            ],
            'history_delete' => [
                'title' => 'Delete a history item',
                'summary' => 'Deletes a finished generation. The credits it used are not given back.',
            ],
            'user' => [
                'title' => 'Get your account',
                'summary' => 'Your name, email and the same plan and usage as below.',
            ],
            'subscription' => [
                'title' => 'Get plan, usage and limits',
                'summary' => 'Your plan and the options it includes, the credits used and left this month, when they renew, your limits and this key\'s own usage. Use it to show usage in your app, or to stop before you run out.',
            ],
        ],

        'params' => [
            'voice_id' => 'A voice id from `GET /v1/voices`, such as `voice_12`, or one of your cloned voices (`cloned_3`).',
            'voice_id_studio' => 'A voice id from `GET /v1/voices`, such as `voice_12`.',
            'idempotency_key' => 'A value of your own, unique to this request, so a retry never generates or charges twice. See Safe retries.',
            'output_format' => 'File type: `mp3` (default) or another type your plan includes (see Audio formats).',
            'text' => 'The text to read, up to the characters one request may carry (see Long texts).',
            'model_id' => 'Always `listen_tts_premium_v1`, the only model. It may be left out.',
            'language_code' => '`ar` or `en`. Defaults to the voice\'s language.',
            'dialect' => 'Arabic dialect (such as `ar-EG`, `ar-SA`, `ar-LB`) or English accent (such as `en-US`, `en-GB`).',
            'style' => 'Delivery style, such as `narrative`, `news`, `assistant` or `podcast`.',
            'emotion' => 'Emotion, such as `warm`, `calm`, `excited` or `serious`.',
            'style_prompt' => 'Your own direction for the delivery, in words.',
            'audio_effect' => 'A sound environment for the whole recording, such as `hall`, `phone` or `radio`. See Sound environments.',
            'dialogue' => 'From 2 to 6 speakers, each with `name`, `voice_id` and an optional `dialect`. See Dialogue.',
            'speed' => '`voice_settings.speed`: from 0.5 to 2 (default 1).',
            'pitch' => '`voice_settings.pitch`: from -10 to 10 (default 0).',
            'translate' => '`true` to translate the text into `language_code` before reading it.',
            'title' => 'A label for the history item.',
            'same_body' => 'Every other field of Convert text to speech works here too.',
            'filter_language' => 'Only voices of this language: `ar` or `en`.',
            'filter_model' => 'Only voices of this model.',
            'filter_gender' => '`female` or `male`.',
            'filter_category' => '`premade` (studio voices) or `cloned` (yours).',
            'filter_category_studio' => '`premade`: the studio voices.',
            'page_size' => 'Items a page, from 1 to 100 (default 30).',
            'before' => 'The `next_cursor` of the previous page.',
            'history_voice' => 'Only generations with this voice.',
            'source' => '`api` (developer API) or `app` (studio and extension).',
            'status' => '`queued`, `processing`, `completed` or `failed`.',
            'history_item_id' => 'The id of the history item.',
        ],

        'headers' => [
            'X-History-Item-Id' => 'History item of this generation, to fetch it again later.',
            'X-Character-Count' => 'Characters of the text that was read.',
            'X-Credits-Used' => 'Credits charged: 15 for each second of audio.',
            'X-Credits-Remaining' => 'Credits left on your plan this period.',
            'X-Audio-Duration-Seconds' => 'Length of the audio, in seconds.',
            'X-Output-Format' => 'File type of the body.',
            'Idempotent-Replayed' => '`true` when this is the saved result of an earlier request with the same `Idempotency-Key`.',
            'X-Request-Id' => 'Id of this request. Include it when you contact support.',
            'X-RateLimit-Limit' => 'Requests your account may send a minute, all its keys together (generations and other requests are counted separately).',
            'X-RateLimit-Remaining' => 'Requests left this minute.',
            'Retry-After' => 'Seconds to wait before trying again (on `429`).',
        ],

        'errors' => [
            'title' => 'Errors',
            'body' => 'Errors use standard status codes and always have the same body. `type` is the broad kind, `code` the exact reason, and `param` the request field at fault, when there is one.',
            'col_status' => 'Status',
            'col_code' => 'Code',
            'col_meaning' => 'Meaning',
            'rows' => [
                ['400', 'invalid_json', 'The body is not valid JSON.'],
                ['400', 'invalid_idempotency_key', 'The `Idempotency-Key` is longer than 255 characters.'],
                ['401', 'missing_api_key', 'No key was sent.'],
                ['401', 'invalid_api_key', 'The key is wrong or revoked.'],
                ['402', 'quota_exceeded', 'Your plan does not have enough credits left this month.'],
                ['402', 'key_credit_limit_exceeded', 'This key has reached its own monthly credit limit.'],
                ['403', 'api_access_not_in_plan', 'Your plan does not include the developer API.'],
                ['403', 'api_not_available_during_trial', 'The developer API starts after your first payment.'],
                ['403', 'missing_permission', 'The key lacks the permission for this endpoint.'],
                ['403', 'account_suspended', 'The account is suspended. Contact support.'],
                ['403', 'feature_not_in_plan', 'An option, voice or format is not in your plan. The message names the plans that include it.'],
                ['404', 'voice_not_found', 'No voice has this id.'],
                ['404', 'history_item_not_found', 'No history item has this id.'],
                ['409', 'audio_not_ready', 'The generation has not finished yet.'],
                ['409', 'history_item_in_progress', 'The generation is still running, so it cannot be deleted yet.'],
                ['409', 'idempotent_request_in_progress', 'A request with this `Idempotency-Key` is still running.'],
                ['410', 'audio_expired', 'The audio file of this history item is no longer stored.'],
                ['410', 'idempotent_result_deleted', 'The generation made with this `Idempotency-Key` was deleted. Use a new key to generate it again.'],
                ['422', 'validation_failed', 'A parameter is missing or not valid (see `param`).'],
                // A fourth value: the row shows only when some plan includes that feature.
                ['422', 'invalid_dialect', 'Unknown `dialect` for the language. The same goes for `invalid_style` and `invalid_emotion`.'],
                ['422', 'invalid_audio_effect', 'Unknown `audio_effect`.', 'sound_environments'],
                ['422', 'invalid_dialogue', 'The speakers or the text of a dialogue do not fit together.', 'dialogue'],
                ['422', 'invalid_output_format', 'Unknown `output_format` in the address, for example on `GET /v1/history/{history_item_id}/audio`.'],
                ['422', 'language_mismatch', 'The voice does not speak the `language_code` asked for.'],
                ['422', 'text_too_long', 'The text is longer than one request may carry (see Plans and limits); the message says which limit.'],
                ['422', 'text_has_no_words', 'The text has only tags or sounds, nothing to read.'],
                ['422', 'idempotency_key_reused', 'This `Idempotency-Key` was used for a different request.'],
                ['422', 'content_policy_violation', 'The text contains words that are not allowed.'],
                ['429', 'rate_limit_exceeded', 'Too many requests a minute for your account.'],
                ['429', 'concurrency_limit_exceeded', 'Too many generations running at the same time.'],
                ['429', 'queue_limit_exceeded', 'Too many background generations waiting.'],
                ['429', 'daily_limit_exceeded', 'Your plan\'s daily generation limit is reached.'],
                ['429', 'too_many_failed_attempts', 'Too many refused requests (no key, a wrong key, or a key the API cannot serve) from your address. Wait a minute.'],
                ['502', 'generation_failed', 'The voice could not be made. No credits were charged; try again.'],
                ['503', 'feature_unavailable', 'Sound environments are briefly unavailable.', 'sound_environments'],
                ['503', 'output_format_unavailable', 'An audio format other than `mp3` is briefly unavailable.'],
                ['503', 'server_busy', 'The service is busy for everyone right now; try again a few seconds later.'],
                ['503', 'queue_unavailable', 'A background generation could not start; nothing was charged. Try again shortly.'],
                ['503', 'capacity_reached', 'The API has reached its speech capacity for today; try again the next day.'],
                ['503', 'api_paused', 'The API is paused for maintenance; try again later.'],
                ['500', 'internal_error', 'Something went wrong on our side. Try again; if it persists, contact support with the `request_id`.'],
            ],
        ],

        'limits' => [
            'title' => 'Rate limits',
            'body' => 'Limits depend on your plan (see Plans and limits). Answers to requests that pass the key check carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`, errors included; answers refused at the key check (no key, a wrong or revoked key, a key the API cannot serve, too many refused requests, or the API paused) do not. `Retry-After` comes with `429` answers and with the `503` answers that ask you to wait, and every answer carries `X-Request-Id`.',
            'points' => [
                'Generation requests a minute, for your whole account (all its keys together). Over it: `429` and `rate_limit_exceeded`.',
                'Other requests a minute (status checks, voices, history, account) have their own allowance, also for the whole account, so checking on your generations never uses up the generations.',
                'Generations at the same time while you wait for the file, for the whole account. Over it: `429` and `concurrency_limit_exceeded`. When the service is busy for everyone, the answer is `503` and `server_busy`: try again a few seconds later.',
                'Background generations waiting or running, for the whole account. Over it: `429` and `queue_limit_exceeded`.',
                'Plans with a daily generation limit answer `429` and `daily_limit_exceeded` until the day starts again (`requests.daily_resets_at` in `GET /v1/user/subscription`).',
            ],
        ],

        'versioning' => [
            'title' => 'Versions and changes',
            'body' => 'The version is part of the address (`/v1`). Within it we only add: new endpoints, new optional parameters, new fields and new error codes. Your code should ignore fields it does not know. Anything that would break existing code will come as a new version, announced in advance.',
            'openapi' => 'The full description of the API, for your tools and code generators, is at `/v1/openapi.json`.',
            'changelog_title' => 'Changes',
            'changelog' => [
                ['2026-10-18', 'First version: text to speech (now and in the background) with every studio option, sound environments, dialogue and safe retries; voices, models, options, history, account and usage.'],
            ],
        ],

        'support' => [
            'title' => 'Support',
            'body' => 'Questions, higher limits or a plan for your company? Contact us at :email and include the `X-Request-Id` of any request you ask about.',
        ],
    ],
];
