<?php

namespace App\Support;

/**
 * Members spend credits on the audio they get: 15 credits a second of speech,
 * 900 a minute. A generation reserves credits from its text before it runs
 * and is corrected to the real length of the audio afterwards, so a plan's
 * credits and its voice minutes always match, and cost follows exactly what
 * the speech provider bills.
 */
final class Credits
{
    /** Characters spoken a second, spaces and punctuation included (for estimates). */
    public const CHARS_PER_SECOND = 15;

    /** Credits charged for each second of audio. */
    public const PER_SECOND = 15;

    /** Credits that make one minute of audio. */
    public const PER_MINUTE = self::PER_SECOND * 60;

    /** Credits charged for this many seconds of audio. */
    public static function forSeconds(int $seconds): int
    {
        return max(0, $seconds) * self::PER_SECOND;
    }

    /** Whole voice minutes the credits allow. */
    public static function minutesFor(int $credits): int
    {
        return intdiv(max(0, $credits), self::PER_MINUTE);
    }

    /** Seconds of audio the credits allow. */
    public static function secondsFor(int $credits): int
    {
        return intdiv(max(0, $credits) * 60, self::PER_MINUTE);
    }
}
