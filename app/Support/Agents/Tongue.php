<?php

namespace App\Support\Agents;

/**
 * Which language a line of a call is in, from its letters and its small everyday words:
 *
 *   ar     Arabic letters
 *   short  too few letters to tell (a name, "OK")
 *   latin  Latin letters but no clear language: Arabic written in Latin letters ("tfaḍḍal,
 *          kammil su'alak"), names, an address
 *   en     English
 *   other  another language: Spanish, French, Portuguese, Italian, German (from their words,
 *          their accents and ¿ ¡), or another alphabet (Cyrillic, Hebrew, Devanagari, CJK…)
 *
 * A test call on live: after three minutes an Arabic agent said a line in Spanish ("Déjame
 * revisar la información…"). The call client (public/js/agent-call.js, tongue()) uses the same
 * rules to stop such a line as it starts and have it said again in the call's language.
 * Words that are also Arabic in Latin letters (el, la, de, ya, nos, hay…) are left out.
 */
final class Tongue
{
    public const EUROPEAN = ['que', 'qué', 'los', 'las', 'por', 'para', 'una', 'está', 'están', 'puedo', 'puede', 'usted',
        'gracias', 'hola', 'también', 'pero', 'cómo', 'cuándo', 'dónde', 'muy', 'más', 'información', 'ahora', 'momento',
        'ayudar', 'ayudarte', 'je', 'vous', 'nous', 'est', 'sont', 'les', 'des', 'une', 'pour', 'avec', 'pas', 'oui',
        'merci', 'bonjour', 'très', 'votre', 'você', 'não', 'obrigado', 'obrigada', 'uma', 'sono', 'della', 'grazie',
        'ich', 'und', 'ist', 'nicht', 'bitte', 'danke'];

    public const ENGLISH = ['the', 'and', 'you', 'your', 'are', 'is', 'would', 'can', 'have', 'with', 'this', 'that',
        'what', 'please', 'thank', 'thanks', 'hello', 'our', 'will', 'for', "it's", "i'm", "don't", 'help', 'sure', 'let', 'check'];

    public static function of(string $text): string
    {
        $arabic = preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $text);
        $latin = preg_match_all('/[A-Za-z\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}]/u', $text);
        $script = preg_match_all('/[\x{0370}-\x{03FF}\x{0400}-\x{04FF}\x{0590}-\x{05FF}\x{0900}-\x{097F}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text);
        if ($script >= 4 && $script > $arabic && $script > $latin) {
            return 'other';
        }
        if ($arabic >= $latin) {
            return 'ar';
        }
        if ($latin < 8) {
            return 'short';
        }
        $lower = mb_strtolower($text);
        $words = array_flip(preg_split('/[^\p{L}\']+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $eu = count(array_intersect_key($words, array_flip(self::EUROPEAN)));
        $en = count(array_intersect_key($words, array_flip(self::ENGLISH)));
        $eu += preg_match('/[¿¡ñçßãõœ]/u', $lower) ? 2 : 0;
        $eu += preg_match_all('/[áéíóúàèìòùâêîôûäëïöü]/u', $lower) >= 2 ? 1 : 0;
        if ($eu >= 2 && $eu > $en) {
            return 'other';
        }

        return $en >= 2 ? 'en' : 'latin';
    }

    /**
     * Whether an agent line is in a language it should not speak: never another language; an
     * Arabic agent speaks English only after the caller did, and an English agent Arabic (a
     * caller line in Latin letters may be either: Arabic written in them, or English).
     *
     * @param  string  $own  the agent's language: ar or en
     * @param  string  $caller  the language of the caller's last line (Tongue::of)
     */
    public static function wrong(string $line, string $own, string $caller): bool
    {
        return match (self::of($line)) {
            'other' => true,
            'en' => $own !== 'en' && $caller !== 'en' && $caller !== 'latin',
            'ar' => $own === 'en' && $caller !== 'ar' && $caller !== 'latin',
            default => false,
        };
    }
}
