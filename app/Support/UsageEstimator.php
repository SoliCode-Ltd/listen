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
 * The premium voices were measured again in November 2026 on the 1,072 made
 * with today's engine (Gemini 3.8 Flash; scripts/support/estimate_fit.php,
 * "Estimate fit" in GitHub Actions), each rate and factor fitted together
 * and each language on its own voices. Arabic took the new numbers: judged on
 * the half of its voices the fit did not see, the real length was within 15%
 * of the estimate for 59% of them (51% with the October numbers) and more
 * than 10% longer than it for 11.3% (13.8%). English kept its October numbers:
 * refitted, it did no better on the same test (October's were within 15% for
 * two English voices in three). The pace was fitted on both languages. A rate
 * that too few voices hold to measure (sounds, pauses, digits, the other
 * script's letters, vowel marks, the takes a mood starts) keeps its October
 * number, as does a setting seen on fewer than 5 of a language's voices. On
 * all the voices the fit did not see, the real length is now within 15% of
 * the estimate for 61% of them (55% before) and more than 10% longer for 8.9%
 * (11.2%).
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
            'ar' => ['arabic' => 0.083, 'latin' => 0.075, 'cjk' => 0.2, 'mark' => 0.015, 'number' => 0.8, 'sound' => 1.3, 'short_pause' => 0.55, 'long_pause' => 2.25,
                'sentence' => 0.254, 'clause' => 0.228, 'line' => 0.372, 'mood' => 0.2, 'base' => 0.434],
            'en' => ['arabic' => 0.089, 'latin' => 0.075, 'cjk' => 0.2, 'mark' => 0.0, 'number' => 0.63, 'sound' => 1.3, 'short_pause' => 0.55, 'long_pause' => 2.25,
                'sentence' => 0.42, 'clause' => 0.21, 'line' => 0.21, 'mood' => 0.2, 'base' => 1.13],
        ],
    ];

    /**
     * How much longer (or shorter) a premium voice reads with a studio
     * setting, against the same text read plainly, by the script most of the
     * text is in (as in MODEL): measured on live with the rates, each pulled
     * toward 1 by how few voices it was measured on (1 + (median - 1) x n /
     * (n + 10)), and only kept when that moves it 4% or more.
     *
     * Arabic's are November's, from Arabic voices alone: a horror, cinematic or
     * elder style reads much slower, a social media or neutral one faster; a
     * serious emotion a quarter slower, confident (the most used) a tenth, a
     * furious or panicked one faster. English keeps October's, measured on
     * both languages.
     *
     * @var array<string, array<string, array<string, float>>>
     */
    public const STUDIO_FACTORS = [
        'ar' => [
            'performance_style' => ['horror' => 1.44, 'cinematic' => 1.29, 'elder_dua' => 1.19, 'poetry' => 1.16, 'podcast' => 1.15,
                'meditation' => 1.13, 'storytelling' => 1.13, 'conversational' => 1.12, 'sports_commentator' => 1.09, 'comedic' => 1.07,
                'documentary' => 1.06, 'inspirational' => 1.05, 'commercial' => 0.95, 'social_media' => 0.92, 'neutral' => 0.85],
            'emotion' => ['serious' => 1.25, 'playful' => 1.13, 'spiritual' => 1.13, 'confident' => 1.1, 'hopeful' => 1.09,
                'romantic' => 1.07, 'formal' => 1.06, 'neutral' => 1.05, 'calm' => 1.04, 'panicked' => 0.89, 'furious' => 0.88],
            'dialect' => ['ar-XA-CL' => 1.21, 'ar-XA-ED' => 1.13, 'ar-LB-MT' => 1.12, 'ar-XA' => 1.11, 'ar-MA-NO' => 1.09, 'ar-SA' => 1.08,
                'ar-YE' => 1.08, 'ar-AE' => 1.07, 'ar-OM' => 1.06, 'ar-SY' => 1.05, 'ar-EG-DL' => 1.05, 'ar-EG-SA' => 1.05, 'ar-OM-IN' => 1.05,
                'ar-YE-HD' => 0.93, 'ar-XA-WH' => 0.92, 'ar-SA-QS' => 0.87],
        ],
        'en' => [
            'performance_style' => ['meditation' => 1.22, 'cinematic' => 1.3, 'inspirational' => 1.09, 'sports_commentator' => 1.09,
                'documentary' => 1.08, 'podcast' => 1.04, 'commercial' => 0.92],
            'emotion' => ['spiritual' => 1.13, 'romantic' => 1.09, 'excited' => 1.08, 'formal' => 1.06, 'mysterious' => 1.05,
                'angry' => 0.96, 'warm' => 0.96],
            'dialect' => ['ar-XA' => 1.09, 'ar-IQ' => 1.06, 'ar-OM' => 1.06, 'ar-SA' => 1.05, 'ar-EG-SA' => 1.05,
                'ar-SY' => 0.96, 'ar-LB' => 0.93],
        ],
    ];

    /**
     * A written direction (style_prompt) makes a premium voice slower. No
     * Arabic voice on live had one in November, so it stays October's.
     */
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
     * How the pace setting changes a premium voice's length on today's engine
     * (it is asked for in words): slowing down works a little (pace 0.5 makes
     * the audio about 1.2 times as long), speeding up hardly at all (pace 1.5
     * about 0.98 times). Standard voices are always read at the normal pace.
     */
    public const SLOWER_POWER = 0.25;

    public const FASTER_POWER = 0.05;

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
        $language = $features['arabic'] >= $features['latin'] + $features['cjk'] ? 'ar' : 'en';
        $model = self::MODEL[$tier][$language];
        $seconds = $model['base'];
        foreach ($features as $name => $count) {
            $seconds += ($model[$name] ?? 0.0) * $count;
        }
        if ($tier === VoiceTier::HD) {
            foreach (self::STUDIO_FACTORS[$language] as $setting => $factors) {
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
            'studio_factors_by_language' => self::STUDIO_FACTORS,
            // The one table an older copy of the script reads (the browser extension's, until
            // its next version is installed): English's, which are October's for both.
            'studio_factors' => self::STUDIO_FACTORS['en'],
            'direction_factor' => self::DIRECTION_FACTOR,
            'credits_per_second' => Credits::PER_SECOND,
            'sounds' => GeminiVocalTags::spellingIndex(),
            'deliveries' => GeminiVocalTags::deliveryIndex(),
        ];
    }
}
