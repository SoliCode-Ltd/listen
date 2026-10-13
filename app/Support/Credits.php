<?php

namespace App\Support;

/**
 * Members spend credits: one credit per character read aloud, spaces, line
 * breaks and punctuation included, as the speech provider bills them. Each
 * credit also carries a share of voice minutes, so a plan's credits set both
 * how much text a member can send and how much audio they can make that
 * month, whichever runs out first.
 */
final class Credits
{
    /** Characters spoken a second, spaces and punctuation included. */
    public const CHARS_PER_SECOND = 15;

    /** Credits that make one minute of audio. */
    public const PER_MINUTE = self::CHARS_PER_SECOND * 60;

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
