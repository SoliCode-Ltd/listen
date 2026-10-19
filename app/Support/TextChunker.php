<?php

namespace App\Support;

/**
 * Splits text for speech APIs that limit each request, breaking at line
 * breaks and sentence ends first, then at commas, then at spaces, then
 * mid-word.
 */
class TextChunker
{
    /**
     * @param  callable(string):int  $measure  Size of a piece, e.g. strlen or mb_strlen.
     * @param  int  $together  How many pieces are read at the same time (0: all of them).
     * @return array<int, string>
     */
    public static function split(string $text, int $max, callable $measure, int $together = 0): array
    {
        $text = trim($text);
        if ($measure($text) <= $max) {
            return [$text];
        }

        $pieces = [];
        foreach (self::cut($text, '/((?<=[.!?؟؛…])\s+|\s*\n\s*)/u', ' ') as [$gap, $sentence]) {
            if ($measure($sentence) <= $max) {
                $pieces[] = [$gap, $sentence];
                continue;
            }
            // A long sentence breaks at its commas before its spaces, so the
            // pause between pieces falls between clauses, not inside one.
            foreach (self::cut($sentence, '/((?<=[،,:;])\s+)/u', $gap) as [$gap, $clause]) {
                if ($measure($clause) <= $max) {
                    $pieces[] = [$gap, $clause];
                    continue;
                }
                foreach (self::cut($clause, '/(\s+)/u', $gap) as [$gap, $word]) {
                    while ($measure($word) > $max) {
                        $head = self::headWithin($word, $max, $measure);
                        $pieces[] = [$gap, $head];
                        $gap = ' ';
                        $word = substr($word, strlen($head));
                    }
                    if ($word !== '') {
                        $pieces[] = [$gap, $word];
                    }
                }
            }
        }

        // The fewest pieces the limit allows. They are read $together at a
        // time and the longest of each round sets its wait, so the earlier
        // rounds stay full and the last round shares what is left evenly:
        // 1,000 characters become two of about 500, not 900 and 100.
        $chunks = self::fill($pieces, $max, $measure);
        $full = $together > 0 ? intdiv(count($chunks) - 1, $together) * $together : 0;
        $rest = array_slice($pieces, array_sum(array_column(array_slice($chunks, 0, $full), 'pieces')));
        $last = array_slice($chunks, $full);
        $low = 1;
        $high = $max;
        while ($low < $high) {
            $size = intdiv($low + $high, 2);
            $tried = self::fill($rest, $size, $measure);
            if ($tried !== null && count($tried) <= count($last)) {
                $high = $size;
                $even = $tried;
            } else {
                $low = $size + 1;
            }
        }

        return array_column(array_merge(array_slice($chunks, 0, $full), $even ?? $last), 'text');
    }

    /**
     * Pieces of text with the space that came before each: a line break
     * stays a line break, so paragraphs are still read as paragraphs.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private static function cut(string $text, string $pattern, string $firstGap): array
    {
        $out = [];
        $gap = $firstGap;
        foreach (preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $i => $part) {
            if ($i % 2 === 1) {
                $gap = str_contains($part, "\n") ? "\n" : ' ';
            } elseif ($part !== '') {
                $out[] = [$gap, $part];
            }
        }

        return $out;
    }

    /**
     * Join the pieces in order into chunks no bigger than the limit, each
     * with the number of pieces in it, or null when a piece alone is bigger.
     *
     * @param  array<int, array{0:string,1:string}>  $pieces
     * @return array<int, array{text:string,pieces:int}>|null
     */
    private static function fill(array $pieces, int $limit, callable $measure): ?array
    {
        $chunks = [];
        $current = ['text' => '', 'pieces' => 0];
        foreach ($pieces as [$gap, $piece]) {
            if ($measure($piece) > $limit) {
                return null;
            }
            $candidate = $current['pieces'] === 0 ? $piece : $current['text'].$gap.$piece;
            if ($measure($candidate) <= $limit) {
                $current = ['text' => $candidate, 'pieces' => $current['pieces'] + 1];
                continue;
            }
            $chunks[] = $current;
            $current = ['text' => $piece, 'pieces' => 1];
        }
        if ($current['pieces'] > 0) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function headWithin(string $word, int $max, callable $measure): string
    {
        $head = mb_strcut($word, 0, max(1, $max), 'UTF-8');
        while ($head !== '' && $measure($head) > $max) {
            $head = mb_substr($head, 0, mb_strlen($head) - 1);
        }

        return $head !== '' ? $head : mb_substr($word, 0, 1);
    }
}
