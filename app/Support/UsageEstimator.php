<?php

namespace App\Support;

class UsageEstimator
{
    /**
     * @return array{chars:int,words:int,seconds:int,minutes:int}
     */
    public static function estimate(string $text): array
    {
        $plain = trim(strip_tags($text));
        $chars = max(0, mb_strlen($plain));

        preg_match_all('/[\p{L}\p{N}\']+/u', $plain, $matches);
        $wordCount = is_array($matches[0] ?? null) ? count($matches[0]) : 0;

        $wordEstimate = max($wordCount, (int) ceil($chars / 5));
        $seconds = max(3, (int) ceil($wordEstimate / 2.6));
        $minutes = max(1, (int) ceil($seconds / 60));

        return [
            'chars' => $chars,
            'words' => $wordEstimate,
            'seconds' => $seconds,
            'minutes' => $minutes,
        ];
    }
}
