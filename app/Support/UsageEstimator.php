<?php

namespace App\Support;

class UsageEstimator
{
    /**
     * Characters a member is charged for: every character of the text that is
     * read aloud, spaces, line breaks, punctuation and tags included, because
     * the speech provider bills all of them.
     */
    public static function billableChars(string $text): int
    {
        return mb_strlen(trim($text));
    }

    /**
     * @return array{chars:int,words:int,seconds:int,minutes:int}
     */
    public static function estimate(string $text): array
    {
        $plain = trim($text);
        $chars = self::billableChars($plain);

        preg_match_all('/[\p{L}\p{N}\']+/u', $plain, $matches);
        $wordCount = is_array($matches[0] ?? null) ? count($matches[0]) : 0;

        // About 15 characters (spaces included) or 2.6 words are spoken a second.
        $seconds = max(3, (int) ceil(max($wordCount / 2.6, $chars / Credits::CHARS_PER_SECOND)));
        $minutes = max(1, (int) ceil($seconds / 60));

        return [
            'chars' => $chars,
            'words' => $wordCount,
            'seconds' => $seconds,
            'minutes' => $minutes,
        ];
    }
}
