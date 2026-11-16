<?php

namespace App\Support\Agents;

/**
 * Numbers in what an agent (or a caller) said, written as numbers.
 *
 * A call's transcript holds the words as they were spoken: an agent reads a
 * phone number back digit by digit ("صفر خمسة صفر واحد…", "five five five,
 * two one zero…"). On the page they read better as the number itself
 * (0501234567, 555 210 4876). Only a run of three or more spoken digits is a
 * number, so "الساعة أربعة" or "one more thing" stay as they are.
 *
 * isolate() keeps a number with spaces or dashes in one left-to-right piece
 * on an Arabic page, where "050 123 4567" would otherwise show as
 * "4567 123 050".
 */
final class SpokenNumbers
{
    /**
     * Arabic digit words, Modern Standard and dialect spellings. Egyptians read their mobile
     * prefix as a number (010 is "زيرو عشرة", 011 "صفر حداشر"), so those words are digits too.
     */
    private const AR = [
        '0' => ['صفر', 'زيرو'],
        '1' => ['واحد'],
        // تنين / ثنين: Levantine and Gulf.
        '2' => ['اثنين', 'إثنين', 'اتنين', 'اثنان', 'إثنان', 'اتنان', 'تنين', 'ثنين', 'تنتين'],
        '3' => ['ثلاثة', 'ثلاثه', 'تلاتة', 'تلاته', 'ثلاث', 'تلات'],
        '4' => ['أربعة', 'اربعة', 'أربعه', 'اربعه', 'أربع', 'اربع'],
        '5' => ['خمسة', 'خمسه', 'خمس'],
        '6' => ['ستة', 'سته', 'ست'],
        '7' => ['سبعة', 'سبعه', 'سبع'],
        '8' => ['ثمانية', 'ثمانيه', 'تمانية', 'تمانيه', 'تمنية', 'تمنيه', 'ثماني', 'تماني', 'ثمان', 'تمن'],
        '9' => ['تسعة', 'تسعه', 'تسع'],
        '10' => ['عشرة', 'عشره'],
        '11' => ['حداشر', 'احداشر', 'إحداشر'],
        '12' => ['اتناشر', 'إتناشر', 'اطناشر', 'اثناشر'],
        '15' => ['خمستاشر', 'خمسطاشر'],
    ];

    /** English digit words ("oh" is zero inside a number). */
    private const EN = [
        'zero' => '0', 'oh' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
    ];

    /** Spoken digits written as numbers (Arabic and English), the rest left as it was said. */
    public static function digits(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // Arabic-Indic digits (٠١٢, ۰۱۲) as the site writes every number: 012.
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        // Arabic: a run of digit words, separated by spaces, commas or dashes, becomes one number.
        $map = [];
        foreach (self::AR as $digit => $words) {
            foreach ($words as $word) {
                $map[$word] = (string) $digit;
            }
        }
        // Longest spellings first, so ثمانية is not read as ثمان + ية.
        uksort($map, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $word = implode('|', array_map(fn ($w) => preg_quote($w, '/'), array_keys($map)));
        // Whole words only: letters (not ؟ or ، which are Arabic too) may not touch them.
        $arWord = '(?<![\p{L}\p{Mn}])(?:'.$word.')(?![\p{L}\p{Mn}])';
        $text = preg_replace_callback('/'.$arWord.'(?:[\s،,\-]+'.$arWord.'){2,}/u', function (array $m) use ($map, $arWord) {
            preg_match_all('/'.$arWord.'/u', $m[0], $found);

            return implode('', array_map(fn ($w) => $map[$w], $found[0]));
        }, $text) ?? $text;

        // English: digit words (and "double"/"triple") make a number; the groups the speaker made
        // with commas stay as groups, with a space between.
        $enWord = '(?:(?:double|triple)\s+)?(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)';
        $text = preg_replace_callback('/\b'.$enWord.'(?:(?:\s*[,\-]\s*|\s+)'.$enWord.'){2,}\b/i', function (array $m) {
            $groups = preg_split('/\s*,\s*/', $m[0]) ?: [$m[0]];
            $out = [];
            foreach ($groups as $group) {
                $digits = '';
                preg_match_all('/(?:(double|triple)\s+)?\b(zero|oh|one|two|three|four|five|six|seven|eight|nine)\b/i', $group, $parts, PREG_SET_ORDER);
                foreach ($parts as $p) {
                    $d = self::EN[strtolower($p[2])];
                    $digits .= str_repeat($d, match (strtolower($p[1] ?? '')) {
                        'double' => 2, 'triple' => 3, default => 1
                    });
                }
                $out[] = $digits;
            }

            return implode(' ', array_filter($out, fn ($g) => $g !== ''));
        }, $text) ?? $text;

        // Single digits joined by dashes (5-5-5-2-1-0): one number.
        $text = preg_replace_callback('/(?<![\d\-])\d(?:-\d){2,}(?![\d\-])/', fn ($m) => str_replace('-', '', $m[0]), $text) ?? $text;

        // A number the transcription spaced out digit by digit ("0 10 6 8 1 2 1 7 0 5"): one number.
        // On an Arabic line each spaced digit is laid out right to left, so it read backwards
        // (01068121705 showed as "5 0 7 1 2 1 8 6 10 0"). Groups the speaker made (087 123 4567)
        // have three digits or more and stay as they are.
        return preg_replace_callback('/(?<![\d+])\d{1,2}(?:[ \x{00A0}]+\d{1,2}){3,}(?!\d)/u', function (array $m) {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';

            return strlen($digits) >= 6 ? $digits : $m[0];
        }, $text) ?? $text;
    }

    /**
     * A line as a page shows it: the transcription's own marks taken out (<no speech detected>,
     * {pause}), spoken digits written as numbers, and on an Arabic page every spaced number kept
     * in its order.
     */
    public static function forDisplay(string $text, bool $rtl = false): string
    {
        $text = self::withoutMarks($text);
        $text = trim(preg_replace('/\s+/u', ' ', self::digits($text)) ?? $text);

        return $rtl ? self::isolate($text) : $text;
    }

    /**
     * What nobody said taken out of a line: the transcription's marks (<no speech detected>,
     * {pause}) and a tool call the model wrote out instead of making it
     * (<metadata>{"action": "end_call", …}</metadata>, seen once on a test call). Braces may nest.
     * The same rules as public/js/agent-call.js.
     */
    public static function withoutMarks(string $text): string
    {
        $text = preg_replace([
            '/<!--.*?-->/su',
            '/<(metadata|tool_call|function_call)\b[^>]*>.*?<\/\1>/isu',
            '/<(metadata|tool_call|function_call)\b[^>]*>.*$/isu',
            '/<[^>]*>/u',
        ], '', $text) ?? $text;
        for ($i = 0; $i < 3 && preg_match('/\{[^{}]*\}/u', $text); $i++) {
            $text = preg_replace('/\{[^{}]*\}/u', '', $text) ?? $text;
        }

        return str_replace(['{', '}'], '', $text);
    }

    /**
     * A number with spaces or dashes inside (a phone number) kept in one left-to-right piece,
     * so an Arabic line shows it in its own order.
     */
    public static function isolate(string $text): string
    {
        // An international number (+966501234567) too: its plus sign would otherwise move to the other end.
        return preg_replace('/(?<![\p{L}\p{N}\x{2066}])(?:\+\d{6,15}|\+?\d[\d\- ]*[\- ]\d[\d\- ]*\d)(?![\p{L}\p{N}])/u', "\u{2066}$0\u{2069}", $text) ?? $text;
    }
}
