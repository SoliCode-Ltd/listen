<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Which Google voice family reads Standard-quality audio.
 *
 * The voice picker keeps listing the standard voices. With Chirp 3 HD on,
 * each one is read by a fixed Chirp 3 HD voice of the same language and
 * gender, so a user's chosen speaker stays the same speaker every time.
 */
class StandardVoiceEngine
{
    public const SETTING_KEY = 'tts_standard_voice_engine';

    public const WAVENET = 'wavenet';

    public const CHIRP3_HD = 'chirp3_hd';

    /** Chirp 3 HD voices available in ar-XA and en-US, in pick order. */
    private const CHIRP_FEMALE = ['Aoede', 'Kore', 'Leda', 'Zephyr', 'Callirrhoe', 'Despina', 'Erinome', 'Sulafat'];

    private const CHIRP_MALE = ['Charon', 'Puck', 'Fenrir', 'Orus', 'Achird', 'Iapetus', 'Schedar', 'Umbriel'];

    /** Google's published genders for the WaveNet and Standard letters we list. */
    private const LETTER_GENDERS = [
        'ar-XA' => ['A' => 'female', 'B' => 'male', 'C' => 'male', 'D' => 'female'],
        'en-US' => [
            'A' => 'male', 'B' => 'male', 'C' => 'female', 'D' => 'male', 'E' => 'female',
            'F' => 'female', 'G' => 'female', 'H' => 'female', 'I' => 'male', 'J' => 'male',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::WAVENET => 'Google WaveNet ($16 / 1M characters)',
            self::CHIRP3_HD => 'Google Chirp 3 HD ($30 / 1M characters, more natural)',
        ];
    }

    public static function current(): string
    {
        $value = strtolower(trim((string) SiteSetting::get(self::SETTING_KEY, self::WAVENET)));

        return $value === self::CHIRP3_HD ? self::CHIRP3_HD : self::WAVENET;
    }

    public static function usesChirp(): bool
    {
        return self::current() === self::CHIRP3_HD;
    }

    public static function isChirpVoice(string $voiceName): bool
    {
        return str_contains(strtolower($voiceName), '-chirp3-hd-');
    }

    /**
     * The Chirp 3 HD voice that reads a given WaveNet or Standard voice.
     */
    public static function chirpVoiceFor(string $voiceName, string $language, string $gender = ''): string
    {
        $voiceName = trim($voiceName);
        if (self::isChirpVoice($voiceName)) {
            return $voiceName;
        }

        $locale = preg_match('/^([a-z]{2})-([A-Z]{2})-/i', $voiceName, $m)
            ? strtolower($m[1]).'-'.strtoupper($m[2])
            : (str_starts_with(strtolower($language), 'ar') ? 'ar-XA' : 'en-US');

        $letter = preg_match('/-(?:wavenet|standard|neural2)-([A-Z])$/i', $voiceName, $m)
            ? strtoupper($m[1])
            : null;

        $gender = strtolower(trim($gender));
        if (! in_array($gender, ['female', 'male'], true)) {
            $gender = ($letter !== null ? (self::LETTER_GENDERS[$locale][$letter] ?? null) : null) ?? 'female';
        }

        $names = $gender === 'male' ? self::CHIRP_MALE : self::CHIRP_FEMALE;
        $index = $letter !== null
            ? ord($letter) - ord('A')
            : (int) sprintf('%u', crc32($voiceName));

        return $locale.'-Chirp3-HD-'.$names[$index % count($names)];
    }

    /**
     * Every Chirp 3 HD voice this mapping can pick, for one locale.
     *
     * @return array<int, string>
     */
    public static function allChirpVoices(string $locale): array
    {
        return array_map(
            fn (string $name): string => $locale.'-Chirp3-HD-'.$name,
            array_merge(self::CHIRP_FEMALE, self::CHIRP_MALE),
        );
    }
}
