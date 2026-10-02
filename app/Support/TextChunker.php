<?php

namespace App\Support;

/**
 * Splits text for speech APIs that limit each request, breaking at line
 * breaks and sentence ends first, then at spaces, then mid-word.
 */
class TextChunker
{
    /**
     * @param  callable(string):int  $measure  Size of a piece, e.g. strlen or mb_strlen.
     * @return array<int, string>
     */
    public static function split(string $text, int $max, callable $measure): array
    {
        $text = trim($text);
        if ($measure($text) <= $max) {
            return [$text];
        }

        $pieces = [];
        foreach (preg_split('/(?<=[.!?؟؛…\n])\s+/u', $text) ?: [$text] as $sentence) {
            if ($measure($sentence) <= $max) {
                $pieces[] = $sentence;
                continue;
            }
            foreach (preg_split('/\s+/u', $sentence) ?: [$sentence] as $word) {
                while ($measure($word) > $max) {
                    $head = self::headWithin($word, $max, $measure);
                    $pieces[] = $head;
                    $word = substr($word, strlen($head));
                }
                if ($word !== '') {
                    $pieces[] = $word;
                }
            }
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current.' '.$piece;
            if ($measure($candidate) <= $max) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $chunks[] = $current;
            }
            $current = $piece;
        }
        if ($current !== '') {
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
