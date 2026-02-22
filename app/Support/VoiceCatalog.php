<?php

namespace App\Support;

use App\Models\Voice;

class VoiceCatalog
{
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function byLanguage(?string $tier = null): array
    {
        $voices = self::activeQuery($tier)->get();
        $catalog = [];

        foreach ($voices as $voice) {
            $catalog[$voice->language] ??= [];
            $catalog[$voice->language][] = self::toVoiceEntry($voice);
        }

        return $catalog;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forLanguage(string $language, ?string $tier = null): array
    {
        return self::byLanguage($tier)[$language] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function allVoiceNames(?string $tier = null): array
    {
        return self::activeQuery($tier)
            ->pluck('internal_voice_id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function namesForLanguage(string $language, ?string $tier = null): array
    {
        return self::activeQuery($tier)
            ->where('language', $language)
            ->pluck('internal_voice_id')
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function mapByName(?string $tier = null): array
    {
        $map = [];

        foreach (self::activeQuery($tier)->get() as $voice) {
            $map[$voice->internal_voice_id] = self::toVoiceEntry($voice);
        }

        return $map;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function publicCatalog(?string $tier = null): array
    {
        $catalog = [];

        foreach (self::byLanguage($tier) as $language => $voices) {
            $catalog[$language] = array_map(function (array $voice): array {
                return [
                    'key' => $voice['key'],
                    'label' => $voice['label'],
                    'gender' => $voice['gender'],
                    'avatar' => $voice['avatar'],
                    'recommended' => $voice['recommended'],
                    'tier' => $voice['tier'],
                    'tier_label' => VoiceTier::label((string) $voice['tier']),
                ];
            }, $voices);
        }

        return $catalog;
    }

    /**
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    public static function publicCatalogByTier(): array
    {
        $catalog = [];

        foreach (VoiceTier::all() as $tier) {
            $catalog[$tier] = self::publicCatalog($tier);
        }

        return $catalog;
    }

    public static function voiceMeta(?string $voiceName): ?array
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        $voice = self::activeQuery()->where('internal_voice_id', $voiceName)->first();
        if (! $voice) {
            return null;
        }

        $meta = self::toVoiceEntry($voice);

        return [
            'key' => $meta['key'],
            'label' => $meta['label'],
            'gender' => $meta['gender'],
            'avatar' => $meta['avatar'],
            'recommended' => (bool) $meta['recommended'],
            'language' => $meta['language'],
            'tier' => $meta['tier'],
            'tier_label' => VoiceTier::label((string) $meta['tier']),
        ];
    }

    public static function labelForName(?string $voiceName): string
    {
        return self::voiceMeta($voiceName)['label'] ?? 'Default voice';
    }

    public static function nameFromKeyForLanguage(string $language, ?string $voiceKey, ?string $tier = null): ?string
    {
        $voice = self::findByKeyForLanguage($voiceKey, $language, $tier);

        return $voice?->internal_voice_id;
    }

    public static function isAllowedForLanguage(?string $voiceName, string $language, ?string $tier = null): bool
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return true;
        }

        return self::activeQuery($tier)
            ->where('language', $language)
            ->where('internal_voice_id', $voiceName)
            ->exists();
    }

    public static function defaultForLanguage(string $language, ?string $tier = null): string
    {
        $language = strtolower(trim($language));
        $normalizedTier = $tier !== null && $tier !== ''
            ? VoiceTier::normalize($tier)
            : null;

        if ($normalizedTier !== null) {
            $voice = self::activeQuery($normalizedTier)
                ->where('language', $language)
                ->orderBy('id')
                ->first();

            return $voice ? (string) $voice->internal_voice_id : '';
        }

        $fallback = self::activeQuery()
            ->where('language', $language)
            ->orderBy('id')
            ->first();

        return $fallback ? (string) $fallback->internal_voice_id : '';
    }

    public static function tierForName(?string $voiceName): ?string
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        return self::activeQuery()
            ->where('internal_voice_id', $voiceName)
            ->value('tier');
    }

    public static function isWaveNetVoiceName(?string $voiceName): bool
    {
        $voiceName = trim((string) $voiceName);

        return $voiceName !== '' && str_contains(strtolower($voiceName), '-wavenet-');
    }

    public static function findByKeyForLanguage(?string $voiceKey, string $language, ?string $tier = null): ?Voice
    {
        $id = self::idFromKey($voiceKey);
        if (! $id) {
            return null;
        }

        $query = self::activeQuery($tier)->where('id', $id)->where('language', $language);

        return $query->first();
    }

    public static function keyFromId(int $id): string
    {
        return 'voice_'.$id;
    }

    private static function idFromKey(?string $key): ?int
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        if (! preg_match('/^voice_(\d+)$/', $key, $matches)) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private static function activeQuery(?string $tier = null)
    {
        $query = Voice::query()->where('is_active', true);

        if ($tier !== null && $tier !== '') {
            $query->where('tier', VoiceTier::normalize($tier));
        }

        return $query
            ->orderBy('language')
            ->orderByRaw("CASE tier WHEN 'standard' THEN 1 WHEN 'hd' THEN 2 WHEN 'hd_plus' THEN 3 ELSE 99 END")
            ->orderBy('display_name')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function toVoiceEntry(Voice $voice): array
    {
        return [
            'id' => (int) $voice->id,
            'key' => self::keyFromId((int) $voice->id),
            'name' => (string) $voice->internal_voice_id,
            'label' => (string) $voice->display_name,
            'gender' => (string) $voice->gender,
            'avatar' => self::avatarFor((string) $voice->gender, (string) $voice->language),
            'recommended' => false,
            'language' => (string) $voice->language,
            'tier' => VoiceTier::normalize((string) $voice->tier),
        ];
    }

    private static function avatarFor(string $gender, string $language): string
    {
        $gender = strtolower(trim($gender));
        $language = strtolower(trim($language));

        if ($gender === 'male') {
            return $language === 'ar' ? 'male-ar' : 'male-en';
        }

        return $language === 'ar' ? 'female-ar' : 'female-en';
    }
}
