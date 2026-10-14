<?php

namespace App\Support;

/**
 * The languages a recording can be made in: Arabic and English, each with its
 * own speakers, dialects and accents. A language added here beyond those two
 * is read by the English premium speakers and needs a premium plan.
 */
final class SpeechLanguages
{
    /**
     * code => [English name, Arabic name, own name, speech code, flag]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const ALL = [
        'ar' => ['Arabic', 'العربية', 'العربية', 'ar-XA', 'sa'],
        'en' => ['English', 'الإنجليزية', 'English', 'en-US', 'us'],
    ];

    /**
     * The writing system of each language that does not use Latin letters,
     * used to notice text that was left in another language.
     *
     * @var array<string, string>
     */
    private const SCRIPTS = [
        'ar' => '\p{Arabic}',
        'ru' => '\p{Cyrillic}',
        'hi' => '\p{Devanagari}',
        'mr' => '\p{Devanagari}',
        'bn' => '\p{Bengali}',
        'ja' => '\p{Hiragana}\p{Katakana}\p{Han}',
        'ko' => '\p{Hangul}',
        'th' => '\p{Thai}',
        'ta' => '\p{Tamil}',
        'te' => '\p{Telugu}',
        'gu' => '\p{Gujarati}',
        'kn' => '\p{Kannada}',
        'ml' => '\p{Malayalam}',
    ];

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::ALL[$code]);
    }

    /**
     * Languages spoken only by the premium speakers.
     */
    public static function premiumOnly(?string $code): bool
    {
        return self::isValid($code) && ! in_array($code, ['ar', 'en'], true);
    }

    /**
     * Whose speakers read this language: Arabic and English have their own,
     * every other language borrows the English premium speakers.
     */
    public static function voiceLanguage(string $code): string
    {
        $code = strtolower(trim($code));

        return self::premiumOnly($code) ? 'en' : $code;
    }

    public static function name(?string $code, bool $arabic): string
    {
        $row = self::ALL[(string) $code] ?? null;

        return $row === null ? (string) $code : ($arabic ? $row[1] : $row[0]);
    }

    public static function englishName(?string $code): string
    {
        return self::name($code, false);
    }

    public static function nativeName(?string $code): string
    {
        return (string) (self::ALL[(string) $code][2] ?? $code);
    }

    public static function speechCode(?string $code): ?string
    {
        return self::ALL[(string) $code][3] ?? null;
    }

    public static function flag(?string $code): string
    {
        return (string) (self::ALL[(string) $code][4] ?? '');
    }

    /**
     * The language a script is most likely written in, from its letters:
     * a guess for the translator's source, never a final answer.
     */
    public static function detect(string $text): string
    {
        foreach (self::SCRIPTS as $code => $class) {
            if (preg_match('/['.$class.']/u', $text) === 1) {
                return $code;
            }
        }

        return 'en';
    }

    /**
     * True when a translation into $target still holds letters of a script
     * the target language does not use (text the translator left untouched).
     */
    public static function hasForeignScript(string $text, string $target): bool
    {
        $own = self::SCRIPTS[$target] ?? '';
        foreach (array_unique(self::SCRIPTS) as $class) {
            if ($class === $own) {
                continue;
            }
            if (preg_match('/['.$class.']/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rows for a language select: value, label in the page language, own name.
     *
     * @return array<int, array{value: string, label: string, native: string, premium: bool}>
     */
    public static function options(bool $arabic): array
    {
        $rows = [];
        foreach (self::ALL as $code => $row) {
            $rows[] = ['value' => $code, 'label' => $arabic ? $row[1] : $row[0], 'native' => $row[2], 'premium' => self::premiumOnly($code)];
        }

        return $rows;
    }
}
