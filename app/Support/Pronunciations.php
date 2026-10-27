<?php

namespace App\Support;

use App\Models\Pronunciation;
use Illuminate\Support\Collection;

/**
 * A member's pronunciations: words they write one way and want said another
 * (with Arabic vowel marks, or spelled the way it sounds). Saved in the
 * studio's (or the demo's) pronunciation panel, applied to the text of every new
 * voice just before it is read: the studio, the demo, the browser extension and
 * the developer API (App\Support\SpeechText::forVoice).
 *
 * A word matches however it is written: with or without vowel marks, any form
 * of alef, ى/ي and ة/ه, any case; only whole words, never inside sound or
 * delivery tags. The panel's underline (public/js/voice-studio.js, pronPattern)
 * matches the same way.
 */
final class Pronunciations
{
    public const LIMIT = 200;

    public const WORD_MAX = 60;

    public const SPOKEN_MAX = 80;

    /** Arabic vowel marks, the dagger alef and the tatweel: none of them change which word it is. */
    private const MARKS = '\x{064B}-\x{065F}\x{0670}\x{0640}';

    /** Tags the voice reads as directions, left as they are: <laugh>, [calm]. */
    private const TAG = '/(<[^<>\n]{1,40}>|\[[^\[\]\n]{1,40}\])/u';

    /** The word as a key: no vowel marks, one form of alef, ي for ى, ه for ة, lower case, single spaces. */
    public static function key(string $word): string
    {
        $word = (string) preg_replace('/['.self::MARKS.']/u', '', $word);
        $word = strtr($word, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $word)));
    }

    /** Finds the word as a whole word, however it is written. */
    public static function pattern(string $word): ?string
    {
        $bare = trim((string) preg_replace('/['.self::MARKS.']/u', '', $word));
        if ($bare === '') {
            return null;
        }
        $body = '';
        foreach (mb_str_split($bare) as $char) {
            $body .= match (true) {
                str_contains('اأإآٱ', $char) => '[اأإآٱ]',
                str_contains('يى', $char) => '[يى]',
                str_contains('هة', $char) => '[هة]',
                (bool) preg_match('/\s/u', $char) => '\s+',
                default => preg_quote($char, '/'),
            }.'['.self::MARKS.']*';
        }

        return '/(?<![\p{L}\p{M}\p{N}])'.$body.'(?![\p{L}\p{M}\p{N}])/iu';
    }

    /**
     * Read fresh each time: the queue worker that makes the voice runs for days, and must
     * see a word the member changed a moment ago.
     *
     * @return Collection<int, Pronunciation> newest first
     */
    public static function forUser(?int $userId): Collection
    {
        if (! $userId) {
            return collect();
        }

        return Pronunciation::query()->where('user_id', $userId)->latest('id')->get();
    }

    /** @return list<array{id:int, word:string, spoken:string}> for the studio's panel */
    public static function panelItems(?int $userId): array
    {
        return self::forUser($userId)
            ->map(fn (Pronunciation $p): array => ['id' => (int) $p->id, 'word' => (string) $p->word, 'spoken' => (string) $p->spoken])
            ->values()
            ->all();
    }

    /**
     * The text with the member's words said their way. A dialogue's speaker labels
     * ("Sara:" at the start of a line) stay as written, so its turns are still found.
     *
     * @param  list<string>  $labels  the dialogue's speaker names
     * @return array{text: string, spoken: list<string>} spoken: the pronunciations used, for the voice's direction
     */
    public static function apply(string $text, ?int $userId, array $labels = []): array
    {
        $entries = self::forUser($userId);

        return $entries->isEmpty() ? ['text' => $text, 'spoken' => []] : self::applyEntries($text, $entries, $labels);
    }

    /**
     * A line for the voice's direction, when the text it reads has any of the member's
     * pronunciations: the speech model is told to keep them exactly as written.
     *
     * @param  list<string>  $spoken
     */
    public static function direction(string $text, array $spoken): string
    {
        $here = array_values(array_filter($spoken, fn (string $s): bool => $s !== '' && mb_strpos($text, $s) !== false));
        if ($here === []) {
            return '';
        }

        return 'Pronounce these words exactly as they are written, following every vowel mark and letter: «'
            .implode('», «', array_slice($here, 0, 20)).'».';
    }

    /**
     * @param  iterable<Pronunciation|array{word:string, spoken:string}>  $entries
     * @param  list<string>  $labels
     * @return array{text: string, spoken: list<string>}
     */
    public static function applyEntries(string $text, iterable $entries, array $labels = []): array
    {
        // Longer words first, so "Listen AI" is used before "Listen".
        $list = collect($entries)
            ->map(fn ($e): array => ['word' => (string) data_get($e, 'word'), 'spoken' => (string) data_get($e, 'spoken')])
            ->filter(fn (array $e): bool => trim($e['word']) !== '' && trim($e['spoken']) !== '')
            ->sortByDesc(fn (array $e): int => mb_strlen(self::key($e['word'])))
            ->values();
        if ($list->isEmpty() || trim($text) === '') {
            return ['text' => $text, 'spoken' => []];
        }

        $used = [];
        $keep = self::TAG;
        $labels = array_values(array_filter(array_map('trim', $labels), fn (string $l): bool => $l !== ''));
        if ($labels !== []) {
            $names = implode('|', array_map(fn (string $l): string => preg_quote($l, '/'), $labels));
            $keep = '/(<[^<>\n]{1,40}>|\[[^\[\]\n]{1,40}\]|(?m:^[ \t]*(?:'.$names.')[ \t]*:))/u';
        }
        $parts = preg_split($keep, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            // Odd parts are the tags themselves.
            if ($i % 2 === 1 || $part === '') {
                continue;
            }
            // A word once replaced is held aside, so a shorter word never matches inside it.
            $held = [];
            foreach ($list as $entry) {
                $pattern = self::pattern($entry['word']);
                if ($pattern === null) {
                    continue;
                }
                $part = (string) preg_replace_callback($pattern, function () use ($entry, &$held, &$used): string {
                    $held[] = $entry['spoken'];
                    $used[$entry['spoken']] = true;

                    return "\u{E000}".(count($held) - 1)."\u{E001}";
                }, $part);
            }
            $parts[$i] = (string) preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn (array $m): string => $held[(int) $m[1]], $part);
        }

        return ['text' => implode('', $parts), 'spoken' => array_keys($used)];
    }
}
