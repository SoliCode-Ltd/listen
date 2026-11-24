<?php

namespace App\Support\Tashkeel;

use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The automatic pronunciation step on the site: just before a premium voice
 * reads an Arabic text, the few words it could say as the wrong word are
 * marked and the voice is told what each means (WordReadings). The member's
 * text, their history and what they are charged stay as they wrote them.
 *
 * Switched in Admin → TTS Settings, off until then: off, on for the listed
 * test accounts only, or on for everyone.
 *
 * Only where it has been heard to help, and nowhere else until that is
 * measured too (fits): one premium voice (no dialogue, no cloned or designed
 * voice), set to Fusha or to no dialect, a text read in one piece and without
 * sound or delivery tags. The studio, the extension and the developer API;
 * not the demo.
 *
 * A text's result is kept for 90 days under a fingerprint of the text (not
 * the text itself: the few marked words and what they mean are what is kept),
 * so another take of the same text waits for nothing and gets the same words.
 * The text model is asked three times at once, each with its own fixed seed;
 * when fewer than two answers come back in time, or anything fails, the text
 * is read as written.
 */
final class AutoReadings
{
    public const SETTING = 'tts_auto_readings';

    public const MEMBERS_SETTING = 'tts_auto_readings_members';

    public const OFF = '0';

    public const TEST = 'test';

    public const ON = '1';

    /** Part of the fingerprint: a change of the prompt or of the rules starts the kept results afresh. */
    private const VERSION = 'v1';

    private const DAYS = 90;

    /** Sound and delivery tags (<laugh>, [calm]): a text with one is left as it is. */
    private const TAG = '/(<[^<>\n]{1,40}>|\[[^\[\]\n]{1,40}\])/u';

    public static function mode(): string
    {
        $mode = (string) SiteSetting::get(self::SETTING, self::OFF);

        return in_array($mode, [self::TEST, self::ON], true) ? $mode : self::OFF;
    }

    /**
     * The test accounts, as member ids.
     *
     * @return list<int>
     */
    public static function testMembers(): array
    {
        preg_match_all('/\d+/', (string) SiteSetting::get(self::MEMBERS_SETTING, ''), $found);

        return array_values(array_unique(array_map('intval', $found[0])));
    }

    /** Whether this member's voices go through the step: everyone when on, the listed accounts when testing. */
    public static function allowsMember(?int $userId): bool
    {
        return match (self::mode()) {
            self::ON => true,
            self::TEST => $userId !== null && in_array($userId, self::testMembers(), true),
            default => false,
        };
    }

    /**
     * The first scope: an Arabic text for a voice set to Fusha (or to no dialect), read in one
     * piece, without tags. The caller knows the rest (one premium voice, Arabic chosen).
     */
    public static function fits(string $text, ?string $dialect, int $maxChars): bool
    {
        $dialect = trim((string) $dialect);

        return ($dialect === '' || ArabicDialects::isPlainFusha($dialect))
            && mb_strlen($text) <= $maxChars
            && preg_match('/\p{Arabic}/u', $text) === 1
            && preg_match(self::TAG, $text) !== 1;
    }

    /**
     * The text as the voice should read it, and what to tell the voice.
     *
     * @param  list<string>  $keep  the member's own spellings in the text, left alone
     * @return array{text: string, direction: string, info: array{words: int, written_in: ?string, reused: bool, ms: int, answers: int}}
     */
    public static function for(string $text, array $keep = []): array
    {
        $started = microtime(true);
        $model = (string) config('services.gemini_tts.readings_model', 'gemini-3.8-flash');
        $key = 'listen:auto-readings:'.self::VERSION.':'.sha1($model.'|'.$text.'|keep:'.json_encode(array_values($keep), JSON_UNESCAPED_UNICODE));
        $info = ['words' => 0, 'written_in' => null, 'reused' => false, 'ms' => 0, 'answers' => 0];

        $kept = Cache::get($key);
        if (is_array($kept) && is_array($kept['readings'] ?? null)) {
            $readings = $kept['readings'];
            $info = ['written_in' => $kept['written_in'] ?? null, 'reused' => true] + $info;
        } else {
            $answers = self::ask(WordReadings::prompt($text), $model);
            $info['answers'] = count($answers);
            // One answer alone is not enough to change what a member's voice says.
            if (count($answers) < 2) {
                return ['text' => $text, 'direction' => '', 'info' => ['ms' => self::since($started)] + $info];
            }
            $readings = WordReadings::agreed($text, $answers, $keep);
            $info['written_in'] = WordReadings::writtenIn($answers);
            Cache::put($key, ['readings' => $readings, 'written_in' => $info['written_in']], now()->addDays(self::DAYS));
        }

        $made = WordReadings::apply($text, $readings);
        // The step only ever adds marks: a result that changes a letter is not used.
        if (TashkeelScore::strip($made['text']) !== TashkeelScore::strip($text)) {
            return ['text' => $text, 'direction' => '', 'info' => ['ms' => self::since($started)] + $info];
        }

        return ['text' => $made['text'], 'direction' => $made['direction'], 'info' => ['words' => count($readings), 'ms' => self::since($started)] + $info];
    }

    /**
     * The model's answers to the same prompt, asked together, each with its own fixed seed
     * so the same text has the best chance of the same answers. Only the ones that came back.
     *
     * @return list<string>
     */
    private static function ask(string $prompt, string $model): array
    {
        try {
            $key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
            if ($key === '') {
                return [];
            }
            $url = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/').'/models/'.$model.':generateContent';
            $seconds = max(2, (int) config('services.gemini_tts.readings_timeout', 6));
            $options = ['verify' => (bool) config('services.gemini_tts.verify_ssl', true)];
            $responses = Http::pool(function (Pool $pool) use ($prompt, $url, $key, $seconds, $options): void {
                for ($k = 0; $k < WordReadings::ASKS; $k++) {
                    $pool->as((string) $k)->timeout($seconds)->connectTimeout(3)->withOptions($options)
                        ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $key])
                        ->post($url, [
                            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'responseMimeType' => 'application/json',
                                'temperature' => 0,
                                'seed' => 101 + $k,
                                'thinkingConfig' => ['thinkingLevel' => 'low'],
                            ],
                        ]);
                }
            });
        } catch (\Throwable) {
            return [];
        }
        $answers = [];
        foreach ($responses as $response) {
            $text = $response instanceof Response && $response->successful() ? data_get($response->json(), 'candidates.0.content.parts.0.text') : null;
            if (is_string($text) && trim($text) !== '') {
                $answers[] = $text;
            }
        }

        return $answers;
    }

    private static function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
