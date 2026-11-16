<?php

namespace App\Services\Transcription;

use App\Models\Transcription;
use App\Services\TranslationService;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Support\Facades\Cache;

/**
 * What a member does with a finished transcript, none of it using minutes:
 * rename it, name its speakers, correct a paragraph (its sentences placed
 * again within the paragraph's own time), and translate it into another
 * language (the site's translator, paragraph by paragraph; each translated
 * paragraph keeps the time of the one it translates, so its subtitles fit).
 */
final class TranscriptEditor
{
    /** Languages one transcript may be translated into. */
    public const LANGUAGES_EACH = 5;

    /** New translations one member may have made in a day, and their letters together. */
    public const NEW_A_DAY = 10;

    public const LETTERS_A_DAY = 500_000;

    public function __construct(private TranslationService $translator, private Transcriber $transcriber) {}

    /** Where a translation is kept (as TranslationService keeps one). */
    private static function keptKey(string $source, string $target, string $text): string
    {
        return 'listen:tr:v4:'.sha1($source.'|'.$target.'|'.trim($text));
    }

    public function rename(Transcription $t, string $title): void
    {
        $title = trim($title);
        if ($title !== '') {
            $t->forceFill(['title' => mb_substr($title, 0, 200)])->save();
        }
    }

    /** @param  array<string, string>  $names  speaker letter => the member's name for them (empty: back to "Speaker A") */
    public function nameSpeakers(Transcription $t, array $names): void
    {
        $letters = array_unique(array_column((array) $t->paragraphs, 'speaker'));
        $own = (array) ($t->speaker_names ?? []);
        foreach ($names as $letter => $name) {
            if (in_array((string) $letter, $letters, true)) {
                $name = mb_substr(trim((string) $name), 0, 60);
                if ($name === '') {
                    unset($own[$letter]);
                } else {
                    $own[$letter] = $name;
                }
            }
        }
        $t->forceFill(['speaker_names' => $own])->save();
    }

    /**
     * A paragraph corrected: its new words, cut into sentences again and
     * shared out over the paragraph's own time. Translations made before no
     * longer match, so they go (making one again costs nothing).
     */
    public function correct(Transcription $t, int $index, string $text): void
    {
        $paragraphs = (array) $t->paragraphs;
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        if (! isset($paragraphs[$index]) || $text === '') {
            return;
        }
        $paragraphs[$index]['text'] = mb_substr($text, 0, 20000);
        $others = array_values(array_filter((array) $t->segments, fn ($s) => (int) $s['paragraph'] !== $index));
        $placed = self::spread($paragraphs[$index], $index);
        $segments = array_merge($others, $placed);
        usort($segments, fn ($a, $b) => [$a['start'], $a['paragraph']] <=> [$b['start'], $b['paragraph']]);
        $t->forceFill(['paragraphs' => $paragraphs, 'segments' => $segments, 'translations' => null])->save();
    }

    /**
     * The transcript in $language, made once and kept (asking again returns it).
     *
     * @return array{paragraphs: array<int, array<string, mixed>>, segments: array<int, array<string, mixed>>}
     */
    public function translate(Transcription $t, string $language): array
    {
        $language = strtolower(trim($language));
        $kept = ($t->translations ?? [])[$language] ?? null;
        if (is_array($kept)) {
            return $kept;
        }
        // A new one: within what one transcript, and one member in a day, may have made. They cost the
        // site's translator (and, for what it cannot do, the speech model) and nothing of the minutes.
        $letters = array_sum(array_map(fn ($p): int => mb_strlen((string) ($p['text'] ?? '')), (array) $t->paragraphs));
        [$madeKey, $lettersKey] = self::dayKeys((int) $t->user_id);
        if (count((array) ($t->translations ?? [])) >= self::LANGUAGES_EACH) {
            throw new TranscriptionRefused('translation_limit', __('transcribe.refused.translations_each', ['n' => self::LANGUAGES_EACH]), 429);
        }
        if ((int) Cache::get($madeKey, 0) >= self::NEW_A_DAY) {
            throw new TranscriptionRefused('translation_limit', __('transcribe.refused.translations_day', ['n' => self::NEW_A_DAY]), 429);
        }
        if ((int) Cache::get($lettersKey, 0) + $letters > self::LETTERS_A_DAY) {
            throw new TranscriptionRefused('translation_limit', __('transcribe.refused.translations_letters'), 429);
        }
        $source = self::translatorCode((string) ($t->language ?: 'en'));
        // Every paragraph asked at once of the quick translator; what it could not do, of the text
        // model in a few requests sent together; anything still missing the careful way, one by one.
        $texts = array_map(fn ($p) => trim((string) ($p['text'] ?? '')), array_values((array) $t->paragraphs));
        $target = self::translatorCode($language);
        $this->translator->prefetch($texts, $source, $target);
        $missing = array_filter($texts, fn ($x) => $x !== '' && ! is_string(Cache::get(self::keptKey($source, $target, $x))));
        if ($missing !== []) {
            $done = $this->transcriber->translateTexts(array_values($missing), (string) ($t->language ?: 'en'), $language);
            foreach (array_values($missing) as $n => $text) {
                if (is_string($done[$n] ?? null)) {
                    Cache::put(self::keptKey($source, $target, $text), $done[$n], now()->addDays(14));
                }
            }
        }
        $paragraphs = [];
        $segments = [];
        foreach ((array) $t->paragraphs as $i => $p) {
            $text = $this->translator->maybeTranslate((string) $p['text'], $source, $target, true);
            $paragraph = ['speaker' => $p['speaker'], 'text' => $text, 'start' => (float) $p['start'], 'end' => (float) $p['end']];
            $paragraphs[] = $paragraph;
            array_push($segments, ...self::spread($paragraph, $i));
        }
        $translation = ['paragraphs' => $paragraphs, 'segments' => $segments, 'at' => now()->toIso8601String()];
        $t->forceFill(['translations' => array_merge((array) ($t->translations ?? []), [$language => $translation])])->save();
        foreach ([$madeKey => 1, $lettersKey => $letters] as $key => $add) {
            Cache::add($key, 0, now()->addDays(2));
            Cache::increment($key, $add);
        }

        return $translation;
    }

    /**
     * Where today's new translations of a member are counted, and their letters.
     *
     * @return array{0: string, 1: string}
     */
    private static function dayKeys(int $userId): array
    {
        $day = now()->format('Ymd');

        return ['transcribe-translations:'.$userId.':'.$day, 'transcribe-translated-letters:'.$userId.':'.$day];
    }

    /**
     * A paragraph's sentences shared out over its own time by their letters.
     *
     * @param  array<string, mixed>  $paragraph
     * @return array<int, array<string, mixed>>
     */
    public static function spread(array $paragraph, int $index): array
    {
        $start = (float) ($paragraph['start'] ?? 0);
        $end = max($start, (float) ($paragraph['end'] ?? $start));

        return Transcriber::placeOnSpeech(Transcriber::sentences([['speaker' => (string) $paragraph['speaker'], 'text' => (string) $paragraph['text']]], $index),
            [[$start, max($end, $start + 0.01)]], $start, $end);
    }

    /** Languages a transcript can be translated into: those members can choose, and the site's own two. */
    public static function targets(): array
    {
        return array_values(array_diff(array_keys(TranscriptionSettings::LANGUAGES), TranscriptionSettings::NOT_TRANSLATED));
    }

    /** The code the translator knows a language by (Filipino is "tl" there). */
    public static function translatorCode(string $code): string
    {
        return TranscriptionSettings::TRANSLATOR_CODES[$code] ?? $code;
    }
}
