<?php

namespace App\Support;

use App\Models\ClonedVoice;

/**
 * Turns what a generation stored (codes like ar-EG, promo, cloned_12) into
 * the names members picked from, in Arabic and English, for the admin.
 *
 * Labels come from the live catalogs, so a generation always shows the
 * choice the way the site offers it today, including choices an admin added.
 */
final class GenerationChoiceLabels
{
    /**
     * The accent the member chose, e.g. "مصري (قاهري) · Egyptian (Cairene) — ar-EG".
     */
    public static function accent(?string $code, ?string $language = null, ?string $tier = null): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return trim((string) $tier) !== '' && VoiceTier::normalize((string) $tier) === VoiceTier::STANDARD
                ? 'Not used (Standard voices have no accent choice)'
                : 'None chosen (no accent direction sent)';
        }

        $row = self::findRow(self::dialectRows($language, $code), $code);
        if ($row === null) {
            return "Unknown accent — {$code}";
        }

        return self::bilingual($row['label_ar'], $row['label'])." — {$row['value']}";
    }

    /**
     * A dialogue's speakers who were given their own dialect: theirs is the one
     * spoken on their lines, not the main one. Empty when none were.
     */
    public static function speakerAccents(mixed $dialogue, ?string $language = null, ?string $tier = null): string
    {
        $parts = [];
        foreach ((array) (is_array($dialogue) ? ($dialogue['speakers'] ?? []) : []) as $speaker) {
            $code = is_array($speaker) ? trim((string) ($speaker['dialect'] ?? '')) : '';
            if ($code !== '') {
                $parts[] = trim((string) ($speaker['name'] ?? '?')).': '.self::accent($code, $language, $tier);
            }
        }

        return $parts === [] ? '' : 'Spoken by the speakers in their own dialect — '.implode('; ', $parts);
    }

    /**
     * Every accent on offer today, for admin filters: code => "مصري (قاهري) · Egyptian (Cairene)".
     *
     * @return array<string, string>
     */
    public static function accentOptions(): array
    {
        $options = [];
        foreach (['ar', 'en'] as $language) {
            foreach (self::inLocale('en', fn () => TtsStudioConfig::dialectCatalogForLanguage($language)) as $row) {
                $options[(string) $row['value']] = self::bilingual((string) $row['label_ar'], (string) $row['label']);
            }
        }

        return $options;
    }

    public static function performanceStyle(?string $value): string
    {
        return self::catalogLabel($value, fn () => TtsStudioConfig::performanceStyleCatalog());
    }

    public static function emotion(?string $value): string
    {
        return self::catalogLabel($value, fn () => TtsStudioConfig::emotionCatalog());
    }

    public static function voice(?string $voiceName): string
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return '—';
        }

        if (str_starts_with($voiceName, 'cloned_')) {
            $id = (int) substr($voiceName, strlen('cloned_'));
            $name = ClonedVoice::withTrashed()->whereKey($id)->value('name');

            return 'Cloned voice: '.($name !== null && trim((string) $name) !== '' ? trim((string) $name) : 'deleted')." (#{$id})";
        }

        $meta = VoiceCatalog::voiceMeta($voiceName);
        if ($meta === null) {
            return $voiceName;
        }

        $label = self::bilingual((string) ($meta['label_ar'] ?? ''), (string) ($meta['label'] ?? ''));
        $gender = trim((string) ($meta['gender'] ?? ''));

        return $label.($gender !== '' ? ' ('.ucfirst($gender).')' : '')." — {$voiceName}";
    }

    public static function tier(?string $tier): string
    {
        if (trim((string) $tier) === '') {
            return '—';
        }

        $normalized = VoiceTier::normalize((string) $tier);

        return VoiceTier::label($normalized)." — {$normalized}";
    }

    public static function language(?string $language): string
    {
        return match (strtolower(trim((string) $language))) {
            'ar' => 'العربية · Arabic',
            'en' => 'English',
            '' => '—',
            default => (string) $language,
        };
    }

    /**
     * @param  callable(): array<int, array{value:string,label:string,label_ar:string}>  $catalog
     */
    private static function catalogLabel(?string $value, callable $catalog): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }

        $english = self::findRow(self::inLocale('en', $catalog), $value);
        if ($english === null) {
            return $value;
        }

        // The defaults keep Arabic in the translation files, so read the catalog in Arabic too.
        $arabic = self::findRow(self::inLocale('ar', $catalog), $value);
        $labelAr = $arabic['label'] ?? $english['label_ar'];

        return self::bilingual($labelAr, $english['label']);
    }

    /**
     * @return array<int, array{value:string,label:string,label_ar:string}>
     */
    private static function dialectRows(?string $language, string $code): array
    {
        $language = strtolower(trim((string) $language));
        if (! in_array($language, ['ar', 'en'], true)) {
            $language = str_starts_with(strtolower($code), 'en') ? 'en' : 'ar';
        }

        return self::inLocale('en', fn () => TtsStudioConfig::dialectCatalogForLanguage($language));
    }

    /**
     * @param  array<int, array{value:string,label:string,label_ar:string}>  $rows
     * @return array{value:string,label:string,label_ar:string}|null
     */
    private static function findRow(array $rows, string $value): ?array
    {
        $needle = str_replace('_', '-', $value);
        foreach ($rows as $row) {
            if (strcasecmp(str_replace('_', '-', (string) $row['value']), $needle) === 0) {
                return $row;
            }
        }

        return null;
    }

    private static function bilingual(string $arabic, string $english): string
    {
        $arabic = trim($arabic);
        $english = trim($english);

        if ($arabic === '' || $arabic === $english) {
            return $english;
        }

        return $english === '' ? $arabic : "{$arabic} · {$english}";
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function inLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
