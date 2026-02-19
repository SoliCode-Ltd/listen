<?php

namespace App\Support;

class VoiceCatalog
{
    public static function byLanguage(): array
    {
        $catalog = config('services.google_tts.voice_catalog', []);

        return is_array($catalog) ? $catalog : [];
    }

    public static function forLanguage(string $language): array
    {
        $catalog = self::byLanguage();

        return isset($catalog[$language]) && is_array($catalog[$language])
            ? $catalog[$language]
            : [];
    }

    public static function allVoiceNames(): array
    {
        $names = [];

        foreach (self::byLanguage() as $voices) {
            if (! is_array($voices)) {
                continue;
            }

            foreach ($voices as $voice) {
                if (is_array($voice) && ! empty($voice['name'])) {
                    $names[] = (string) $voice['name'];
                }
            }
        }

        return array_values(array_unique($names));
    }

    public static function namesForLanguage(string $language): array
    {
        $names = [];

        foreach (self::forLanguage($language) as $voice) {
            if (is_array($voice) && ! empty($voice['name'])) {
                $names[] = (string) $voice['name'];
            }
        }

        return $names;
    }

    public static function isAllowedForLanguage(?string $voiceName, string $language): bool
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return true;
        }

        return in_array($voiceName, self::namesForLanguage($language), true);
    }

    public static function defaultForLanguage(string $language): string
    {
        $default = $language === 'ar'
            ? (string) config('services.google_tts.voice_ar', 'ar-XA-Wavenet-D')
            : (string) config('services.google_tts.voice_en', 'en-US-Wavenet-D');

        if (self::isAllowedForLanguage($default, $language)) {
            return $default;
        }

        $names = self::namesForLanguage($language);

        return $names[0] ?? $default;
    }
}
