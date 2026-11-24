<?php

namespace App\Support\Tashkeel;

/**
 * The words of an Arabic text a voice could say wrongly, each with the
 * reading the sentence means: a word with several readings (علم، عقد، كتب)
 * or a name whose vowels the letters do not give (جدة، الخبر، عمر).
 *
 * A text model finds them (prompt, parse); nothing it says is trusted
 * (accept): a reading that adds, drops or changes a letter, a word that is
 * not in the text as written, a word the writer already marked inside, and a
 * word the member has saved a pronunciation for are all left out. What is kept
 * is put to the voice in two ways at once (apply, direction), because the
 * listening rounds found marks alone a hint, and marks with the meaning told
 * the surest: the word is marked in the text, and the voice is told what it
 * means and how it is said. The rest of the text stays as written.
 *
 * Makes no request by itself: the caller gives the function that asks the
 * model, so this can be measured and tested without one.
 */
final class WordReadings
{
    /** The most words one piece of text may carry: a longer direction is read less closely. */
    public const MAX = 8;

    /**
     * @param  callable(string): ?string  $ask  sends the prompt, returns the model's answer (null when it failed)
     * @param  list<string>  $keep  spellings the member saved, to be left alone
     * @return list<array{word: string, marked: string, said: string, means: string}>
     */
    public static function find(string $text, ?string $variety, callable $ask, array $keep = []): array
    {
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return [];
        }
        $raw = $ask(self::prompt($text, $variety));

        return is_string($raw) ? self::accept($text, self::parse($raw), $keep) : [];
    }

    /** $variety: what the text is written in, in English ("Egyptian Arabic"); null or empty for Fusha. */
    public static function prompt(string $text, ?string $variety = null): string
    {
        $variety = trim((string) $variety);

        return "You prepare Arabic text for a text-to-speech voice. Arabic is written without its short vowels, so some words can be said in more than one way.\n\n"
            ."List ONLY the words in the text below that a careful reader could say wrongly:\n"
            ."- a word with more than one common reading, where the sentence decides which (علم: ʿilm, ʿalam or ʿalima; عقد: ʿaqd or ʿiqd; كتب: kataba, kutiba or kutub);\n"
            ."- a name of a person, a place or a brand whose vowels its letters do not give (جدة، الخبر، عمر، حمد).\n"
            ."Leave out every word that has one natural reading, and every word that already carries vowel marks.\n\n"
            .($variety !== ''
                ? "The text is written in {$variety}: give that variety's own vowels, not those of Fusha.\n\n"
                : "The text is Modern Standard Arabic (Fusha).\n\n")
            ."For each word give:\n"
            ."- \"word\": the word exactly as it is written in the text, with any prefix attached to it, without punctuation;\n"
            ."- \"marked\": the same letters with every vowel mark inside the word (fatha, damma, kasra, sukun, shadda), for the reading this sentence means. Never add, drop or change a letter. Leave the last letter's case ending off;\n"
            ."- \"said\": how it is said, in Latin letters;\n"
            ."- \"means\": what it means here, in two to five English words.\n\n"
            .'Answer with a JSON array only, at most '.self::MAX." items, in the order the words appear. When no word qualifies, answer [].\n\n"
            ."Text:\n«{$text}»";
    }

    /**
     * The model's answer as a list of entries; anything that is not one is dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        // An answer wrapped in a code fence or in a sentence: the array inside it.
        if (preg_match('/\[.*\]/su', $raw, $m)) {
            $raw = $m[0];
        }
        $list = json_decode($raw, true);
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * The entries that are safe to use on this text.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $keep
     * @return list<array{word: string, marked: string, said: string, means: string}>
     */
    public static function accept(string $text, array $entries, array $keep = []): array
    {
        $kept = array_map(fn (string $w): string => TashkeelScore::strip($w), array_values(array_filter($keep, 'is_string')));
        $marks = '/['.TashkeelScore::MARKS.']/u';
        $out = [];
        foreach ($entries as $entry) {
            $word = trim((string) ($entry['word'] ?? ''));
            $marked = trim((string) ($entry['marked'] ?? ''));
            $said = self::plain((string) ($entry['said'] ?? ''));
            $means = self::plain((string) ($entry['means'] ?? ''));
            $bare = TashkeelScore::strip($word);
            if (count($out) >= self::MAX) {
                break;
            }
            if ($bare === '' || $marked === '' || $said === '' || $means === ''
                // One word of Arabic letters. A mark of its own is allowed only at its end (كتاباً).
                || ! preg_match('/^\p{Arabic}+$/u', $bare) || ! self::markedOnlyAtEnd($word)
                // The reading is these letters and nothing else, and it does mark them.
                || ! preg_match('/^[\p{Arabic}'.TashkeelScore::MARKS.']+$/u', $marked)
                || TashkeelScore::strip($marked) !== $bare || ! preg_match($marks, $marked)
                // The member's own spelling of a word is not ours to change.
                || in_array($bare, $kept, true)
                || isset($out[$bare])
                // In the text as a whole word, as written.
                || ! preg_match(self::whole($word), $text)) {
                continue;
            }
            $out[$bare] = ['word' => $word, 'marked' => self::keepingOwnMarks($word, $marked), 'said' => $said, 'means' => $means];
        }

        return array_values($out);
    }

    /**
     * The text with those words marked (the first unmarked time each appears), and what to tell the voice.
     *
     * @param  list<array{word: string, marked: string, said: string, means: string}>  $readings
     * @return array{text: string, direction: string}
     */
    public static function apply(string $text, array $readings): array
    {
        foreach ($readings as $reading) {
            $text = (string) preg_replace_callback(self::whole($reading['word']), fn (): string => $reading['marked'], $text, 1);
        }

        return ['text' => $text, 'direction' => self::direction($readings)];
    }

    /**
     * What the voice is told, in the wording the listening rounds measured.
     *
     * @param  list<array{word: string, marked: string, said: string, means: string}>  $readings
     */
    public static function direction(array $readings): string
    {
        if ($readings === []) {
            return '';
        }
        $parts = array_map(
            fn (array $r): string => 'the word written «'.$r['word'].'» means "'.$r['means'].'" and is said "'.$r['said'].'"',
            $readings,
        );

        return 'In this text '.implode('; ', $parts).'. Say '.(count($parts) === 1 ? 'that word' : 'those words').' exactly that way.';
    }

    /**
     * Each letter with the marks on it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function units(string $word): array
    {
        preg_match_all('/([^'.TashkeelScore::MARKS.'])(['.TashkeelScore::MARKS.']*)/u', $word, $found, PREG_SET_ORDER);

        return array_map(fn (array $u): array => [$u[1], $u[2]], $found);
    }

    /** No mark before the word's last two letters: an ending the writer put (كتاباً) is not a reading. */
    private static function markedOnlyAtEnd(string $word): bool
    {
        $units = self::units($word);
        foreach (array_slice($units, 0, max(0, count($units) - 2)) as [, $marks]) {
            if ($marks !== '') {
                return false;
            }
        }

        return true;
    }

    /** The reading's marks, except where the writer already put one: theirs stays. */
    private static function keepingOwnMarks(string $word, string $marked): string
    {
        $own = self::units($word);
        $out = '';
        foreach (self::units($marked) as $k => [$letter, $marks]) {
            $out .= $letter.(($own[$k][1] ?? '') !== '' ? $own[$k][1] : $marks);
        }

        return $out;
    }

    /** The word standing alone: no letter or mark on either side of it. */
    private static function whole(string $word): string
    {
        return '/(?<![\p{L}\p{M}])'.preg_quote($word, '/').'(?![\p{L}\p{M}])/u';
    }

    /** A short plain line for the direction: no quotes or line breaks the model may have put in. */
    private static function plain(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\s"«»]+/u', ' ', $value)), 0, 60);
    }
}
