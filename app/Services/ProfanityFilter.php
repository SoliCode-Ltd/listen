<?php

namespace App\Services;

class ProfanityFilter
{
    /**
     * Digits and symbols written in place of letters ("sh1t", "$hit").
     */
    private const LOOKALIKES = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '@' => 'a',
        '$' => 's',
    ];

    /**
     * @var array<int, array{language:string,term:string,match:string}>
     */
    private array $compiledTerms;

    /**
     * @var list<string>
     */
    private array $allowedPhrases;

    public function __construct()
    {
        $this->compiledTerms = $this->compileTerms((array) config('profanity.lists', []));
        $this->allowedPhrases = $this->compileAllowedPhrases((array) config('profanity.allowed_phrases', []));
    }

    public function containsProfanity(?string $text): bool
    {
        return $this->firstMatch($text) !== null;
    }

    /**
     * @return array{language:string,term:string}|null
     */
    public function firstMatch(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $normalized = $this->withoutAllowedPhrases($this->normalize($text));
        if (trim($normalized) === '') {
            return null;
        }

        // Whole words only: a term never matches inside a longer word, and a
        // phrase only as those words in a row (never across other words).
        foreach ($this->compiledTerms as $entry) {
            if (str_contains($normalized, $entry['match'])) {
                return [
                    'language' => $entry['language'],
                    'term' => $entry['term'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $lists
     * @return array<int, array{language:string,term:string,match:string}>
     */
    private function compileTerms(array $lists): array
    {
        $compiled = [];

        foreach ($lists as $language => $terms) {
            foreach ((array) $terms as $term) {
                $rawTerm = trim((string) $term);
                if ($rawTerm === '') {
                    continue;
                }

                $normalized = $this->normalize($rawTerm);
                $words = trim($normalized);
                if (! $this->longEnough($words)) {
                    continue;
                }

                $compiled[] = [
                    'language' => (string) $language,
                    'term' => $words,
                    'match' => $normalized,
                ];

                // A phrase run together into one word ("fuckyou") is that word.
                if (str_contains($words, ' ')) {
                    $compiled[] = [
                        'language' => (string) $language,
                        'term' => $words,
                        'match' => ' '.str_replace(' ', '', $words).' ',
                    ];
                }
            }
        }

        usort($compiled, static function (array $left, array $right): int {
            return mb_strlen($right['match']) <=> mb_strlen($left['match']);
        });

        return $compiled;
    }

    /**
     * Collapsing repeated letters must not leave a one- or two-letter term
     * ("xxx" becomes "x", which would match the letter on its own), so a term
     * needs three letters. Arabic writes no short vowels: a two-letter Arabic
     * word (كس) is a whole word and stays.
     */
    private function longEnough(string $words): bool
    {
        $letters = mb_strlen(str_replace(' ', '', $words));

        return $letters >= 3 || ($letters === 2 && preg_match('/^\p{Arabic}+$/u', $words) === 1);
    }

    /**
     * @param  array<int, string>  $phrases
     * @return list<string>
     */
    private function compileAllowedPhrases(array $phrases): array
    {
        $compiled = [];

        foreach ($phrases as $phrase) {
            $normalized = $this->normalize(trim((string) $phrase));
            if (trim($normalized) !== '') {
                $compiled[] = $normalized;
            }
        }

        return $compiled;
    }

    /**
     * Ordinary phrases that hold a listed word ("magna cum laude") are taken
     * out before the text is checked.
     */
    private function withoutAllowedPhrases(string $normalized): string
    {
        foreach ($this->allowedPhrases as $phrase) {
            while (str_contains($normalized, $phrase)) {
                $normalized = str_replace($phrase, ' ', $normalized);
            }
        }

        return $normalized;
    }

    /**
     * The text as lowercase words with one space between them and a space at
     * each end (" word word "), so terms are matched as whole words.
     */
    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        $text = strtr($text, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
            'گ' => 'ك',
            'ڤ' => 'ف',
        ]);

        $text = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;

        // Digits and symbols stand for letters only inside a word that also has
        // letters ("sh1t", "$hit"); numbers and prices (455, 717, 45$) stay numbers.
        $text = preg_replace_callback(
            '/[\p{L}\p{N}$@]+/u',
            static fn (array $word): string => preg_match('/\p{L}/u', $word[0]) === 1 ? strtr($word[0], self::LOOKALIKES) : $word[0],
            $text,
        ) ?? $text;

        $text = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text);

        // Letters spaced out one by one ("f u c k", "ك س") read as one word. A
        // single letter on its own stays a letter, and no term is one letter.
        $text = preg_replace_callback(
            '/(?<=^| )\p{L}(?: \p{L})+(?= |$)/u',
            static fn (array $letters): string => str_replace(' ', '', $letters[0]),
            $text,
        ) ?? $text;

        if ((bool) config('profanity.collapse_repeated_characters', true)) {
            $text = preg_replace('/(.)\1{2,}/u', '$1', $text) ?? $text;
        }

        return ' '.$text.' ';
    }
}
