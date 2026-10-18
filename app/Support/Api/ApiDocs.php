<?php

namespace App\Support\Api;

use App\Models\Plan;
use App\Services\Api\ApiIdempotency;
use App\Services\Api\ApiSpeechService;
use App\Support\AudioEffects;
use App\Support\Credits;
use App\Support\PlanUsageGuard;

/**
 * The developer documentation's reference: every endpoint with its
 * parameters, request examples in each language and a sample answer. Text
 * comes from lang/{en,ar}/developers.php; examples follow the page's
 * language (an Arabic voice and text on the Arabic page, English on the
 * English page), so each page reads in one language.
 */
final class ApiDocs
{
    /**
     * @param  array{voice_id:string, name:string, name_ar?:string, language:string, gender?:?string}  $voice  the voice of the examples
     * @param  array{voice_id:string, name:string}|null  $partner  a second voice of the same language, for the dialogue example
     * @param  array{voice_id:string, body:array<string, mixed>}|null  $hero  the request at the top of the page (developer_docs_samples.php)
     * @return array<string, mixed>
     */
    public static function build(string $locale, string $baseUrl, array $voice, ?array $partner = null, ?array $hero = null): array
    {
        $arabic = $locale === 'ar';
        // Options no plan includes are left out of every example and list.
        $offered = ApiFeatureGate::offered();
        $example = $arabic
            ? ['text' => 'أهلين! كيفك اليوم؟ أنا هون لساعدك.', 'dialect' => 'ar-LB', 'language' => 'ar', 'title' => 'ترحيب المساعد']
            : ['text' => 'Hi there! How can I help you today?', 'dialect' => 'en-GB', 'language' => 'en', 'title' => 'Assistant greeting'];
        $voiceId = $voice['voice_id'];
        // Every example reads the same words as the request at the top.
        if ($hero !== null) {
            $example['text'] = (string) $hero['body']['text'];
            $example['dialect'] = (string) ($hero['body']['dialect'] ?? $example['dialect']);
        }
        $historyId = 4821;
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | ($arabic ? JSON_UNESCAPED_UNICODE : 0);
        $json = static fn (array $value): string => (string) preg_replace_callback(
            '/^((?: {4})+)/m',
            static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[1]), 4)),
            (string) json_encode($value, $jsonFlags),
        );

        $ttsBody = [
            'text' => $example['text'],
            'dialect' => $example['dialect'],
            'style' => 'assistant',
            'emotion' => 'warm',
            'voice_settings' => ['speed' => 1.0],
        ];

        $voiceExample = [
            'voice_id' => $voiceId,
            'name' => $voice['name'],
            'name_ar' => ($voice['name_ar'] ?? '') !== '' ? $voice['name_ar'] : $voice['name'],
            'category' => 'premade',
            'language' => $voice['language'],
            'gender' => $voice['gender'] ?? 'female',
            'accent' => null,
            'model_id' => ApiModels::PREMIUM,
            'featured' => true,
            'available_on_plan' => true,
            'preview_url' => url('/media/voice-previews/'.$voiceId.'.mp3'),
            'avatar_url' => url('/images/speakers/'.strtolower($voice['language'].'-'.$voice['name']).'.webp'),
        ];

        $historyItem = [
            'history_item_id' => $historyId,
            'status' => 'completed',
            'request_source' => 'api',
            'api_key_id' => 7,
            'voice_id' => $voiceId,
            'voice_name' => $voice['name'],
            'model_id' => ApiModels::PREMIUM,
            'language_code' => $example['language'],
            'dialect' => $example['dialect'],
            'style' => 'assistant',
            'emotion' => 'warm',
            'voice_settings' => ['speed' => 1.0, 'pitch' => 0.0],
            'audio_effect' => null,
            'dialogue' => false,
            'title' => $example['title'],
            'text' => $example['text'],
            'character_count' => mb_strlen($example['text']),
            'credits_used' => 45,
            'duration_seconds' => 3,
            'audio_url' => $baseUrl.'/history/'.$historyId.'/audio',
            'error' => null,
            'created_at' => '2026-10-18T09:30:12+00:00',
            'created_at_unix' => 1792315812,
        ];
        if (! $offered[ApiFeatureGate::SOUND_ENVIRONMENTS]) {
            unset($historyItem['audio_effect']);
        }
        if (! $offered[ApiFeatureGate::DIALOGUE]) {
            unset($historyItem['dialogue']);
        }

        // The sample answers use a real plan with the API (the featured one, else
        // the cheapest), so editing that plan in the admin changes them too.
        $apiPlans = ApiFeatureGate::apiPlans();
        $samplePlan = $apiPlans->firstWhere('is_featured', true) ?? $apiPlans->first();
        $features = [];
        foreach (ApiFeatureGate::FIELDS as $feature => $field) {
            if ($offered[$feature]) {
                $features[$field] = $samplePlan === null || ApiFeatureGate::planOffers($samplePlan, $feature);
            }
        }
        $isPack = (bool) $samplePlan?->isPack();
        $creditLimit = $samplePlan !== null ? PlanUsageGuard::planChars($samplePlan) : 1500000;
        $creditsUsed = (int) round($creditLimit * 0.12);
        $monthlyRequests = $samplePlan !== null && ! $isPack ? PlanUsageGuard::planRequests($samplePlan) : 0;
        $dailyRequests = $samplePlan !== null ? PlanUsageGuard::dailyRequests($samplePlan) : 0;
        $rpm = ApiPlanAccess::requestsPerMinute($samplePlan);
        $maxChars = ApiFeatureGate::maxChars($samplePlan);
        $sampleFormats = $samplePlan !== null ? ApiSpeechService::formatsFor($samplePlan) : ['mp3'];

        $subscription = [
            'plan' => [
                'slug' => (string) ($samplePlan?->slug ?? 'creator'),
                'name' => $samplePlan !== null ? ($samplePlan->getLocalizedName('en') ?: (string) $samplePlan->name) : 'Creator',
                'models' => [ApiModels::PREMIUM],
                'features' => $features,
                'commercial_use_allowed' => true,
                'processing_priority' => (string) ($samplePlan?->processing_priority ?: 'standard'),
            ],
            'status' => 'active',
            'trial' => false,
            'current_period_end' => $isPack ? null : '2026-11-02T10:00:00+00:00',
            'billing_mode' => $isPack ? 'pack' : 'monthly',
            'credits' => [
                'used' => $creditsUsed,
                'limit' => $creditLimit,
                'remaining' => $creditLimit - $creditsUsed,
                'gift' => 0,
                'credits_per_second' => Credits::PER_SECOND,
                'overage_allowed' => false,
                'period' => $isPack ? null : '2026-10-14',
                'period_starts_at' => $isPack ? null : '2026-10-14T08:37:00+00:00',
                'resets_at' => $isPack ? null : '2026-11-14T08:37:00+00:00',
                'resets_at_unix' => $isPack ? null : 1794645420,
                'expires_at' => $isPack ? '2026-11-17T09:30:12+00:00' : null,
            ],
            'voice_seconds' => ['used' => Credits::secondsFor($creditsUsed), 'limit' => Credits::secondsFor($creditLimit)],
            'requests' => [
                'used_this_month' => 911,
                'monthly_limit' => $monthlyRequests > 0 ? $monthlyRequests : null,
                'daily_limit' => $dailyRequests > 0 ? $dailyRequests : null,
                'used_today' => $dailyRequests > 0 ? 12 : null,
                'daily_resets_at' => '2026-10-19T00:00:00+03:00',
            ],
            'limits' => [
                'max_characters_per_request' => $maxChars,
                'max_characters_per_async_request' => ApiFeatureGate::maxChars($samplePlan, true),
                'max_characters_per_dialogue' => ApiSpeechService::maxCharsFor($samplePlan, true, true),
                'requests_per_minute' => $rpm,
                'other_requests_per_minute' => ApiPlanAccess::readsPerMinute($samplePlan),
                'concurrent_generations' => ApiPlanAccess::concurrency($samplePlan),
                'background_generations' => ApiSpeechService::queueLimit($samplePlan),
                'output_formats' => $sampleFormats,
            ],
            'api_key' => [
                'api_key_id' => 7,
                'name' => $arabic ? 'المساعد الذكي' : 'Production assistant',
                'monthly_credit_limit' => (int) (round($creditLimit / 3 / 1000) * 1000) ?: null,
                'credits_used_this_month' => (int) round($creditsUsed * 0.54),
                'requests_this_month' => 640,
            ],
        ];
        if (! $offered[ApiFeatureGate::DIALOGUE]) {
            unset($subscription['limits']['max_characters_per_dialogue']);
        }

        $voiceOptions = [
            'model_id' => ApiModels::PREMIUM,
            'dialects' => [
                'ar' => [
                    ['value' => 'ar-EG', 'name' => 'Egyptian', 'name_ar' => 'مصري'],
                    ['value' => 'ar-LB', 'name' => 'Lebanese', 'name_ar' => 'لبناني'],
                ],
                'en' => [['value' => 'en-GB', 'name' => 'British', 'name_ar' => 'بريطاني']],
            ],
            'styles' => [['value' => 'assistant', 'name' => 'Assistant', 'name_ar' => 'مساعد']],
            'emotions' => [['value' => 'warm', 'name' => 'Warm', 'name_ar' => 'دافئ']],
            'defaults' => ['ar' => ['dialect' => 'ar-EG', 'style' => 'promo', 'emotion' => 'confident']],
            'voice_settings' => ['speed' => ['min' => 0.5, 'max' => 2.0, 'default' => 1.0], 'pitch' => ['min' => -10, 'max' => 10, 'default' => 0]],
            'audio_effects' => [
                ['value' => 'hall', 'name' => 'Concert hall', 'name_ar' => 'قاعة كبيرة'],
                ['value' => 'phone', 'name' => 'Phone call', 'name_ar' => 'مكالمة هاتفية'],
            ],
            'dialogue' => ['min_speakers' => 2, 'max_speakers' => 6, 'max_characters' => ApiSpeechService::maxCharsFor($samplePlan, true, true)],
            'output_formats' => $sampleFormats,
            'features' => $subscription['plan']['features'],
        ];
        if (! $offered[ApiFeatureGate::SOUND_ENVIRONMENTS]) {
            unset($voiceOptions['audio_effects']);
        }
        if (! $offered[ApiFeatureGate::DIALOGUE]) {
            unset($voiceOptions['dialogue']);
        }

        $audioResponse = static fn (int $credits): string => implode("\n", [
            'HTTP/1.1 200 OK',
            'Content-Type: audio/mpeg',
            'X-History-Item-Id: '.$historyId,
            'X-Character-Count: '.mb_strlen($example['text']),
            'X-Credits-Used: '.$credits,
            'X-Credits-Remaining: 1317660',
            'X-Audio-Duration-Seconds: 3',
            'X-Output-Format: mp3',
            'X-Request-Id: req_8f2kq0x7m3c9w1v5b6n4z2a1',
            'X-RateLimit-Limit: '.$rpm,
            'X-RateLimit-Remaining: '.max(0, $rpm - 1),
            '',
            '<audio/mpeg>',
        ]);

        // $feature: only the plans with that feature include it (see the plan features guide).
        $p = static fn (string $name, string $type, bool $required, string $descriptionKey, ?string $feature = null): array => [
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'description' => 'developers.docs.params.'.$descriptionKey,
            'gated' => $feature !== null,
            'feature' => $feature,
        ];
        $cloned = $offered[ApiFeatureGate::CLONED_VOICES];

        $endpoints = [
            [
                'id' => 'text-to-speech',
                'key' => 'tts',
                'method' => 'POST',
                'path' => '/v1/text-to-speech/{voice_id}',
                'scope' => ApiScopes::TEXT_TO_SPEECH,
                'path_params' => [$p('voice_id', 'string', true, $cloned ? 'voice_id' : 'voice_id_studio')],
                'header_params' => [$p(ApiIdempotency::HEADER, 'string', false, 'idempotency_key')],
                'query_params' => [$p('output_format', 'string', false, 'output_format')],
                'body_params' => [
                    $p('text', 'string', true, 'text'),
                    $p('model_id', 'string', false, 'model_id'),
                    $p('language_code', 'string', false, 'language_code'),
                    $p('dialect', 'string', false, 'dialect'),
                    $p('style', 'string', false, 'style'),
                    $p('emotion', 'string', false, 'emotion'),
                    $p('voice_settings.speed', 'number', false, 'speed'),
                    $p('voice_settings.pitch', 'number', false, 'pitch'),
                    $p('style_prompt', 'string', false, 'style_prompt', ApiFeatureGate::CUSTOM_DIRECTION),
                    $p('audio_effect', 'string', false, 'audio_effect', ApiFeatureGate::SOUND_ENVIRONMENTS),
                    $p('dialogue.speakers', 'array', false, 'dialogue', ApiFeatureGate::DIALOGUE),
                    $p('translate', 'boolean', false, 'translate'),
                    $p('title', 'string', false, 'title'),
                ],
                'headers' => ['X-History-Item-Id', 'X-Character-Count', 'X-Credits-Used', 'X-Credits-Remaining', 'X-Audio-Duration-Seconds', 'X-Output-Format', 'Idempotent-Replayed', 'X-Request-Id'],
                'request' => ['POST', $baseUrl.'/text-to-speech/'.$voiceId.'?output_format=mp3', $ttsBody, 'speech.mp3'],
                'response' => ['http', $audioResponse(45)],
            ],
            [
                'id' => 'text-to-speech-async',
                'key' => 'tts_async',
                'method' => 'POST',
                'path' => '/v1/text-to-speech/{voice_id}/async',
                'scope' => ApiScopes::TEXT_TO_SPEECH,
                'path_params' => [$p('voice_id', 'string', true, $cloned ? 'voice_id' : 'voice_id_studio')],
                'header_params' => [$p(ApiIdempotency::HEADER, 'string', false, 'idempotency_key')],
                'body_params' => [$p('text', 'string', true, 'text'), $p('title', 'string', false, 'title'), $p('*', 'string', false, 'same_body')],
                'request' => ['POST', $baseUrl.'/text-to-speech/'.$voiceId.'/async', ['text' => $example['text'], 'dialect' => $example['dialect'], 'title' => $example['title']], null],
                'response' => ['json', $json(['status' => 'queued', 'credits_used' => 45, 'duration_seconds' => null, 'audio_url' => null] + $historyItem)],
            ],
            [
                'id' => 'list-voices',
                'key' => 'voices_list',
                'summary' => $cloned ? 'summary' : 'summary_studio',
                'method' => 'GET',
                'path' => '/v1/voices',
                'scope' => ApiScopes::VOICES_READ,
                'query_params' => [
                    $p('language', 'string', false, 'filter_language'),
                    $p('model_id', 'string', false, 'filter_model'),
                    $p('gender', 'string', false, 'filter_gender'),
                    $p('category', 'string', false, $cloned ? 'filter_category' : 'filter_category_studio'),
                ],
                'request' => ['GET', $baseUrl.'/voices?language='.$example['language'].'&model_id='.ApiModels::PREMIUM, null, null],
                'response' => ['json', $json(['voices' => [$voiceExample], 'total_count' => 1])],
            ],
            [
                'id' => 'get-voice',
                'key' => 'voices_get',
                'method' => 'GET',
                'path' => '/v1/voices/{voice_id}',
                'scope' => ApiScopes::VOICES_READ,
                'path_params' => [$p('voice_id', 'string', true, $cloned ? 'voice_id' : 'voice_id_studio')],
                'request' => ['GET', $baseUrl.'/voices/'.$voiceId, null, null],
                'response' => ['json', $json($voiceExample)],
            ],
            [
                'id' => 'voice-options',
                'key' => 'options',
                'summary_replace' => ['fields' => self::listed('options_fields', $offered, $arabic)],
                'method' => 'GET',
                'path' => '/v1/voice-options',
                'scope' => ApiScopes::VOICES_READ,
                'request' => ['GET', $baseUrl.'/voice-options', null, null],
                'response' => ['json', $json($voiceOptions)],
            ],
            [
                'id' => 'list-models',
                'key' => 'models',
                'summary_replace' => ['options' => self::listed('models_options', $offered, $arabic)],
                'method' => 'GET',
                'path' => '/v1/models',
                'scope' => ApiScopes::VOICES_READ,
                'request' => ['GET', $baseUrl.'/models', null, null],
                'response' => ['json', $json(['models' => [[
                    'model_id' => ApiModels::PREMIUM,
                    'name' => ApiModels::NAME,
                    'languages' => ['ar', 'en'],
                    'available_on_plan' => true,
                    'max_characters_per_request' => $maxChars,
                    'max_characters_per_async_request' => ApiFeatureGate::maxChars($samplePlan, true),
                    'supports' => ['dialect' => true, 'style' => true, 'emotion' => true, 'voice_settings' => true, 'inline_tags' => true] + $subscription['plan']['features'],
                ]]])],
            ],
            [
                'id' => 'list-history',
                'key' => 'history_list',
                'method' => 'GET',
                'path' => '/v1/history',
                'scope' => ApiScopes::HISTORY_READ,
                'query_params' => [
                    $p('page_size', 'integer', false, 'page_size'),
                    $p('before', 'integer', false, 'before'),
                    $p('voice_id', 'string', false, 'history_voice'),
                    $p('source', 'string', false, 'source'),
                    $p('status', 'string', false, 'status'),
                ],
                'request' => ['GET', $baseUrl.'/history?page_size=20', null, null],
                'response' => ['json', $json(['history' => [$historyItem], 'has_more' => true, 'next_cursor' => $historyId])],
            ],
            [
                'id' => 'get-history-item',
                'key' => 'history_get',
                'method' => 'GET',
                'path' => '/v1/history/{history_item_id}',
                'scope' => ApiScopes::HISTORY_READ,
                'path_params' => [$p('history_item_id', 'integer', true, 'history_item_id')],
                'request' => ['GET', $baseUrl.'/history/'.$historyId, null, null],
                'response' => ['json', $json($historyItem)],
            ],
            [
                'id' => 'get-history-audio',
                'key' => 'history_audio',
                'method' => 'GET',
                'path' => '/v1/history/{history_item_id}/audio',
                'scope' => ApiScopes::HISTORY_READ,
                'path_params' => [$p('history_item_id', 'integer', true, 'history_item_id')],
                'query_params' => [$p('output_format', 'string', false, 'output_format')],
                'request' => ['GET', $baseUrl.'/history/'.$historyId.'/audio?output_format=mp3', null, 'speech.mp3'],
                'response' => ['http', "HTTP/1.1 200 OK\nContent-Type: audio/mpeg\nX-History-Item-Id: {$historyId}\nX-Output-Format: mp3\n\n<audio/mpeg>"],
            ],
            [
                'id' => 'delete-history-item',
                'key' => 'history_delete',
                'method' => 'DELETE',
                'path' => '/v1/history/{history_item_id}',
                'scope' => ApiScopes::HISTORY_WRITE,
                'path_params' => [$p('history_item_id', 'integer', true, 'history_item_id')],
                'request' => ['DELETE', $baseUrl.'/history/'.$historyId, null, null],
                'response' => ['json', $json(['history_item_id' => $historyId, 'deleted' => true])],
            ],
            [
                'id' => 'get-subscription',
                'key' => 'subscription',
                'method' => 'GET',
                'path' => '/v1/user/subscription',
                'scope' => ApiScopes::USER_READ,
                'request' => ['GET', $baseUrl.'/user/subscription', null, null],
                'response' => ['json', $json($subscription)],
            ],
            [
                'id' => 'get-user',
                'key' => 'user',
                'method' => 'GET',
                'path' => '/v1/user',
                'scope' => ApiScopes::USER_READ,
                'request' => ['GET', $baseUrl.'/user', null, null],
                'response' => ['json', $json([
                    'user_id' => 1024,
                    'name' => $arabic ? 'سارة' : 'Sara',
                    'email' => 'sara@example.com',
                    'created_at' => '2026-03-02T12:00:00+00:00',
                    'subscription' => ['plan' => $subscription['plan'], 'credits' => $subscription['credits']],
                ])],
            ],
        ];

        foreach ($endpoints as &$endpoint) {
            foreach (['header_params', 'path_params', 'query_params', 'body_params'] as $group) {
                if (isset($endpoint[$group])) {
                    $endpoint[$group] = array_values(array_filter($endpoint[$group], fn (array $param): bool => $param['feature'] === null || $offered[$param['feature']]));
                }
            }
            [$method, $url, $body, $saveAs] = $endpoint['request'];
            $endpoint['samples'] = self::highlighted(ApiCodeSamples::all($method, $url, $body, $saveAs));
            [$grammar, $answer] = $endpoint['response'];
            $endpoint['response_html'] = CodeHighlighter::html($answer, $grammar);
        }
        unset($endpoint);

        $errorBody = [
            'error' => [
                'type' => ApiError::TYPE_QUOTA,
                'code' => 'quota_exceeded',
                'message' => 'Your monthly credits are used up. Please upgrade your plan to continue.',
                'param' => null,
                'credits_needed' => 450,
                'request_id' => 'req_8f2kq0x7m3c9w1v5b6n4z2a1',
            ],
        ];

        // Dialogue: two speakers of the page's language, each line starting with a speaker's name.
        $partner ??= ['voice_id' => $arabic ? 'voice_14' : 'voice_31', 'name' => $arabic ? 'Omar' : 'James'];
        $speakers = $arabic ? ['سارة', 'عمر'] : ['Sara', 'Omar'];
        $dialogueBody = [
            'text' => $arabic
                ? "{$speakers[0]}: أهلا فيك من جديد عالبرنامج!\n{$speakers[1]}: شكرًا، مبسوط كتير إني هون."
                : "{$speakers[0]}: Welcome back to the show!\n{$speakers[1]}: Thanks, it's great to be here.",
            'dialect' => $example['dialect'],
            'dialogue' => ['speakers' => [
                ['name' => $speakers[0], 'voice_id' => $voiceId],
                ['name' => $speakers[1], 'voice_id' => (string) $partner['voice_id']],
            ]],
        ];

        // The refusal a plan without an option gets, with the plans that include it today:
        // dialogue, or another option some plan has, or else an audio format.
        $shown = collect([ApiFeatureGate::DIALOGUE, ApiFeatureGate::SOUND_ENVIRONMENTS, ApiFeatureGate::CLONED_VOICES, ApiFeatureGate::CUSTOM_DIRECTION])
            ->first(fn (string $feature): bool => $offered[$feature]);
        $refusal = match ($shown) {
            null => ApiFeatureGate::formatRefusal('wav'),
            ApiFeatureGate::CLONED_VOICES => ApiFeatureGate::refusal($shown, 'voice_id'),
            default => ApiFeatureGate::refusal($shown, ApiFeatureGate::FIELDS[$shown]),
        };
        $refusalBody = ['error' => [
            'type' => $refusal->type,
            'code' => $refusal->errorCode,
            'message' => $refusal->getMessage(),
            'param' => $refusal->param,
        ] + $refusal->extra + ['request_id' => 'req_3c9w1v5b6n4z2a1f8k0x7m2q']];

        // Sound environments by group, named in the page's language.
        $environments = [];
        foreach (AudioEffects::ALL as $value => $row) {
            $environments[$row[1]]['name'] ??= AudioEffects::GROUPS[$row[1]][$arabic ? 'ar' : 'en'] ?? $row[1];
            $environments[$row[1]]['items'][] = ['value' => $value, 'name' => $arabic ? $row[3] : $row[2], 'description' => $arabic ? $row[5] : $row[4]];
        }

        return [
            'offered' => $offered,
            'languages' => ApiCodeSamples::LANGUAGES,
            'endpoints' => $endpoints,
            // The request at the top, exactly as its recording was made.
            'hero' => CodeHighlighter::html(ApiCodeSamples::all('POST', $baseUrl.'/text-to-speech/'.($hero['voice_id'] ?? $voiceId).'?output_format=mp3', $hero['body'] ?? $ttsBody, 'speech.mp3')['curl'], 'bash'),
            'dialogue' => self::highlighted(ApiCodeSamples::all('POST', $baseUrl.'/text-to-speech/'.$voiceId, $dialogueBody, 'dialogue.mp3')),
            'environment_sample' => self::highlighted(ApiCodeSamples::all('POST', $baseUrl.'/text-to-speech/'.$voiceId, ['text' => $example['text'], 'dialect' => $example['dialect'], 'audio_effect' => 'phone'], 'call.mp3')),
            'environments' => array_values($environments),
            'feature_error' => CodeHighlighter::html("HTTP/1.1 403 Forbidden\nContent-Type: application/json\n\n".$json($refusalBody), 'http'),
            'idempotency' => CodeHighlighter::html(
                "curl -X POST \"{$baseUrl}/text-to-speech/{$voiceId}\" \\\n  -H \"X-Listen-Api-Key: \$LISTEN_API_KEY\" \\\n  -H \"Idempotency-Key: 7f3e9c2a-58b1-4d0e-9a61-2c4f8b7d1e05\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"text\": \"".($arabic ? $example['text'] : $example['text'])."\"}' \\\n  --output speech.mp3\n\n# Sent again with the same key: the first result, not charged again.\n# HTTP/1.1 200 OK\n# Idempotent-Replayed: true",
                'bash',
            ),
            'quickstart' => self::highlighted(ApiCodeSamples::all('POST', $baseUrl.'/text-to-speech/'.$voiceId.'?output_format=mp3', $ttsBody, 'speech.mp3')),
            'env' => CodeHighlighter::html('export LISTEN_API_KEY="lsk_live_..."', 'bash'),
            'auth' => CodeHighlighter::html(
                "curl \"{$baseUrl}/user/subscription\" \\\n  -H \"X-Listen-Api-Key: \$LISTEN_API_KEY\"\n\n# or\ncurl \"{$baseUrl}/user/subscription\" \\\n  -H \"Authorization: Bearer \$LISTEN_API_KEY\"",
                'bash',
            ),
            'error_example' => CodeHighlighter::html("HTTP/1.1 402 Payment Required\nContent-Type: application/json\nX-Request-Id: req_8f2kq0x7m3c9w1v5b6n4z2a1\n\n".$json($errorBody), 'http'),
            'max_text' => ApiSpeechService::MAX_TEXT,
            // The longest texts some plan allows (the API's own ceilings when there is none).
            'long_limits' => [
                'sync' => number_format((int) ($apiPlans->max(fn (Plan $plan): int => ApiSpeechService::maxCharsFor($plan)) ?? ApiSpeechService::SYNC_MAX_TEXT)),
                'async' => number_format((int) ($apiPlans->max(fn (Plan $plan): int => ApiSpeechService::maxCharsFor($plan, true)) ?? ApiSpeechService::ASYNC_MAX_TEXT)),
                'dialogue' => number_format((int) ($apiPlans->max(fn (Plan $plan): int => ApiSpeechService::maxCharsFor($plan, true, true)) ?? ApiSpeechService::DIALOGUE_MAX_TEXT)),
            ],
            // The formats besides mp3 some plan includes, as code, in the page's language.
            'formats_offered' => self::series(array_map(fn (string $format): string => '`'.$format.'`', ApiSpeechService::formatsOffered()), $arabic),
        ];
    }

    /**
     * @param  array<string, string>  $samples
     * @return array<string, string>
     */
    private static function highlighted(array $samples): array
    {
        $html = [];
        foreach ($samples as $language => $code) {
            $html[$language] = CodeHighlighter::html($code, ApiCodeSamples::GRAMMAR[$language]);
        }

        return $html;
    }

    /**
     * The items of a list in lang/{en,ar}/developers.php (docs.lists), joined
     * as a sentence in the page's language. Items with a feature as their key
     * are left out when no plan includes that feature.
     *
     * @param  array<string, bool>  $offered  ApiFeatureGate::offered()
     */
    public static function listed(string $list, array $offered, bool $arabic): string
    {
        $items = [];
        foreach ((array) __('developers.docs.lists.'.$list) as $feature => $item) {
            if (is_int($feature) || ($offered[$feature] ?? false)) {
                $items[] = (string) $item;
            }
        }

        return self::series($items, $arabic);
    }

    /**
     * "a, b and c" in English; "أ، وب، وج" (or "أ وب" for two) in Arabic.
     *
     * @param  array<int, string>  $items
     */
    public static function series(array $items, bool $arabic): string
    {
        $items = array_values($items);
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        if ($arabic) {
            return count($items) === 2 ? $items[0].' و'.$items[1] : $items[0].'، و'.implode('، و', array_slice($items, 1));
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * Translated text with `code` spans: everything is escaped, and text
     * between backticks becomes inline code (left to right in both languages).
     * $html values are trusted markup put in place of :placeholders; $replace
     * values are plain text (with backticks) put in place before escaping.
     *
     * @param  array<string, string>  $html
     * @param  array<string, string>  $replace
     */
    public static function rich(string $key, array $html = [], array $replace = []): string
    {
        $tokens = [];
        foreach ($html as $name => $markup) {
            $tokens[$name] = '__HTML_'.strtoupper($name).'__';
        }
        $text = self::richText((string) __($key, $tokens + $replace));

        foreach ($html as $name => $markup) {
            $text = str_replace($tokens[$name], $markup, $text);
        }

        return $text;
    }

    public static function richText(string $text): string
    {
        return (string) preg_replace('/`([^`]+)`/', '<code dir="ltr">$1</code>', e($text));
    }
}
