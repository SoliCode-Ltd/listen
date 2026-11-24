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
 * means and how it is said. The rest of the text stays as written. In a
 * dialect text only names are touched: a voice already says a dialect's own
 * words right, so marking them could only do harm. What a text is written in
 * is not what its voice is set to (members write Egyptian for a Fusha voice):
 * the model says which it is with its answer, and the text is treated as
 * Fusha only when most answers call it that.
 *
 * The model does not answer the same way twice: asked once, it listed رفع in
 * two runs of a passage and skipped it in the third, and the voice then read
 * that word wrongly. So it is asked several times (ASKS) and a word is used
 * only when most of the answers give it, with the same reading (agreed); the
 * words kept are put in the order of the text, so the limit always falls on
 * the same ones.
 *
 * Makes no request by itself: the caller gives the function that asks the
 * model, so this can be measured and tested without one.
 */
final class WordReadings
{
    /** The most words one piece of text may carry: a longer direction is read less closely. */
    public const MAX = 8;

    /** How many times the model is asked about one text: a word is used when most of the answers agree on it. */
    public const ASKS = 3;

    /**
     * @param  callable(string, int): ?string  $ask  sends the prompt, returns the model's answer (null when it failed);
     *                                               its second argument counts the asks from 0 (a caller may seed the model with it)
     * @param  list<string>  $keep  spellings the member saved, to be left alone
     * @return list<array{word: string, marked: string, said: string, means: string, kind: string}>
     */
    public static function find(string $text, ?string $variety, callable $ask, array $keep = [], int $asks = self::ASKS): array
    {
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return [];
        }
        $prompt = self::prompt($text, $variety);
        $answers = [];
        for ($k = 0; $k < max(1, $asks); $k++) {
            $raw = $ask($prompt, $k);
            if (is_string($raw)) {
                $answers[] = $raw;
            }
        }
        $refused = [];

        // A variety the caller knows (a voice set to a dialect) settles it; otherwise the answers say what the text is written in.
        return self::agreed($text, $answers, $keep, $refused, trim((string) $variety) !== '');
    }

    /**
     * What several answers to the same prompt agree on: a word is kept when more
     * than half of the answers give it the same reading (one answer alone decides
     * when it is the only one). Kept in the order of the text, up to the limit.
     *
     * @param  list<string>  $answers  the model's answers as they came (the ones that failed left out)
     * @param  list<string>  $keep
     * @param  array<string, int>  $refused
     * @param  bool  $namesOnly  the caller knows the text is in a dialect
     * @return list<array{word: string, marked: string, said: string, means: string, kind: string}>
     */
    public static function agreed(string $text, array $answers, array $keep = [], array &$refused = [], bool $namesOnly = false): array
    {
        // In a dialect only names are touched: the voice already says the dialect's own words right.
        $namesOnly = $namesOnly || self::writtenIn($answers) === 'dialect';
        $votes = [];
        foreach ($answers as $raw) {
            foreach (self::accept($text, self::parse($raw), $keep, $refused, $namesOnly, PHP_INT_MAX) as $reading) {
                $bare = TashkeelScore::strip($reading['word']);
                // With the answers that gave this word the same reading (the ending and a sukun make no difference).
                $with = null;
                foreach ($votes[$bare] ?? [] as $g => $given) {
                    if (self::sameReading($given[0]['marked'], $reading['marked'])) {
                        $with = $g;
                        break;
                    }
                }
                if ($with === null) {
                    $votes[$bare][] = [$reading];
                } else {
                    $votes[$bare][$with][] = $reading;
                }
            }
        }
        $need = intdiv(count($answers), 2) + 1;
        $kept = [];
        foreach ($votes as $bare => $byReading) {
            // The reading most answers gave; between two as often given, the one given first.
            $most = [];
            foreach ($byReading as $given) {
                if (count($given) > count($most)) {
                    $most = $given;
                }
            }
            if (count($most) < $need) {
                $refused['not agreed between the answers'] = ($refused['not agreed between the answers'] ?? 0) + 1;

                continue;
            }
            preg_match(self::whole($most[0]['word']), $text, $found, PREG_OFFSET_CAPTURE);
            $kept[] = [$found[0][1] ?? PHP_INT_MAX, $most[0]];
        }
        usort($kept, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        if (count($kept) > self::MAX) {
            $refused['over the limit'] = ($refused['over the limit'] ?? 0) + count($kept) - self::MAX;
        }

        return array_column(array_slice($kept, 0, self::MAX), 1);
    }

    /**
     * What the answers say the text is written in: "fusha" when more than half of
     * those that say call it that, "dialect" otherwise (a tie is a dialect: the
     * careful side), null when none says.
     *
     * @param  list<string>  $answers
     */
    public static function writtenIn(array $answers): ?string
    {
        $said = array_values(array_filter(array_map(fn (string $raw): ?string => self::decode($raw)['written_in'], $answers), 'is_string'));
        if ($said === []) {
            return null;
        }
        $fusha = count(array_filter($said, fn (string $v): bool => $v === 'fusha'));

        return $fusha >= intdiv(count($said), 2) + 1 ? 'fusha' : 'dialect';
    }

    /** $variety: what the caller knows the text is written in, in English ("Egyptian Arabic"); null or empty when it does not know. */
    public static function prompt(string $text, ?string $variety = null): string
    {
        $variety = trim((string) $variety);
        $names = 'list ONLY names of people, places and brands; leave every other word alone, however it could be read. Give a name the vowels it has in that dialect.';

        return "You prepare Arabic text for a text-to-speech voice. Arabic is written without its short vowels, so a few words can be said as two different words.\n\n"
            .($variety !== ''
                ? "The text is written in {$variety}, a spoken dialect, and the voice already knows it: {$names}\n\n"
                : "First decide what the text is written in, from its own words and grammar and not from its subject: Modern Standard Arabic (Fusha), or a spoken dialect (Egyptian, Levantine, Gulf or another). A text that mixes Fusha with a dialect counts as that dialect.\n\n"
                    ."If the text is in a dialect, the voice already knows that dialect: {$names}\n\n"
                    ."If the text is Fusha, list ONLY the words in it that the voice could say as the wrong word:\n"
                    ."- a word whose letters spell two or more different, common words, where the sentence decides which (علم: ʿilm, ʿalam or ʿalima; عقد: ʿaqd or ʿiqd; كتب: kataba, kutiba or kutub);\n"
                    ."- a name of a person, a place or a brand whose vowels its letters do not give (جدة، الخبر، عمر، حمد).\n"
                    ."Leave out everything else: a word with one natural reading (في، كان، المدينة، كبير), a word only because it is long, foreign or colloquial, and a word that already carries vowel marks inside it. Most sentences have none, one or two such words.\n\n")
            ."For each word give:\n"
            ."- \"word\": the word copied exactly as it is written in the text, with any prefix attached to it and any mark it already has, without punctuation;\n"
            ."- \"marked\": the same letters with every vowel mark inside the word (fatha, damma, kasra, sukun, shadda), for the reading this sentence means. Never add, drop or change a letter, and do not stretch the word with a tatweel. Leave the last letter's case ending off;\n"
            ."- \"said\": how it is said, in Latin letters;\n"
            ."- \"means\": what it means here, in two to five English words;\n"
            ."- \"kind\": \"name\" for the name of a person, a place or a brand, \"word\" for anything else.\n\n"
            .'Answer with a JSON object only: {"written_in": "fusha", "egyptian", "levantine", "gulf" or "other dialect", "words": [the words, at most '.self::MAX.", in the order they appear]}. When no word qualifies, \"words\" is [].\n\n"
            ."Text:\n«{$text}»";
    }

    /**
     * The model's answer as a list of entries; anything that is not one is dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $raw): array
    {
        return self::decode($raw)['words'];
    }

    /**
     * The answer read: what it says the text is written in ("fusha", or the dialect it names;
     * null when it does not say), and its entries. An answer wrapped in a code fence or in a
     * sentence is read too, and so is a bare list of entries.
     *
     * @return array{written_in: ?string, words: list<array<string, mixed>>}
     */
    private static function decode(string $raw): array
    {
        $raw = trim($raw);
        $object = preg_match('/\{.*\}/su', $raw, $m) ? json_decode($m[0], true) : null;
        if (is_array($object) && array_key_exists('words', $object)) {
            $in = strtolower(trim((string) (is_string($object['written_in'] ?? null) ? $object['written_in'] : '')));
            $in = $in === '' ? null : (preg_match('/fusha|standard|msa|classical/', $in) ? 'fusha' : $in);

            return ['written_in' => $in, 'words' => array_values(array_filter((array) $object['words'], 'is_array'))];
        }
        $list = preg_match('/\[.*\]/su', $raw, $m) ? json_decode($m[0], true) : null;

        return ['written_in' => null, 'words' => is_array($list) ? array_values(array_filter($list, 'is_array')) : []];
    }

    /**
     * The entries that are safe to use on this text.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $keep
     * @param  array<string, int>  $refused  why entries were left out, counted (for measuring)
     * @param  bool  $namesOnly  a dialect text: only names are used
     * @param  int  $limit  the most entries kept, in the order given
     * @return list<array{word: string, marked: string, said: string, means: string, kind: string}>
     */
    public static function accept(string $text, array $entries, array $keep = [], array &$refused = [], bool $namesOnly = false, int $limit = self::MAX): array
    {
        $kept = array_map(fn (string $w): string => TashkeelScore::strip($w), array_values(array_filter($keep, 'is_string')));
        $marks = '/['.TashkeelScore::MARKS.']/u';
        $out = [];
        foreach ($entries as $entry) {
            $word = trim((string) ($entry['word'] ?? ''));
            // A tatweel is not a letter: the reading never stretches the word.
            $marked = str_replace("\u{0640}", '', trim((string) ($entry['marked'] ?? '')));
            $said = self::plain((string) ($entry['said'] ?? ''));
            $means = self::plain((string) ($entry['means'] ?? ''));
            $bare = TashkeelScore::strip($word);
            // Anything the model does not call a name is a word.
            $kind = strtolower(trim((string) ($entry['kind'] ?? ''))) === 'name' ? 'name' : 'word';
            $why = match (true) {
                count($out) >= $limit => 'over the limit',
                $namesOnly && $kind !== 'name' => 'a word in a dialect text',
                $bare === '' || $marked === '' || $said === '' || $means === '' => 'incomplete',
                // One word of Arabic letters. A mark of its own is allowed only at its end (كتاباً).
                ! preg_match('/^\p{Arabic}+$/u', $bare) => 'not one Arabic word',
                ! self::markedOnlyAtEnd($word) => 'marked by the writer',
                // The reading is these letters and nothing else, and it does mark them.
                ! preg_match('/^[\p{Arabic}'.TashkeelScore::MARKS.']+$/u', $marked) || TashkeelScore::strip($marked) !== $bare => 'letters changed',
                ! preg_match($marks, $marked) => 'no marks given',
                // The member's own spelling of a word is not ours to change.
                in_array($bare, $kept, true) => 'saved by the member',
                isset($out[$bare]) => 'given twice',
                // In the text as a whole word, as written.
                ! preg_match(self::whole($word), $text) => 'not in the text as written',
                default => null,
            };
            if ($why !== null) {
                $refused[$why] = ($refused[$why] ?? 0) + 1;

                continue;
            }
            $out[$bare] = ['word' => $word, 'marked' => self::keepingOwnMarks($word, $marked), 'said' => $said, 'means' => $means, 'kind' => $kind];
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

    /** Two markings of a word that are one reading: neither has a vowel inside the word that the other contradicts or leaves open. */
    private static function sameReading(string $a, string $b): bool
    {
        return TashkeelScore::reading($a, $b) === 'same' && TashkeelScore::reading($b, $a) === 'same';
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

    /**
     * The reading's marks, except where the writer already put one: theirs stays.
     * A tanween the writer put on the final alef (عقداً) is the word's one tanween:
     * the reading's own, on the letter before, is dropped rather than doubled.
     */
    private static function keepingOwnMarks(string $word, string $marked): string
    {
        $own = self::units($word);
        $units = self::units($marked);
        foreach ($units as $k => [$letter, $marks]) {
            if (($own[$k][1] ?? '') === '') {
                continue;
            }
            $units[$k][1] = $own[$k][1];
            if ($k > 0 && str_contains($own[$k][1], "\u{064B}")) {
                $units[$k - 1][1] = str_replace("\u{064B}", '', $units[$k - 1][1]);
            }
        }

        return implode('', array_map(fn (array $u): string => $u[0].$u[1], $units));
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
