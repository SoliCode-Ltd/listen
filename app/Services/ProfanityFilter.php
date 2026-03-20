<?php

namespace App\Services;

class ProfanityFilter
{
    /**
     * @var array<int, array{language:string,spaced:string,compact:string,check_compact:bool}>
     */
    private array $compiledTerms;

    public function __construct()
    {
        $this->compiledTerms = $this->compileTerms((array) config('profanity.lists', []));
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

        $normalized = $this->normalize($text);
        if ($normalized['spaced'] === '  ' && $normalized['compact'] === '') {
            return null;
        }

        foreach ($this->compiledTerms as $entry) {
            if ($entry['spaced'] !== '' && str_contains($normalized['spaced'], $entry['spaced'])) {
                return [
                    'language' => $entry['language'],
                    'term' => trim($entry['spaced']),
                ];
            }

            if (
                $entry['check_compact']
                && $entry['compact'] !== ''
                && str_contains($normalized['compact'], $entry['compact'])
            ) {
                return [
                    'language' => $entry['language'],
                    'term' => $entry['compact'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $lists
     * @return array<int, array{language:string,spaced:string,compact:string,check_compact:bool}>
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
                $compact = $normalized['compact'];

                if ($compact === '') {
                    continue;
                }

                $compiled[] = [
                    'language' => (string) $language,
                    'spaced' => $normalized['spaced'],
                    'compact' => $compact,
                    'check_compact' => str_contains($rawTerm, ' ') || ((string) $language === 'ar' && mb_strlen($compact) >= 4),
                ];
            }
        }

        usort($compiled, static function (array $left, array $right): int {
            return mb_strlen($right['compact']) <=> mb_strlen($left['compact']);
        });

        return $compiled;
    }

    /**
     * @return array{spaced:string,compact:string}
     */
    private function normalize(string $text): array
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
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '7' => 't',
            '@' => 'a',
            '$' => 's',
        ]);

        $text = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        if ((bool) config('profanity.collapse_repeated_characters', true)) {
            $text = preg_replace('/(.)\1{2,}/u', '$1', $text) ?? $text;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return [
            'spaced' => ' '.$text.' ',
            'compact' => str_replace(' ', '', $text),
        ];
    }
}
