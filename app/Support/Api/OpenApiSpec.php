<?php

namespace App\Support\Api;

use App\Services\Api\ApiIdempotency;
use App\Services\Api\ApiSpeechService;
use App\Support\AudioEffects;
use App\Support\SpeechLanguages;

/**
 * The OpenAPI 3.1 description of the developer API, served at
 * /v1/openapi.json and linked from the /developers page. Keep it in step
 * with routes/v1.php and the controllers in App\Http\Controllers\Api\V1.
 */
final class OpenApiSpec
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        // Options no plan includes are left out, as on the /developers page.
        $offered = ApiFeatureGate::offered();
        $extras = array_keys(array_filter([
            'sound environments' => $offered[ApiFeatureGate::SOUND_ENVIRONMENTS],
            'dialogue' => $offered[ApiFeatureGate::DIALOGUE],
            'your own cloned voices' => $offered[ApiFeatureGate::CLONED_VOICES],
        ]));

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Listen API',
                'version' => self::VERSION,
                'summary' => 'Arabic and English text to speech: '.ApiDocs::series(['studio voices', 'Arabic dialects', 'English accents', 'emotions', ...$extras], false).'.',
                'description' => 'Turn text into natural Arabic and English speech with one model, '.ApiModels::PREMIUM.', and every option of the studio. The dashboard, the browser extension and the API share one balance: generations are charged to your plan\'s credits exactly as in the dashboard (15 credits per second of audio) and stop at your plan\'s limit, with no overage. Options a plan does not include are refused with 403 feature_not_in_plan, naming the plans that include them. Full guide: '.url('/developers'),
                'contact' => ['name' => 'Listen support', 'email' => 'support@listenai.studio', 'url' => url('/contact-us')],
                'termsOfService' => url('/terms-of-service'),
            ],
            'servers' => [['url' => url('/v1'), 'description' => 'Production']],
            'security' => [['ApiKeyHeader' => []], ['BearerAuth' => []]],
            'tags' => [
                ['name' => 'Text to speech', 'description' => 'Generate speech from text.'],
                ['name' => 'Voices', 'description' => 'Voices, models and delivery options.'],
                ['name' => 'History', 'description' => 'Past generations and their audio.'],
                ['name' => 'User', 'description' => 'Your account, plan and usage.'],
            ],
            'paths' => self::paths(),
            'components' => self::components(),
        ];

        foreach ([ApiFeatureGate::CUSTOM_DIRECTION, ApiFeatureGate::SOUND_ENVIRONMENTS, ApiFeatureGate::DIALOGUE] as $feature) {
            if (! $offered[$feature]) {
                unset($spec['components']['schemas']['TextToSpeechRequest']['properties'][ApiFeatureGate::FIELDS[$feature]]);
            }
        }
        if (! $offered[ApiFeatureGate::CLONED_VOICES]) {
            $spec['paths']['/voices']['get']['description'] = 'The studio voices. Requires the voices_read permission.';
            array_walk_recursive($spec['paths'], static function (mixed &$value, string|int $key): void {
                if ($key === 'description' && $value === 'A voice id from GET /v1/voices, such as voice_12 or cloned_3.') {
                    $value = 'A voice id from GET /v1/voices, such as voice_12.';
                }
            });
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        $voiceIdParam = [
            'name' => 'voice_id', 'in' => 'path', 'required' => true,
            'description' => 'A voice id from GET /v1/voices, such as voice_12 or cloned_3.',
            'schema' => ['type' => 'string', 'pattern' => '^(voice|cloned)_[0-9]+$'],
        ];
        $historyIdParam = [
            'name' => 'history_item_id', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
        $formatParam = [
            'name' => 'output_format', 'in' => 'query', 'required' => false,
            'description' => 'File type of the audio. mp3 is always accepted; the others when your plan includes them. If the server\'s audio converter is unavailable, the audio may come back as WAV; Content-Type and X-Output-Format always say what the body is.',
            'schema' => ['type' => 'string', 'enum' => ApiSpeechService::OUTPUT_FORMATS, 'default' => 'mp3'],
        ];

        $idempotencyParam = [
            'name' => ApiIdempotency::HEADER, 'in' => 'header', 'required' => false,
            'description' => 'A value of your own, unique to this request (up to 255 characters). Sent again within 24 hours with the same body, the first result is returned (Idempotent-Replayed: true) and nothing is charged again.',
            'schema' => ['type' => 'string', 'maxLength' => 255],
        ];

        $ttsBody = [
            'required' => true,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TextToSpeechRequest']]],
        ];

        return [
            '/text-to-speech/{voice_id}' => [
                'post' => [
                    'tags' => ['Text to speech'],
                    'operationId' => 'textToSpeech',
                    'summary' => 'Convert text to speech',
                    'description' => 'Generates speech and returns the audio file in the response body. Requires the text_to_speech permission.',
                    'parameters' => [$voiceIdParam, $idempotencyParam, $formatParam],
                    'requestBody' => $ttsBody,
                    'responses' => [
                        '200' => [
                            'description' => 'The audio file.',
                            'headers' => [
                                'X-History-Item-Id' => self::header('integer', 'History item of this generation.'),
                                'X-Character-Count' => self::header('integer', 'Characters in the text that was read.'),
                                'X-Credits-Used' => self::header('integer', 'Credits charged (15 per second of audio).'),
                                'X-Credits-Remaining' => self::header('integer', 'Credits left this period.'),
                                'X-Audio-Duration-Seconds' => self::header('integer', 'Length of the audio.'),
                                'X-Output-Format' => self::header('string', 'File type of the body.'),
                                'Idempotent-Replayed' => self::header('string', 'true when this is the saved result of an earlier request with the same Idempotency-Key.'),
                                'X-Request-Id' => self::header('string', 'Id of this request, for support.'),
                                'X-RateLimit-Limit' => self::header('integer', 'Requests your account may send a minute, all its keys together (generations and other requests are counted separately).'),
                                'X-RateLimit-Remaining' => self::header('integer', 'Requests left this minute.'),
                            ],
                            'content' => [
                                'audio/mpeg' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                                'audio/wav' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                                'audio/mp4' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                                'audio/ogg' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                                'audio/flac' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                            ],
                        ],
                    ] + self::errorResponses(['400', '401', '402', '403', '404', '409', '422', '429', '502', '503']),
                ],
            ],
            '/text-to-speech/{voice_id}/async' => [
                'post' => [
                    'tags' => ['Text to speech'],
                    'operationId' => 'textToSpeechAsync',
                    'summary' => 'Convert text to speech in the background',
                    'description' => 'Queues the generation and answers at once with a history item. Poll GET /v1/history/{history_item_id} until its status is completed or failed, then download the audio. Best for long texts. Credits are reserved now and given back if the generation fails. Each account may have a few background generations waiting at once (429 queue_limit_exceeded).',
                    'parameters' => [$voiceIdParam, $idempotencyParam],
                    'requestBody' => $ttsBody,
                    'responses' => [
                        '202' => [
                            'description' => 'Accepted; the generation is queued.',
                            'headers' => ['Location' => self::header('string', 'URL of the history item.')],
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/HistoryItem']]],
                        ],
                    ] + self::errorResponses(['400', '401', '402', '403', '404', '409', '422', '429', '503']),
                ],
            ],
            '/voices' => [
                'get' => [
                    'tags' => ['Voices'],
                    'operationId' => 'listVoices',
                    'summary' => 'List voices',
                    'description' => 'Studio voices and your own cloned voices. Requires the voices_read permission.',
                    'parameters' => [
                        ['name' => 'language', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => SpeechLanguages::codes()]],
                        ['name' => 'model_id', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ApiModels::ids()]],
                        ['name' => 'gender', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['female', 'male']]],
                        ['name' => 'category', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['premade', 'cloned']]],
                    ],
                    'responses' => [
                        '200' => self::json('The voices.', [
                            'type' => 'object',
                            'properties' => [
                                'voices' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Voice']],
                                'total_count' => ['type' => 'integer'],
                            ],
                        ]),
                    ] + self::errorResponses(['401', '403', '422', '429']),
                ],
            ],
            '/voices/{voice_id}' => [
                'get' => [
                    'tags' => ['Voices'],
                    'operationId' => 'getVoice',
                    'summary' => 'Get a voice',
                    'parameters' => [$voiceIdParam],
                    'responses' => [
                        '200' => self::json('The voice.', ['$ref' => '#/components/schemas/Voice']),
                    ] + self::errorResponses(['401', '403', '404', '429']),
                ],
            ],
            '/voice-options' => [
                'get' => [
                    'tags' => ['Voices'],
                    'operationId' => 'getVoiceOptions',
                    'summary' => 'List voice options',
                    'description' => 'Values accepted by dialect, style, emotion and audio_effect, the defaults used when they are left out, the output formats your plan can request, and which plan options you have.',
                    'responses' => [
                        '200' => self::json('The options.', ['$ref' => '#/components/schemas/VoiceOptions']),
                    ] + self::errorResponses(['401', '403', '429']),
                ],
            ],
            '/models' => [
                'get' => [
                    'tags' => ['Voices'],
                    'operationId' => 'listModels',
                    'summary' => 'List models',
                    'description' => 'One model, '.ApiModels::PREMIUM.'. supports says which options your plan includes.',
                    'responses' => [
                        '200' => self::json('The models.', [
                            'type' => 'object',
                            'properties' => ['models' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Model']]],
                        ]),
                    ] + self::errorResponses(['401', '403', '429']),
                ],
            ],
            '/history' => [
                'get' => [
                    'tags' => ['History'],
                    'operationId' => 'listHistory',
                    'summary' => 'List generations',
                    'description' => 'Your generations from the API, the dashboard and the browser extension, newest first. Requires the history_read permission.',
                    'parameters' => [
                        ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30]],
                        ['name' => 'before', 'in' => 'query', 'description' => 'Cursor: next_cursor from the previous page.', 'schema' => ['type' => 'integer']],
                        ['name' => 'voice_id', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'source', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['api', 'app']]],
                        ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['queued', 'processing', 'completed', 'failed']]],
                    ],
                    'responses' => [
                        '200' => self::json('A page of history.', [
                            'type' => 'object',
                            'properties' => [
                                'history' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/HistoryItem']],
                                'has_more' => ['type' => 'boolean'],
                                'next_cursor' => ['type' => ['integer', 'null']],
                            ],
                        ]),
                    ] + self::errorResponses(['401', '403', '422', '429']),
                ],
            ],
            '/history/{history_item_id}' => [
                'get' => [
                    'tags' => ['History'],
                    'operationId' => 'getHistoryItem',
                    'summary' => 'Get a generation',
                    'parameters' => [$historyIdParam],
                    'responses' => [
                        '200' => self::json('The history item.', ['$ref' => '#/components/schemas/HistoryItem']),
                    ] + self::errorResponses(['401', '403', '404', '429']),
                ],
                'delete' => [
                    'tags' => ['History'],
                    'operationId' => 'deleteHistoryItem',
                    'summary' => 'Delete a generation',
                    'description' => 'Deletes the history item. Credits it used are not given back. Requires the history_write permission.',
                    'parameters' => [$historyIdParam],
                    'responses' => [
                        '200' => self::json('Deleted.', [
                            'type' => 'object',
                            'properties' => ['history_item_id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']],
                        ]),
                    ] + self::errorResponses(['401', '403', '404', '409', '429']),
                ],
            ],
            '/history/{history_item_id}/audio' => [
                'get' => [
                    'tags' => ['History'],
                    'operationId' => 'getHistoryAudio',
                    'summary' => 'Download the audio of a generation',
                    'parameters' => [$historyIdParam, $formatParam],
                    'responses' => [
                        '200' => [
                            'description' => 'The audio file.',
                            'content' => ['audio/mpeg' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                        ],
                    ] + self::errorResponses(['401', '403', '404', '409', '410', '429']),
                ],
            ],
            '/user' => [
                'get' => [
                    'tags' => ['User'],
                    'operationId' => 'getUser',
                    'summary' => 'Get your account',
                    'description' => 'Requires the user_read permission.',
                    'responses' => [
                        '200' => self::json('The account.', [
                            'type' => 'object',
                            'properties' => [
                                'user_id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'email' => ['type' => ['string', 'null']],
                                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                                'subscription' => ['$ref' => '#/components/schemas/Subscription'],
                            ],
                        ]),
                    ] + self::errorResponses(['401', '403', '429']),
                ],
            ],
            '/user/subscription' => [
                'get' => [
                    'tags' => ['User'],
                    'operationId' => 'getSubscription',
                    'summary' => 'Get your plan, usage and limits',
                    'responses' => [
                        '200' => self::json('Plan, credits and limits.', ['$ref' => '#/components/schemas/Subscription']),
                    ] + self::errorResponses(['401', '403', '429']),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function components(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'securitySchemes' => [
                'ApiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Listen-Api-Key'],
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Authorization: Bearer lsk_live_...'],
            ],
            'schemas' => [
                'TextToSpeechRequest' => [
                    'type' => 'object',
                    'required' => ['text'],
                    'properties' => [
                        'text' => ['type' => 'string', 'maxLength' => ApiSpeechService::ASYNC_MAX_TEXT, 'description' => 'The text to read: up to '.ApiSpeechService::SYNC_MAX_TEXT.' characters when you wait for the file, up to '.ApiSpeechService::ASYNC_MAX_TEXT.' in the background (/async), and up to '.ApiSpeechService::DIALOGUE_MAX_TEXT.' for a dialogue. Your plan may set less (limits in GET /v1/user/subscription). Inline sound tags such as <laugh> and <short pause> are read as sounds. In a dialogue, each line starts with a speaker\'s name and a colon.'],
                        'model_id' => ['type' => 'string', 'enum' => ApiModels::ids(), 'description' => 'Optional; the only model.'],
                        'language_code' => ['type' => 'string', 'enum' => SpeechLanguages::codes(), 'description' => 'Language to speak. Defaults to the voice\'s language.'],
                        'dialect' => ['type' => 'string', 'description' => 'An Arabic dialect such as ar-EG, ar-SA or ar-LB, or an English accent such as en-US or en-GB. See GET /v1/voice-options.', 'examples' => ['ar-LB']],
                        'style' => ['type' => 'string', 'description' => 'Delivery style, such as narrative, news or assistant.'],
                        'emotion' => ['type' => 'string', 'description' => 'Emotion, such as warm, calm or excited.'],
                        'style_prompt' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'On plans with custom direction. Free-text direction for the delivery.'],
                        'audio_effect' => ['type' => 'string', 'enum' => AudioEffects::keys(), 'description' => 'On plans with sound environments. A sound environment for the whole recording.'],
                        'dialogue' => [
                            'type' => 'object',
                            'description' => 'On plans with dialogue. Several voices in one recording.',
                            'required' => ['speakers'],
                            'properties' => [
                                'speakers' => [
                                    'type' => 'array', 'minItems' => 2, 'maxItems' => 6,
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['name', 'voice_id'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'maxLength' => 50, 'description' => 'The name that starts this speaker\'s lines in the text.'],
                                            'voice_id' => ['type' => 'string', 'description' => 'A studio voice of the text\'s language.'],
                                            'dialect' => ['type' => 'string', 'description' => 'This speaker\'s own dialect or accent.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'voice_settings' => [
                            'type' => 'object',
                            'properties' => [
                                'speed' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 2, 'default' => 1],
                                'pitch' => ['type' => 'number', 'minimum' => -10, 'maximum' => 10, 'default' => 0],
                            ],
                        ],
                        'translate' => ['type' => 'boolean', 'default' => false, 'description' => 'Translate the text into language_code before reading it.'],
                        'title' => ['type' => 'string', 'maxLength' => 255, 'description' => 'A label for the history item.'],
                        'output_format' => ['type' => 'string', 'enum' => ApiSpeechService::OUTPUT_FORMATS, 'description' => 'Same as the output_format query parameter.'],
                    ],
                ],
                'Voice' => [
                    'type' => 'object',
                    'properties' => [
                        'voice_id' => ['type' => 'string', 'examples' => ['voice_12']],
                        'name' => ['type' => 'string'],
                        'name_ar' => ['type' => 'string'],
                        'category' => ['type' => 'string', 'enum' => ['premade', 'cloned']],
                        'language' => $nullableString,
                        'gender' => $nullableString,
                        'accent' => $nullableString,
                        'model_id' => ['type' => 'string', 'enum' => ApiModels::ids()],
                        'featured' => ['type' => 'boolean'],
                        'available_on_plan' => ['type' => 'boolean'],
                        'preview_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                        'avatar_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    ],
                ],
                'Option' => [
                    'type' => 'object',
                    'properties' => ['value' => ['type' => 'string'], 'name' => ['type' => 'string'], 'name_ar' => ['type' => 'string']],
                ],
                'VoiceOptions' => [
                    'type' => 'object',
                    'properties' => [
                        'model_id' => ['type' => 'string'],
                        'dialects' => [
                            'type' => 'object',
                            'properties' => [
                                'ar' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']],
                                'en' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']],
                            ],
                        ],
                        'styles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']],
                        'emotions' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']],
                        'defaults' => ['type' => 'object'],
                        'voice_settings' => ['type' => 'object'],
                        'audio_effects' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Option']],
                        'dialogue' => ['type' => 'object', 'properties' => ['min_speakers' => ['type' => 'integer'], 'max_speakers' => ['type' => 'integer']]],
                        'output_formats' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'features' => ['$ref' => '#/components/schemas/PlanFeatures'],
                    ],
                ],
                'PlanFeatures' => [
                    'type' => 'object',
                    'description' => 'The options only some plans include, and whether this account has them.',
                    'properties' => [
                        'style_prompt' => ['type' => 'boolean'],
                        'audio_effect' => ['type' => 'boolean'],
                        'dialogue' => ['type' => 'boolean'],
                        'cloned_voices' => ['type' => 'boolean'],
                    ],
                ],
                'Model' => [
                    'type' => 'object',
                    'properties' => [
                        'model_id' => ['type' => 'string', 'enum' => ApiModels::ids()],
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'languages' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'available_on_plan' => ['type' => 'boolean'],
                        'max_characters_per_request' => ['type' => 'integer', 'description' => 'Waiting for the file.'],
                        'max_characters_per_async_request' => ['type' => 'integer', 'description' => 'In the background (/async).'],
                        'supports' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
                    ],
                ],
                'HistoryItem' => [
                    'type' => 'object',
                    'properties' => [
                        'history_item_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'enum' => ['queued', 'processing', 'completed', 'failed']],
                        'request_source' => ['type' => 'string', 'enum' => ['api', 'app']],
                        'api_key_id' => ['type' => ['integer', 'null']],
                        'voice_id' => $nullableString,
                        'voice_name' => $nullableString,
                        'model_id' => ['type' => 'string'],
                        'language_code' => ['type' => 'string'],
                        'dialect' => $nullableString,
                        'style' => $nullableString,
                        'emotion' => $nullableString,
                        'voice_settings' => ['type' => 'object', 'properties' => ['speed' => ['type' => 'number'], 'pitch' => ['type' => 'number']]],
                        'audio_effect' => $nullableString,
                        'dialogue' => ['type' => 'boolean', 'description' => 'Whether several voices speak in it.'],
                        'title' => $nullableString,
                        'text' => ['type' => 'string'],
                        'character_count' => ['type' => 'integer'],
                        'credits_used' => ['type' => 'integer', 'description' => 'Final once completed; the reserved estimate while it runs; 0 when it failed.'],
                        'duration_seconds' => ['type' => ['integer', 'null']],
                        'audio_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'description' => 'Download with your API key.'],
                        'error' => ['type' => ['object', 'null'], 'properties' => ['message' => ['type' => 'string']]],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'created_at_unix' => ['type' => 'integer'],
                    ],
                ],
                'Subscription' => [
                    'type' => 'object',
                    'properties' => [
                        'plan' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'models' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ApiModels::ids()]],
                                'features' => ['$ref' => '#/components/schemas/PlanFeatures'],
                                'commercial_use_allowed' => ['type' => 'boolean'],
                                'processing_priority' => ['type' => 'string'],
                            ],
                        ],
                        'status' => ['type' => 'string', 'description' => '`active`, or `past_due` when the latest renewal payment failed: the plan stays on until `current_period_end`, and moves to the free plan then unless the payment goes through.'],
                        'trial' => ['type' => 'boolean'],
                        'current_period_end' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When the paid period ends. While the status is `past_due`, when the plan ends unless the payment goes through.'],
                        'billing_mode' => ['type' => 'string', 'enum' => ['monthly', 'pack']],
                        'credits' => [
                            'type' => 'object',
                            'properties' => [
                                'used' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'remaining' => ['type' => 'integer'],
                                'gift' => ['type' => 'integer'],
                                'credits_per_second' => ['type' => 'integer'],
                                'overage_allowed' => ['type' => 'boolean', 'const' => false],
                                'period' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'The day the current credit period started: the plan\'s own day each month (the 1st on the free plan).'],
                                'period_starts_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'resets_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When the credits start again: a month after the period started, or when the plan renews. While the status is `past_due`, they start again once the renewal is paid, and this is when the plan ends if it is not.'],
                                'resets_at_unix' => ['type' => ['integer', 'null']],
                                'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ],
                        'voice_seconds' => ['type' => 'object'],
                        'requests' => [
                            'type' => 'object',
                            'properties' => [
                                'used_this_month' => ['type' => 'integer'],
                                'monthly_limit' => ['type' => ['integer', 'null']],
                                'daily_limit' => ['type' => ['integer', 'null']],
                                'used_today' => ['type' => ['integer', 'null']],
                                'daily_resets_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the daily count starts again.'],
                            ],
                        ],
                        'limits' => [
                            'type' => 'object',
                            'description' => 'For the whole account (all its keys together), read from the plan on every request.',
                            'properties' => [
                                'max_characters_per_request' => ['type' => 'integer', 'description' => 'Waiting for the file.'],
                                'max_characters_per_async_request' => ['type' => 'integer', 'description' => 'In the background (/async).'],
                                'max_characters_per_dialogue' => ['type' => 'integer'],
                                'requests_per_minute' => ['type' => 'integer', 'description' => 'Generation requests.'],
                                'other_requests_per_minute' => ['type' => 'integer', 'description' => 'Status checks, voices, history and account.'],
                                'concurrent_generations' => ['type' => 'integer', 'description' => 'Waiting for the file, at the same time.'],
                                'background_generations' => ['type' => 'integer', 'description' => 'Waiting or running in the background.'],
                                'output_formats' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                        'api_key' => ['type' => 'object'],
                    ],
                ],
                'Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'required' => ['type', 'code', 'message', 'request_id'],
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => [
                                    ApiError::TYPE_AUTHENTICATION, ApiError::TYPE_PERMISSION, ApiError::TYPE_INVALID_REQUEST,
                                    ApiError::TYPE_QUOTA, ApiError::TYPE_RATE_LIMIT, ApiError::TYPE_API,
                                ]],
                                'code' => ['type' => 'string', 'examples' => ['quota_exceeded']],
                                'message' => ['type' => 'string'],
                                'param' => $nullableString,
                                'request_id' => ['type' => 'string'],
                                'required_feature' => ['type' => 'string', 'description' => 'With feature_not_in_plan: the option asked for.'],
                                'available_on_plans' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'With feature_not_in_plan: slugs of the plans that include it.'],
                                'upgrade_url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function header(string $type, string $description): array
    {
        return ['description' => $description, 'schema' => ['type' => $type]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function json(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, mixed>
     */
    private static function errorResponses(array $codes): array
    {
        $descriptions = [
            '400' => 'The body is not valid JSON, or the Idempotency-Key is too long.',
            '401' => 'Missing or invalid API key.',
            '402' => 'Not enough credits on the plan or on the key\'s monthly limit.',
            '403' => 'Your plan or key does not allow this: the API is not in your plan or starts after the first payment, the key lacks a permission, or an option is not in your plan (feature_not_in_plan, with the plans that include it).',
            '404' => 'Not found.',
            '409' => 'Not possible yet: the item is still being made, or a request with the same Idempotency-Key is still running.',
            '410' => 'The audio is no longer stored.',
            '422' => 'A parameter is not valid, or the Idempotency-Key was used for a different request.',
            '429' => 'Rate, concurrency, background-queue or daily limit reached, for the whole account; see Retry-After.',
            '502' => 'The generation failed; no credits were charged.',
            '503' => 'Briefly unavailable: an option (a sound environment or an audio format), the background queue (queue_unavailable, nothing charged), the service for everyone (server_busy), today\'s speech capacity (capacity_reached), or the whole API while paused (api_paused). See Retry-After.',
        ];

        $responses = [];
        foreach ($codes as $code) {
            $responses[$code] = [
                'description' => $descriptions[$code] ?? 'Error.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }

        return $responses;
    }
}
