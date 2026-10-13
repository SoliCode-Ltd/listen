<?php

namespace App\Support;

/**
 * Members spend credits: one credit per character read aloud (so balances,
 * gifts and packs keep their numbers). Each credit also carries a share of
 * voice minutes, so a plan's credits set both how much text a member can
 * send and how much audio they can make that month, whichever runs out first.
 */
final class Credits
{
    /** Credits that make one minute of audio (13 spoken characters a second). */
    public const PER_MINUTE = 780;

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
