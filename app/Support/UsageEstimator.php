<?php

namespace App\Support;

/**
 * How long a text's audio will be, and so what it costs, before it is made:
 * the studio shows it as the text is typed ("≈ 0:15 of audio · 225
 * credits"), and the studio, the extension and the developer API reserve it.
 * The member is charged the real length afterwards (GenerateAudioJob).
 *
 * The rates were measured on live in October 2026 from 4,766 voices whose
 * audio length was read from the file (scripts/support/speech_rates.php,
 * "speech-rates" in the Server deploy workflow): Arabic is read at about 9
 * letters a second and English at about 13, a sound such as <laugh> adds
 * about 1.3 seconds on a premium voice, and so on. Fitted to the relative
 * error, so short clips count as much as long ones; with these numbers the
 * real length was within about 12% of the estimate for most voices (the
 * old flat 15 characters a second was a median 25% short for Arabic).
 *
 * public/js/audio-estimate.js does the same for the studio page: keep the
 * two in step (tests/Feature/AudioEstimateScriptTest.php runs both).
 */
class UsageEstimator
{
    /**
     * Seconds of audio, at the normal pace, for what a text holds, by voice
     * tier and by the script most of the text is in. Letters are counted by
     * their own script in any text.
     *
     * @var array<string, array<string, array<string, float>>>
     */
    public const MODEL = [
        VoiceTier::STANDARD => [
            'ar' => ['arabic' => 0.106, 'latin' => 0.071, 'cjk' => 0.2, 'mark' => 0.0, 'number' => 0.69, 'sound' => 0.0, 'short_pause' => 0.0, 'long_pause' => 0.0,
                'sentence' => 0.29, 'clause' => 0.37, 'line' => 0.16, 'mood' => 2.2, 'base' => 0.27],
            'en' => ['arabic' => 0.106, 'latin' => 0.071, 'cjk' => 0.2, 'mark' => 0.0, 'number' => 0.48, 'sound' => 0.0, 'short_pause' => 0.0, 'long_pause' => 0.0,
                'sentence' => 0.0, 'clause' => 0.31, 'line' => 0.22, 'mood' => 2.2, 'base' => 0.58],
        ],
        VoiceTier::HD => [
            'ar' => ['arabic' => 0.089, 'latin' => 0.075, 'cjk' => 0.2, 'mark' => 0.015, 'number' => 0.8, 'sound' => 1.3, 'short_pause' => 0.55, 'long_pause' => 2.25,
                'sentence' => 0.28, 'clause' => 0.27, 'line' => 0.21, 'mood' => 0.2, 'base' => 0.49],
            'en' => ['arabic' => 0.089, 'latin' => 0.075, 'cjk' => 0.2, 'mark' => 0.0, 'number' => 0.63, 'sound' => 1.3, 'short_pause' => 0.55, 'long_pause' => 2.25,
                'sentence' => 0.42, 'clause' => 0.21, 'line' => 0.21, 'mood' => 0.2, 'base' => 1.13],
        ],
    ];

    /**
     * How much longer (or shorter) a premium voice reads with a studio
     * setting, against the same text read plainly: measured on live like the
     * rates, each pulled toward 1 by how few voices it was measured on
     * (1 + (median - 1) x n / (n + 10)), and only kept when that moves it 4%
     * or more. A meditation or cinematic style reads much slower, a
     * commercial one a little faster; a written direction (style_prompt)
     * makes it slower too.
     *
     * @var array<string, array<string, float>>
     */
    public const STUDIO_FACTORS = [
        'performance_style' => ['meditation' => 1.22, 'cinematic' => 1.3, 'inspirational' => 1.09, 'sports_commentator' => 1.09,
            'documentary' => 1.08, 'podcast' => 1.04, 'commercial' => 0.92],
        'emotion' => ['spiritual' => 1.13, 'romantic' => 1.09, 'excited' => 1.08, 'formal' => 1.06, 'mysterious' => 1.05,
            'angry' => 0.96, 'warm' => 0.96],
        'dialect' => ['ar-XA' => 1.09, 'ar-IQ' => 1.06, 'ar-OM' => 1.06, 'ar-SA' => 1.05, 'ar-EG-SA' => 1.05,
            'ar-SY' => 0.96, 'ar-LB' => 0.93],
    ];

    public const DIRECTION_FACTOR = 1.1;

    /**
     * The estimate is made this much longer, so a member is more often
     * charged a little less than shown than a little more. Premium voices
     * vary more from one take to the next (most within about 15% of the
     * fit, standard voices within about 10%), so theirs is wider.
     *
     * @var array<string, float>
     */
    public const MARGIN = [VoiceTier::STANDARD => 1.05, VoiceTier::HD => 1.1];

    /**
     * How the pace setting changes a premium voice's length: slowing down
     * works (pace 0.5 made the audio about 1.6 times as long), speeding up
     * much less (pace 1.5 made it about 0.85 times as long). Standard voices
     * are always read at the normal pace.
     */
    public const SLOWER_POWER = 0.7;

    public const FASTER_POWER = 0.2;

    /**
     * Characters a member is charged for: every character of the text that is
     * read aloud, spaces, line breaks, punctuation and tags included, because
     * the speech provider bills all of them.
     */
    public static function billableChars(string $text): int
    {
        return mb_strlen(trim($text));
    }

    /**
     * @param  string|null  $tier  the voice's tier (VoiceTier); unknown means premium
     * @param  float  $speed  the pace setting (1.0 is normal)
     * @param  array<string, mixed>  $studio  the premium voice's performance_style, emotion, dialect and style_prompt
     * @return array{chars:int,words:int,seconds:int,minutes:int,credits:int}
     */
    public static function estimate(string $text, ?string $tier = null, float $speed = 1.0, array $studio = []): array
    {
        $plain = trim($text);
        $seconds = 0;
        if ($plain !== '') {
            $seconds = max(1, (int) round(self::rawSeconds(self::features($plain), $tier, $speed, $studio) * self::MARGIN[self::tier($tier)]));
        }

        return [
            'chars' => self::billableChars($plain),
            // Vowel marks are part of a word, not a break in it.
            'words' => preg_match_all('/[\p{L}\p{M}\p{N}\']+/u', $plain),
            'seconds' => $seconds,
            'minutes' => max(1, (int) ceil($seconds / 60)),
            // Reserved before generating; corrected to the real audio afterwards.
            'credits' => Credits::forSeconds($seconds),
        ];
    }

    /**
     * Seconds at the normal pace, before the margin and rounding.
     *
     * @param  array<string, int>  $features  from features()
     * @param  array<string, mixed>  $studio  as estimate() takes it
     */
    public static function rawSeconds(array $features, ?string $tier = null, float $speed = 1.0, array $studio = []): float
    {
        $tier = self::tier($tier);
        $model = self::MODEL[$tier][$features['arabic'] >= $features['latin'] + $features['cjk'] ? 'ar' : 'en'];
        $seconds = $model['base'];
        foreach ($features as $name => $count) {
            $seconds += ($model[$name] ?? 0.0) * $count;
        }
        if ($tier === VoiceTier::HD) {
            foreach (self::STUDIO_FACTORS as $setting => $factors) {
                $seconds *= $factors[(string) ($studio[$setting] ?? '')] ?? 1.0;
            }
            if (trim((string) ($studio['style_prompt'] ?? '')) !== '') {
                $seconds *= self::DIRECTION_FACTOR;
            }
            if ($speed > 0 && abs($speed - 1.0) > 0.001) {
                $seconds *= $speed ** ($speed < 1 ? -self::SLOWER_POWER : -self::FASTER_POWER);
            }
        }

        return $seconds;
    }

    /** The tier whose numbers apply: premium unless the voice is a standard one. */
    private static function tier(?string $tier): string
    {
        return VoiceTier::normalize($tier ?? VoiceTier::HD) === VoiceTier::STANDARD ? VoiceTier::STANDARD : VoiceTier::HD;
    }

    /**
     * What a text holds that takes time to say, as the speech pipeline reads
     * it (GeminiVocalTags::parts): letters by script (vowel marks apart),
     * numbers, sounds and pauses, sentence and clause ends, line breaks, and
     * the extra takes a mood or delivery tag in the middle of the text starts.
     *
     * @return array{arabic:int, latin:int, cjk:int, mark:int, number:int, sound:int, short_pause:int, long_pause:int, sentence:int, clause:int, line:int, mood:int}
     */
    public static function features(string $text): array
    {
        $parts = GeminiVocalTags::parts($text, true);
        $sounds = ['sound' => 0, 'short_pause' => 0, 'long_pause' => 0];
        $spoken = [];
        foreach ($parts as $part) {
            preg_match_all('/<([a-z][a-z -]*)>/', (string) $part['text'], $tags);
            foreach ($tags[1] as $tag) {
                $sounds[match ($tag) {
                    'short pause' => 'short_pause',
                    'long pause' => 'long_pause',
                    default => 'sound',
                }]++;
            }
            $spoken[] = GeminiVocalTags::strip((string) $part['text']);
        }
        $spoken = implode(' ', $spoken);

        $letters = preg_match_all('/[\p{L}\p{N}]/u', $spoken);
        $arabic = preg_match_all('/(?=[\p{L}\p{N}])\p{Arabic}/u', $spoken);
        $cjk = preg_match_all('/(?=[\p{L}\p{N}])[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $spoken);

        return [
            'arabic' => $arabic,
            'latin' => $letters - $arabic - $cjk,
            'cjk' => $cjk,
            'mark' => preg_match_all('/\p{Mn}/u', $spoken),
            'number' => preg_match_all('/\p{N}+/u', $spoken),
            ...$sounds,
            'sentence' => preg_match_all('/[.!?؟…]+/u', $spoken),
            'clause' => preg_match_all('/[,،;؛:]/u', $spoken),
            'line' => preg_match_all('/\n\s*/u', $spoken),
            'mood' => max(0, count($parts) - 1),
        ];
    }

    /**
     * What public/js/audio-estimate.js needs to estimate like this class.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        return [
            'model' => self::MODEL,
            'margin' => self::MARGIN,
            'slower_power' => self::SLOWER_POWER,
            'faster_power' => self::FASTER_POWER,
            'studio_factors' => self::STUDIO_FACTORS,
            'direction_factor' => self::DIRECTION_FACTOR,
            'credits_per_second' => Credits::PER_SECOND,
            'sounds' => GeminiVocalTags::spellingIndex(),
            'deliveries' => GeminiVocalTags::deliveryIndex(),
        ];
    }
}
