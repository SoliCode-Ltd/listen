<?php

namespace App\Support\Agents;

/**
 * One line of the install panel's steps, as safe HTML. In the language files `code` is a name
 * from the platform's screens or a piece of code (always shown left to right, whatever the page's
 * language); `A` > `B` is a path through the platform's menus (a chevron between the names);
 * long code (a file path, a line of config) gets its own line so it never breaks mid-sentence.
 */
final class StepText
{
    private const LONG = 30;

    public static function html(string $text): string
    {
        $parts = preg_split('/(`[^`]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $isCode = fn (?string $part): bool => $part !== null && mb_strlen($part) > 2 && str_starts_with($part, '`') && str_ends_with($part, '`');
        $html = '';
        foreach ($parts as $i => $part) {
            if ($isCode($part)) {
                $code = mb_substr($part, 1, -1);
                $long = mb_strlen($code) > self::LONG;
                $path = ! $long && trim($parts[$i + 1] ?? '') === '>' && $isCode($parts[$i + 2] ?? null);
                $class = $long ? ' class="is-long"' : ($path ? ' class="is-path"' : '');
                $html .= '<code dir="ltr"'.$class.'>'.e($code).'</code>';
            } elseif (trim($part) === '>' && $isCode($parts[$i - 1] ?? null) && mb_strlen($parts[$i - 1]) - 2 <= self::LONG && $isCode($parts[$i + 1] ?? null)) {
                // The chevron is drawn by the name before it; the space lets the path wrap between names.
                $html .= ' ';
            } else {
                $html .= e($part);
            }
        }

        return $html;
    }
}
