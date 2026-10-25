<?php

namespace App\Support;

/**
 * The homepage's frequently asked questions: what the studio is and what it
 * does, with every count from its catalogs (StudioFacts). The questions about
 * two-voice dialogue and voice cloning are asked only while a plan on sale has
 * them. The page shows these and its FAQ structured data holds the same.
 */
final class HomeFaq
{
    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public static function items(?string $locale = null): array
    {
        $locale = str_starts_with((string) ($locale ?? app()->getLocale()), 'ar') ? 'ar' : 'en';
        $ask = fn (string $key, array $replace = []): array => [
            'question' => (string) __("ui.home_faq.{$key}_q", [], $locale),
            'answer' => (string) __("ui.home_faq.{$key}_a", $replace, $locale),
        ];

        $items = [
            $ask('what'),
            $ask('dialects', [
                'dialects' => StudioFacts::arabicDialects(),
                'countries' => StudioFacts::arabicCountries(),
                'fusha' => StudioFacts::fushaStyles(),
                'english' => StudioFacts::englishAccents(),
                'total' => StudioFacts::roundedDown(StudioFacts::dialectsAndAccents()),
            ]),
            $ask('direct', ['emotions' => StudioFacts::emotions(), 'styles' => StudioFacts::styles()]),
        ];

        $dialoguePlans = StudioFacts::dialoguePlans($locale);
        if ($dialoguePlans !== []) {
            $items[] = $ask('dialogue', ['plans' => StudioFacts::planList($dialoguePlans, $locale)]);
        }
        $cloning = StudioFacts::cloningRange();
        if ($cloning !== null) {
            [$fewest, $most] = $cloning;
            $items[] = $ask('clone', ['voices' => $fewest === $most ? (string) $most : $fewest.($locale === 'ar' ? ' إلى ' : ' to ').$most]);
        }
        $items[] = $ask('api');
        $items[] = $ask('try');

        return $items;
    }
}
