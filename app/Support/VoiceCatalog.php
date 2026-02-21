<?php

namespace App\Support;

class VoiceCatalog
{
    public static function byLanguage(): array
    {
        $catalog = config('services.google_tts.voice_catalog', []);
        $requireWaveNet = (bool) config('listen.voice.require_wavenet', true);

        if (! is_array($catalog)) {
            return [];
        }

        $cleaned = [];
        foreach ($catalog as $language => $voices) {
            if (! is_array($voices)) {
                continue;
            }

            $language = (string) $language;
            $cleaned[$language] = [];
            $seenKeys = [];
            $seenNames = [];

            foreach ($voices as $index => $voice) {
                if (! is_array($voice) || empty($voice['name'])) {
                    continue;
                }

                $name = (string) $voice['name'];
                if (! self::matchesLanguagePrefix($name, $language)) {
                    continue;
                }

                if ($requireWaveNet && ! self::isWaveNetVoiceName($name)) {
                    continue;
                }

                $key = (string) ($voice['key'] ?? ($language.'_'.($index + 1)));
                if (isset($seenKeys[$key]) || isset($seenNames[$name])) {
                    continue;
                }

                $seenKeys[$key] = true;
                $seenNames[$name] = true;
                $voice['key'] = $key;
                $cleaned[$language][] = $voice;
            }
        }

        return $cleaned;
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
                    'avatar' => (string) ($voice['avatar'] ?? 'profile'),
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
                    'avatar' => (string) ($voice['avatar'] ?? 'profile'),
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
            'avatar' => $meta['avatar'] ?? 'profile',
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
        $fallback = $language === 'ar' ? 'ar-XA-Wavenet-A' : 'en-US-Wavenet-F';
        $default = $language === 'ar'
            ? (string) config('services.google_tts.voice_ar', $fallback)
            : (string) config('services.google_tts.voice_en', $fallback);

        $requireWaveNet = (bool) config('listen.voice.require_wavenet', true);
        if ($requireWaveNet && ! self::isWaveNetVoiceName($default)) {
            $default = $fallback;
        }

        if (self::isAllowedForLanguage($default, $language)) {
            return $default;
        }

        $names = self::namesForLanguage($language);

        return $names[0] ?? $fallback;
    }

    public static function isWaveNetVoiceName(?string $voiceName): bool
    {
        $voiceName = trim((string) $voiceName);

        return $voiceName !== '' && str_contains(strtolower($voiceName), '-wavenet-');
    }

    private static function matchesLanguagePrefix(string $voiceName, string $language): bool
    {
        $language = strtolower(trim($language));
        $voiceName = trim($voiceName);

        return match ($language) {
            'ar' => str_starts_with($voiceName, 'ar-'),
            'en' => str_starts_with($voiceName, 'en-'),
            default => true,
        };
    }
}
