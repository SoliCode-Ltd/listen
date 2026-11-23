<?php

namespace App\Support\Tashkeel;

/**
 * How far a marked Arabic text (tashkeel) is from its reference, counted the
 * way that matters for a voice:
 *
 *  - "inside" errors: a wrong or missing mark on any letter but the last.
 *    These change the word that is said (عِلْم / عَلَم / عَلِمَ).
 *  - "ending" errors: only the last letter's mark differs (the case ending,
 *    i'rab). A voice that stops on the word says most of them the same.
 *
 * Both sides are first brought to one spelling of the marks (canonical()),
 * so two correct ways of writing the same sounds are not counted apart.
 * Only letters the reference marks are scored: the reference is the policy
 * for which marks are written at all. A mark the candidate adds on a letter
 * the reference leaves bare is counted apart, as "extra".
 */
final class TashkeelScore
{
    /** Fathatan … sukun, and the dagger alef. */
    public const MARKS = '\x{064B}-\x{0652}\x{0670}';

    private const SUN = 'تثدذرزسشصضطظلن';

    /**
     * @return array{words: int, letters: int, inside_words: int, ending_words: int, wrong_marks: int, wrong_marks_inside: int, extra_marks: int, letters_changed: bool, misses: list<array{word: string, got: string, kind: string}>}
     */
    public static function compare(string $reference, string $candidate): array
    {
        $out = ['words' => 0, 'letters' => 0, 'inside_words' => 0, 'ending_words' => 0, 'wrong_marks' => 0, 'wrong_marks_inside' => 0,
            'extra_marks' => 0, 'letters_changed' => false, 'misses' => []];
        $ref = self::words($reference);
        $got = self::words($candidate);
        // A candidate that changed, dropped or added a letter is not a marking of this text.
        $out['letters_changed'] = array_column($ref, 'bare') !== array_column($got, 'bare');
        $byBare = [];
        foreach ($got as $i => $word) {
            $byBare[$word['bare']][] = $i;
        }
        foreach ($ref as $i => $word) {
            if ($word['marked'] === 0) {
                continue;
            }
            // Its own place when the letters agree; else the next unused word with the same letters.
            $j = ! $out['letters_changed'] ? $i : (isset($byBare[$word['bare']]) ? array_shift($byBare[$word['bare']]) : null);
            $other = $j !== null ? $got[$j] : ['units' => [], 'text' => ''];
            $out['words']++;
            $inside = $ending = false;
            $last = self::endingIndex($word['units']);
            foreach ($word['units'] as $k => [$letter, $marks]) {
                $theirs = $other['units'][$k][1] ?? '';
                if ($marks === '') {
                    $out['extra_marks'] += $theirs !== '' ? 1 : 0;

                    continue;
                }
                $out['letters']++;
                if ($marks !== $theirs) {
                    $out['wrong_marks']++;
                    if ($k === $last) {
                        $ending = true;
                    } else {
                        $inside = true;
                        $out['wrong_marks_inside']++;
                    }
                }
            }
            if ($inside) {
                $out['inside_words']++;
            } elseif ($ending) {
                $out['ending_words']++;
            }
            if ($inside || $ending) {
                $out['misses'][] = ['word' => $word['text'], 'got' => $other['text'], 'kind' => $inside ? 'inside' : 'ending'];
            }
        }

        return $out;
    }

    /**
     * One spelling for the same sounds: marks in one order (shadda first), no
     * mark where the letters already say it (a long vowel's own letter, the
     * fatha before an alef, the alef-lam, the shadda a sun letter takes after
     * it), tanween fath on the letter before its alef, no tatweel.
     */
    public static function canonical(string $text): string
    {
        $m = self::MARKS;
        $text = (string) preg_replace('/\x{0640}/u', '', $text);
        // Marks on one letter in one order: shadda, then the vowel.
        $text = (string) preg_replace_callback('/['.$m.']{2,}/u', function (array $x): string {
            $marks = array_unique(mb_str_split($x[0]));
            usort($marks, fn (string $a, string $b): int => ($a === "\u{0651}" ? 0 : 1) <=> ($b === "\u{0651}" ? 0 : 1) ?: strcmp($a, $b));

            return implode('', $marks);
        }, $text);
        // «كتاباً» and «كتابًا» are the same.
        $text = (string) preg_replace('/([اى])\x{064B}/u', "\u{064B}$1", $text);
        // The alef-lam: no marks on it, and none needed on the sun letter's doubling.
        $text = (string) preg_replace('/(?<![\p{L}'.$m.'])([وفبكل]['.$m.']*)?[اٱ]['.$m.']*ل['.$m.']*(?=\p{Arabic})/u', '$1ال', $text);
        $text = (string) preg_replace('/(?<![\p{L}'.$m.'])((?:[وفبكل]['.$m.']*)?ال)(['.self::SUN.'])\x{0651}/u', '$1$2', $text);
        // Long vowels: the letter says it. فَا → فا, فِي → في, فُو → فو, and no sukun on the long letter.
        $text = (string) preg_replace(['/\x{064E}(?=ا)/u', '/\x{0650}(?=ي(?!['.$m.']))/u', '/\x{064F}(?=و(?!['.$m.']))/u'], '', $text);
        $text = (string) preg_replace('/(?<=[اوي])\x{0652}/u', '', $text);
        $text = (string) preg_replace('/(?<=ا)['.$m.']+/u', '', $text);

        return $text;
    }

    public static function strip(string $text): string
    {
        return (string) preg_replace('/['.self::MARKS.'\x{0640}]/u', '', $text);
    }

    /**
     * The Arabic words of a text, each as its letters with their marks.
     *
     * @return list<array{text: string, bare: string, marked: int, units: list<array{0: string, 1: string}>}>
     */
    public static function words(string $text): array
    {
        $words = [];
        preg_match_all('/[\p{Arabic}'.self::MARKS.']+/u', self::canonical($text), $found);
        foreach ($found[0] as $word) {
            preg_match_all('/([^'.self::MARKS.'])(['.self::MARKS.']*)/u', $word, $units, PREG_SET_ORDER);
            $list = array_map(fn (array $u): array => [$u[1], $u[2]], $units);
            if ($list === [] || ! preg_match('/\p{L}/u', $word)) {
                continue;
            }
            $words[] = [
                'text' => $word,
                'bare' => implode('', array_column($list, 0)),
                'marked' => count(array_filter($list, fn (array $u): bool => $u[1] !== '')),
                'units' => $list,
            ];
        }

        return $words;
    }

    /**
     * The letter that carries the case ending: the last one, or the one before
     * a final bare alef that only seats a tanween («كتابًا»).
     *
     * @param  list<array{0: string, 1: string}>  $units
     */
    private static function endingIndex(array $units): int
    {
        $last = count($units) - 1;
        if ($last > 0 && in_array($units[$last][0], ['ا', 'ى'], true) && $units[$last][1] === '' && str_contains($units[$last - 1][1], "\u{064B}")) {
            return $last - 1;
        }

        return $last;
    }
}
