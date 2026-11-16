<?php

namespace App\Services\Transcription;

use App\Models\Transcription;
use App\Services\TranslationService;
use App\Support\Transcription\TranscriptionSettings;

/**
 * What a member does with a finished transcript, none of it using minutes:
 * rename it, name its speakers, correct a paragraph (its sentences placed
 * again within the paragraph's own time), and translate it into another
 * language (the site's translator, paragraph by paragraph; each translated
 * paragraph keeps the time of the one it translates, so its subtitles fit).
 */
final class TranscriptEditor
{
    public function __construct(private TranslationService $translator) {}

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
        $source = (string) ($t->language ?: 'en');
        $paragraphs = [];
        $segments = [];
        foreach ((array) $t->paragraphs as $i => $p) {
            $text = $this->translator->maybeTranslate((string) $p['text'], $source, $language, true);
            $paragraph = ['speaker' => $p['speaker'], 'text' => $text, 'start' => (float) $p['start'], 'end' => (float) $p['end']];
            $paragraphs[] = $paragraph;
            array_push($segments, ...self::spread($paragraph, $i));
        }
        $translation = ['paragraphs' => $paragraphs, 'segments' => $segments, 'at' => now()->toIso8601String()];
        $t->forceFill(['translations' => array_merge((array) ($t->translations ?? []), [$language => $translation])])->save();

        return $translation;
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
        return array_values(array_unique(array_merge(['ar', 'en'], array_keys(TranscriptionSettings::LANGUAGES))));
    }
}
