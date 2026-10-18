<?php

namespace App\Support\Api;

use App\Services\Api\ApiSpeechService;

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
     * @param  array{voice_id:string, name:string, language:string, gender?:?string}  $voice
     * @return array<string, mixed>
     */
    public static function build(string $locale, string $baseUrl, array $voice): array
    {
        $arabic = $locale === 'ar';
        $example = $arabic
            ? ['text' => 'أهلين! كيفك اليوم؟ أنا هون لساعدك.', 'dialect' => 'ar-LB', 'language' => 'ar', 'title' => 'ترحيب المساعد']
            : ['text' => 'Hi there! How can I help you today?', 'dialect' => 'en-GB', 'language' => 'en', 'title' => 'Assistant greeting'];
        $voiceId = $voice['voice_id'];
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
            'name_ar' => $voice['name'] === 'Sumaya' ? 'سمية' : $voice['name'],
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

        $subscription = [
            'plan' => [
                'slug' => 'creator',
                'name' => 'Creator',
                'models' => [ApiModels::STANDARD, ApiModels::PREMIUM],
                'commercial_use_allowed' => true,
                'processing_priority' => 'high',
            ],
            'status' => 'active',
            'trial' => false,
            'current_period_end' => '2026-11-02T10:00:00+00:00',
            'billing_mode' => 'monthly',
            'credits' => [
                'used' => 182340,
                'limit' => 1500000,
                'remaining' => 1317660,
                'gift' => 0,
                'credits_per_second' => 15,
                'overage_allowed' => false,
                'period' => '2026-10',
                'resets_at' => '2026-11-01T00:00:00+00:00',
                'resets_at_unix' => 1793491200,
                'expires_at' => null,
            ],
            'voice_seconds' => ['used' => 12156, 'limit' => 100000],
            'requests' => ['used_this_month' => 911, 'monthly_limit' => null, 'daily_limit' => null, 'used_today' => null],
            'limits' => [
                'max_characters_per_request' => 20000,
                'requests_per_minute' => 120,
                'concurrent_generations' => 5,
                'output_formats' => ['mp3', 'wav'],
            ],
            'api_key' => [
                'api_key_id' => 7,
                'name' => $arabic ? 'المساعد الذكي' : 'Production assistant',
                'monthly_credit_limit' => 500000,
                'credits_used_this_month' => 98210,
                'requests_this_month' => 640,
            ],
        ];

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
            'X-RateLimit-Limit: 120',
            'X-RateLimit-Remaining: 119',
            '',
            '<audio/mpeg>',
        ]);

        $p = static fn (string $name, string $type, bool $required, string $descriptionKey, bool $premium = false): array => [
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'description' => 'developers.docs.params.'.$descriptionKey,
            'premium' => $premium,
        ];

        $endpoints = [
            [
                'id' => 'text-to-speech',
                'key' => 'tts',
                'method' => 'POST',
                'path' => '/v1/text-to-speech/{voice_id}',
                'scope' => ApiScopes::TEXT_TO_SPEECH,
                'path_params' => [$p('voice_id', 'string', true, 'voice_id')],
                'query_params' => [$p('output_format', 'string', false, 'output_format')],
                'body_params' => [
                    $p('text', 'string', true, 'text'),
                    $p('model_id', 'string', false, 'model_id'),
                    $p('language_code', 'string', false, 'language_code'),
                    $p('dialect', 'string', false, 'dialect', true),
                    $p('style', 'string', false, 'style', true),
                    $p('emotion', 'string', false, 'emotion', true),
                    $p('style_prompt', 'string', false, 'style_prompt', true),
                    $p('voice_settings.speed', 'number', false, 'speed', true),
                    $p('voice_settings.pitch', 'number', false, 'pitch', true),
                    $p('translate', 'boolean', false, 'translate'),
                    $p('title', 'string', false, 'title'),
                ],
                'headers' => ['X-History-Item-Id', 'X-Character-Count', 'X-Credits-Used', 'X-Credits-Remaining', 'X-Audio-Duration-Seconds', 'X-Output-Format', 'X-Request-Id'],
                'request' => ['POST', $baseUrl.'/text-to-speech/'.$voiceId.'?output_format=mp3', $ttsBody, 'speech.mp3'],
                'response' => ['http', $audioResponse(45)],
            ],
            [
                'id' => 'text-to-speech-async',
                'key' => 'tts_async',
                'method' => 'POST',
                'path' => '/v1/text-to-speech/{voice_id}/async',
                'scope' => ApiScopes::TEXT_TO_SPEECH,
                'path_params' => [$p('voice_id', 'string', true, 'voice_id')],
                'body_params' => [
                    $p('text', 'string', true, 'text'),
                    $p('title', 'string', false, 'title'),
                ],
                'request' => ['POST', $baseUrl.'/text-to-speech/'.$voiceId.'/async', ['text' => $example['text'], 'dialect' => $example['dialect'], 'title' => $example['title']], null],
                'response' => ['json', $json(['status' => 'queued', 'credits_used' => 45, 'duration_seconds' => null, 'audio_url' => null] + $historyItem)],
            ],
            [
                'id' => 'list-voices',
                'key' => 'voices_list',
                'method' => 'GET',
                'path' => '/v1/voices',
                'scope' => ApiScopes::VOICES_READ,
                'query_params' => [
                    $p('language', 'string', false, 'filter_language'),
                    $p('model_id', 'string', false, 'filter_model'),
                    $p('gender', 'string', false, 'filter_gender'),
                    $p('category', 'string', false, 'filter_category'),
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
                'path_params' => [$p('voice_id', 'string', true, 'voice_id')],
                'request' => ['GET', $baseUrl.'/voices/'.$voiceId, null, null],
                'response' => ['json', $json($voiceExample)],
            ],
            [
                'id' => 'voice-options',
                'key' => 'options',
                'method' => 'GET',
                'path' => '/v1/voice-options',
                'scope' => ApiScopes::VOICES_READ,
                'request' => ['GET', $baseUrl.'/voice-options', null, null],
                'response' => ['json', $json([
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
                    'output_formats' => ['mp3', 'wav'],
                ])],
            ],
            [
                'id' => 'list-models',
                'key' => 'models',
                'method' => 'GET',
                'path' => '/v1/models',
                'scope' => ApiScopes::VOICES_READ,
                'request' => ['GET', $baseUrl.'/models', null, null],
                'response' => ['json', $json(['models' => [
                    ['model_id' => ApiModels::STANDARD, 'name' => 'Listen Standard', 'languages' => ['ar', 'en'], 'available_on_plan' => true, 'max_characters_per_request' => 20000],
                    ['model_id' => ApiModels::PREMIUM, 'name' => 'Listen Premium', 'languages' => ['ar', 'en'], 'available_on_plan' => true, 'max_characters_per_request' => 20000],
                ]])],
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

        return [
            'languages' => ApiCodeSamples::LANGUAGES,
            'endpoints' => $endpoints,
            'quickstart' => self::highlighted(ApiCodeSamples::all('POST', $baseUrl.'/text-to-speech/'.$voiceId.'?output_format=mp3', $ttsBody, 'speech.mp3')),
            'env' => CodeHighlighter::html('export LISTEN_API_KEY="lsk_live_..."', 'bash'),
            'auth' => CodeHighlighter::html(
                "curl \"{$baseUrl}/user/subscription\" \\\n  -H \"X-Listen-Api-Key: \$LISTEN_API_KEY\"\n\n# or\ncurl \"{$baseUrl}/user/subscription\" \\\n  -H \"Authorization: Bearer \$LISTEN_API_KEY\"",
                'bash',
            ),
            'error_example' => CodeHighlighter::html("HTTP/1.1 402 Payment Required\nContent-Type: application/json\nX-Request-Id: req_8f2kq0x7m3c9w1v5b6n4z2a1\n\n".$json($errorBody), 'http'),
            'max_text' => ApiSpeechService::MAX_TEXT,
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
     * Translated text with `code` spans: everything is escaped, and text
     * between backticks becomes inline code (left to right in both languages).
     * $html values are trusted markup put in place of :placeholders.
     *
     * @param  array<string, string>  $html
     */
    public static function rich(string $key, array $html = []): string
    {
        $tokens = [];
        foreach ($html as $name => $markup) {
            $tokens[$name] = '__HTML_'.strtoupper($name).'__';
        }
        $text = self::richText((string) __($key, $tokens));

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
