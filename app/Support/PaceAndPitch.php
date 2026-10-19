<?php

namespace App\Support;

/**
 * What the premium voices (stock and cloned) are told for the studio's speed
 * and pitch. The speech engine has no speed or pitch setting, so each step is
 * a written direction, graded so a bigger step reads as a bigger change.
 * 1.0x and pitch 0 send nothing.
 *
 * The studio's sliders move in steps of 0.1x from 0.5x to 2.0x, and of 1 from
 * -10 to +10 (semitones, as the standard voices read the same slider). The
 * developer API takes any value in those ranges: it is read at the nearest step.
 */
final class PaceAndPitch
{
    /**
     * By speed step.
     *
     * @var array<string, string>
     */
    public const SPEED = [
        '0.5' => 'Speak very slowly and deliberately, at about half a normal pace, taking pauses between phrases.',
        '0.6' => 'Speak slowly and deliberately, about 40% slower than normal, with short pauses between phrases.',
        '0.7' => 'Speak at a slow, measured pace, about 30% slower than normal.',
        '0.8' => 'Speak noticeably slower than a normal pace, about 20% slower.',
        '0.9' => 'Speak slightly slower than a normal pace, about 10% slower.',
        '1.1' => 'Speak slightly faster than a normal pace, about 10% faster.',
        '1.2' => 'Speak noticeably faster than a normal pace, about 20% faster.',
        '1.3' => 'Speak at a faster pace with energetic delivery, about 30% faster than normal.',
        '1.4' => 'Speak at a faster pace with brisk, energetic delivery, about 40% faster than normal.',
        '1.5' => 'Speak fast, with brisk, energetic delivery, at about one and a half times a normal pace.',
        '1.6' => 'Speak very quickly with rapid, energetic pacing, about 60% faster than normal.',
        '1.7' => 'Speak very quickly with rapid, energetic pacing, about 70% faster than normal.',
        '1.8' => 'Speak very quickly with rapid, energetic pacing, about 80% faster than normal.',
        '1.9' => 'Speak very quickly with rapid, energetic pacing, about 90% faster than normal.',
        '2.0' => 'Speak very quickly with rapid, energetic pacing, at about twice a normal pace.',
    ];

    /**
     * By pitch step.
     *
     * @var array<int, string>
     */
    public const PITCH = [
        -10 => 'Use a very deep, low-pitched voice, about 10 semitones below its natural pitch.',
        -9 => 'Use a much deeper, lower-pitched voice, about 9 semitones below its natural pitch.',
        -8 => 'Use a much deeper, lower-pitched voice, about 8 semitones below its natural pitch.',
        -7 => 'Use a much deeper, lower-pitched voice, about 7 semitones below its natural pitch.',
        -6 => 'Use a deeper, lower-pitched voice, about 6 semitones below its natural pitch.',
        -5 => 'Use a deeper, lower-pitched voice, about 5 semitones below its natural pitch.',
        -4 => 'Use a deeper, lower-pitched voice, about 4 semitones below its natural pitch.',
        -3 => "Use a noticeably lower pitch than the voice's natural pitch, about 3 semitones lower.",
        -2 => "Use a slightly lower pitch than the voice's natural pitch, about 2 semitones lower.",
        -1 => "Use a slightly lower pitch than the voice's natural pitch, about 1 semitone lower.",
        1 => "Use a slightly higher pitch than the voice's natural pitch, about 1 semitone higher.",
        2 => "Use a slightly higher pitch than the voice's natural pitch, about 2 semitones higher.",
        3 => "Use a noticeably higher pitch than the voice's natural pitch, about 3 semitones higher.",
        4 => 'Use a higher-pitched, brighter voice, about 4 semitones above its natural pitch.',
        5 => 'Use a higher-pitched, brighter voice, about 5 semitones above its natural pitch.',
        6 => 'Use a higher-pitched, brighter voice, about 6 semitones above its natural pitch.',
        7 => 'Use a much higher-pitched, brighter voice, about 7 semitones above its natural pitch.',
        8 => 'Use a much higher-pitched, brighter voice, about 8 semitones above its natural pitch.',
        9 => 'Use a much higher-pitched, brighter voice, about 9 semitones above its natural pitch.',
        10 => 'Use a very high-pitched, bright voice, about 10 semitones above its natural pitch.',
    ];

    /**
     * The direction for a speed, or '' at 1.0x (or no speed at all).
     */
    public static function speed(float $speed): string
    {
        if ($speed <= 0) {
            return '';
        }

        return self::SPEED[number_format(round(min(2.0, max(0.5, $speed)), 1), 1, '.', '')] ?? '';
    }

    /**
     * The direction for a pitch, or '' at 0.
     */
    public static function pitch(float $pitch): string
    {
        return self::PITCH[(int) round(min(10.0, max(-10.0, $pitch)))] ?? '';
    }
}
