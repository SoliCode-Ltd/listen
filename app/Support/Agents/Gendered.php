<?php

namespace App\Support\Agents;

use Illuminate\Support\Facades\Lang;

/**
 * Arabic words that follow the agent's voice: "سارة تتحدث", "جاهزة متى شئت" for a woman's
 * voice. A line with a woman's form has a twin key ending in _female in the language file.
 */
final class Gendered
{
    /**
     * @param  array<string, string>  $lines
     * @return array<string, string> the same lines, each in the voice's form, without the _female twins
     */
    public static function lines(array $lines, bool $female): array
    {
        foreach ($lines as $key => $text) {
            if (str_ends_with((string) $key, '_female')) {
                if ($female) {
                    $lines[substr((string) $key, 0, -7)] = $text;
                }
                unset($lines[$key]);
            }
        }

        return $lines;
    }

    /** One line in the voice's form: agents.talk_hint, or agents.talk_hint_female for a woman's voice. */
    public static function line(string $key, bool $female, array $replace = []): string
    {
        return (string) __($female && Lang::has($key.'_female') ? $key.'_female' : $key, $replace);
    }
}
