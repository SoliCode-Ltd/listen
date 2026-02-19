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

    public static function mapByName(): array
    {
        $map = [];

        foreach (self::byLanguage() as $language => $voices) {
            if (! is_array($voices)) {
                continue;
            }

            foreach ($voices as $index => $voice) {
                if (! is_array($voice) || empty($voice['name'])) {
                    continue;
                }

                $name = (string) $voice['name'];
                $key = (string) ($voice['key'] ?? ($language.'_'.($index + 1)));
                $map[$name] = [
                    'key' => $key,
                    'name' => $name,
                    'label' => (string) ($voice['label'] ?? $name),
                    'gender' => (string) ($voice['gender'] ?? 'neutral'),
                    'avatar' => (string) ($voice['avatar'] ?? '🎙️'),
                    'recommended' => (bool) ($voice['recommended'] ?? false),
                    'language' => (string) $language,
                ];
            }
        }

        return $map;
    }

    public static function publicCatalog(): array
    {
        $catalog = [];

        foreach (self::byLanguage() as $language => $voices) {
            if (! is_array($voices)) {
                continue;
            }

            $catalog[$language] = [];

            foreach ($voices as $index => $voice) {
                if (! is_array($voice) || empty($voice['name'])) {
                    continue;
                }

                $catalog[$language][] = [
                    'key' => (string) ($voice['key'] ?? ($language.'_'.($index + 1))),
                    'label' => (string) ($voice['label'] ?? $voice['name']),
                    'gender' => (string) ($voice['gender'] ?? 'neutral'),
                    'avatar' => (string) ($voice['avatar'] ?? '🎙️'),
                    'recommended' => (bool) ($voice['recommended'] ?? false),
                ];
            }
        }

        return $catalog;
    }

    public static function voiceMeta(?string $voiceName): ?array
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        $map = self::mapByName();
        $meta = $map[$voiceName] ?? null;
        if (! is_array($meta)) {
            return null;
        }

        return [
            'key' => $meta['key'] ?? null,
            'label' => $meta['label'] ?? null,
            'gender' => $meta['gender'] ?? null,
            'avatar' => $meta['avatar'] ?? '🎙️',
            'recommended' => (bool) ($meta['recommended'] ?? false),
            'language' => $meta['language'] ?? null,
        ];
    }

    public static function labelForName(?string $voiceName): string
    {
        $meta = self::voiceMeta($voiceName);

        return $meta['label'] ?? 'Default voice';
    }

    public static function nameFromKeyForLanguage(string $language, ?string $voiceKey): ?string
    {
        $voiceKey = trim((string) $voiceKey);
        if ($voiceKey === '') {
            return null;
        }

        foreach (self::forLanguage($language) as $index => $voice) {
            if (! is_array($voice) || empty($voice['name'])) {
                continue;
            }

            $key = (string) ($voice['key'] ?? ($language.'_'.($index + 1)));
            if ($key === $voiceKey) {
                return (string) $voice['name'];
            }
        }

        return null;
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
