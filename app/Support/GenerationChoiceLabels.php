<?php

namespace App\Support;

use App\Models\AudioGeneration;
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
     * The accent the audio was spoken in. A dialogue speaker given their own dialect
     * speaks it on their lines: one accent when everyone spoke the same, else one per speaker.
     */
    public static function spokenAccent(AudioGeneration $generation): string
    {
        $speakers = self::speakers($generation);
        if ($speakers === []) {
            return trim((string) $generation->dialect) !== ''
                ? self::accent($generation->dialect, $generation->target_language, $generation->voice_tier)
                : '—';
        }

        $accents = array_values(array_unique(array_column($speakers, 'accent')));

        return count($accents) === 1
            ? $accents[0]
            : implode('; ', array_map(fn (array $s): string => $s['name'].': '.$s['accent'], $speakers));
    }

    /**
     * Who spoke a dialogue: each speaker's name, the voice that read their lines
     * (resolved the way the speech request resolves it) and the accent they spoke.
     *
     * @return list<array{name: string, voice: string, accent: string}>
     */
    public static function speakers(AudioGeneration $generation): array
    {
        if (! $generation->is_dialogue || ! is_array($generation->dialogue_metadata)) {
            return [];
        }

        $language = (string) $generation->target_language;
        $tier = VoiceTier::normalize((string) $generation->voice_tier);
        $provider = VoiceCatalog::providerForName((string) $generation->voice_name);
        $speakers = [];
        foreach ((array) ($generation->dialogue_metadata['speakers'] ?? []) as $speaker) {
            if (! is_array($speaker)) {
                continue;
            }
            $resolved = DialogueSpeakers::forSpeech([$speaker], $language, $tier, $provider)[0] ?? [];
            $dialect = trim((string) ($speaker['dialect'] ?? '')) ?: trim((string) $generation->dialect);
            $speakers[] = [
                'name' => trim((string) ($speaker['name'] ?? $speaker['speaker'] ?? '')) ?: 'Speaker',
                'voice' => self::voice((string) ($resolved['voice_name'] ?? $speaker['voice_name'] ?? $speaker['voice'] ?? '')),
                'accent' => $dialect !== '' ? self::accent($dialect, $language, $generation->voice_tier) : '—',
            ];
        }

        return $speakers;
    }

    /**
     * The voice that spoke: a dialogue's speakers' voices, else the chosen voice.
     *
     * @return list<string>
     */
    public static function spokenVoices(AudioGeneration $generation): array
    {
        $speakers = self::speakers($generation);

        return $speakers === []
            ? [self::voice($generation->voice_name)]
            : array_map(fn (array $s): string => $s['name'].': '.$s['voice'], $speakers);
    }

    /**
     * The sound environment the audio was placed in, e.g. "ملعب · Stadium — stadium".
     */
    public static function environment(?string $effect): string
    {
        $effect = trim((string) $effect);
        if ($effect === '' || ! AudioEffects::isValid($effect)) {
            return $effect === '' ? 'None' : "Unknown environment — {$effect}";
        }

        return self::bilingual(AudioEffects::label($effect, true), AudioEffects::label($effect, false))." — {$effect}";
    }

    /**
     * Every studio setting the audio was made with that differs from the default,
     * one per line, for the generations list. Standard voices have none.
     *
     * @return list<string>
     */
    public static function settings(AudioGeneration $generation): array
    {
        $lines = [];
        $style = strtolower(trim((string) $generation->performance_style));
        if ($style !== '' && $style !== 'neutral') {
            $lines[] = 'Style: '.self::performanceStyle($style);
        }
        $emotion = strtolower(trim((string) $generation->emotion));
        if ($emotion !== '' && $emotion !== 'neutral') {
            $lines[] = 'Emotion: '.self::emotion($emotion);
        }
        if ($generation->speed !== null && abs((float) $generation->speed - 1.0) > 0.001) {
            $lines[] = 'Speed: '.(float) $generation->speed.'x';
        }
        if ($generation->pitch !== null && abs((float) $generation->pitch) > 0.001) {
            $lines[] = 'Pitch: '.(float) $generation->pitch;
        }
        if (filled($generation->style_prompt)) {
            $lines[] = 'Direction: '.\Illuminate\Support\Str::limit(trim((string) $generation->style_prompt), 80);
        }
        $effect = (string) (($generation->settings_snapshot ?? [])['audio_effect'] ?? '');
        if ($effect !== '') {
            $lines[] = 'Environment: '.self::environment($effect);
        }

        return $lines;
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
