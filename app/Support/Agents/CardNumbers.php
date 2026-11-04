<?php

namespace App\Support\Agents;

/**
 * Card numbers a caller reads out, kept out of what Listen stores: in a call's words and in the
 * notes the agent saves, a run of 15 to 19 digits (spaces or dashes between them allowed, Latin or
 * Arabic digits) that passes the card checksum becomes "•••• 1234". Agents never ask for a card
 * (AgentInstructions), but callers volunteer them, and a stored card number puts the business's
 * call records in scope of card-security rules. Phone numbers are 15 digits at most and rarely
 * pass the checksum, so they stay as they are.
 */
final class CardNumbers
{
    private const DIGIT = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';

    public static function mask(string $text): string
    {
        if (! preg_match('/'.self::DIGIT.'/u', $text)) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/(?<!'.self::DIGIT.')(?:'.self::DIGIT.'[ \-]?){14,18}'.self::DIGIT.'(?!'.self::DIGIT.')/u',
            function (array $m): string {
                $digits = self::latin(preg_replace('/[ \-]/u', '', $m[0]) ?? '');
                $n = strlen($digits);

                return $n >= 15 && $n <= 19 && self::luhn($digits) ? '•••• '.substr($digits, -4) : $m[0];
            },
            $text,
        );
    }

    private static function latin(string $digits): string
    {
        return strtr($digits, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
