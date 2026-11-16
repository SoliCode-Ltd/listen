<?php

namespace App\Support\Transcription;

/**
 * A length of audio in the words the page uses: "2 min 10 s", "45 s",
 * "1 h 05 min" (Arabic: "2 د 10 ث"). Speech to text counts by the second. The
 * month's minutes are said in minutes only, as the studio says its own
 * ("239 min of 250 min"): $hours false.
 */
final class Minutes
{
    public static function format(int $seconds, ?string $locale = null, bool $hours = true): string
    {
        $seconds = max(0, $seconds);
        $h = $hours ? intdiv($seconds, 3600) : 0;
        $m = $hours ? intdiv($seconds % 3600, 60) : intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return __('transcribe.time.hours_minutes', ['h' => $h, 'm' => str_pad((string) $m, 2, '0', STR_PAD_LEFT)], $locale);
        }
        if ($m > 0) {
            return $s > 0 ? __('transcribe.time.minutes_seconds', ['m' => $m, 's' => $s], $locale) : __('transcribe.time.minutes', ['m' => $m], $locale);
        }

        return __('transcribe.time.seconds', ['s' => $s], $locale);
    }
}
