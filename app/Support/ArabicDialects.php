<?php

namespace App\Support;

/**
 * Every Arabic dialect members can choose, grouped by region.
 *
 * Each has the direction given to the stock voices (prompt) and to cloned
 * voices (clone), which also get their script rewritten into the dialect.
 * Codes stay within 10 characters (the dialect columns).
 */
final class ArabicDialects
{
    /**
     * @var array<string, array{en: string, ar: string, region: string, prompt: string, clone: string}>
     */
    public const ALL = [
        'ar-XA' => ['en' => 'Modern Standard Arabic', 'ar' => 'فصحى', 'region' => 'fusha',
            'prompt' => 'Modern Standard Arabic (Fusha)',
            'clone' => 'Modern Standard Arabic (Fusha) with clear formal pronunciation, not the colloquial accent of the recording.'],

        'ar-SA' => ['en' => 'Saudi (Najdi)', 'ar' => 'سعودي (نجدي)', 'region' => 'gulf',
            'prompt' => 'Saudi Arabic (Najdi, Gulf dialect)',
            'clone' => 'Najdi Saudi colloquial Arabic, the way someone from Riyadh talks to a friend. Pronounce ج as the English j, never as the Egyptian hard g. Saudi Gulf rhythm and Saudi wording.'],
        'ar-SA-HJ' => ['en' => 'Saudi (Hejazi)', 'ar' => 'سعودي (حجازي)', 'region' => 'gulf',
            'prompt' => 'Hejazi Saudi Arabic (Jeddah and Mecca)',
            'clone' => 'Hejazi Saudi Arabic, the way someone from Jeddah talks. Hejazi rhythm and wording. Do not use an Egyptian accent.'],
        'ar-AE' => ['en' => 'Emirati', 'ar' => 'إماراتي', 'region' => 'gulf',
            'prompt' => 'Emirati Arabic (Gulf dialect)',
            'clone' => 'Emirati Gulf Arabic, the way someone from Dubai talks. Do not use an Egyptian accent.'],
        'ar-KW' => ['en' => 'Kuwaiti', 'ar' => 'كويتي', 'region' => 'gulf',
            'prompt' => 'Kuwaiti Arabic (Gulf dialect)',
            'clone' => 'Kuwaiti Gulf Arabic. Do not use an Egyptian accent.'],
        'ar-QA' => ['en' => 'Qatari', 'ar' => 'قطري', 'region' => 'gulf',
            'prompt' => 'Qatari Arabic (Gulf dialect)',
            'clone' => 'Qatari Gulf Arabic. Do not use an Egyptian accent.'],
        'ar-BH' => ['en' => 'Bahraini', 'ar' => 'بحريني', 'region' => 'gulf',
            'prompt' => 'Bahraini Arabic (Gulf dialect)',
            'clone' => 'Bahraini Gulf Arabic. Do not use an Egyptian accent.'],
        'ar-OM' => ['en' => 'Omani', 'ar' => 'عُماني', 'region' => 'gulf',
            'prompt' => 'Omani Arabic (Gulf dialect)',
            'clone' => 'Omani Arabic. Do not use an Egyptian accent.'],

        'ar-LB' => ['en' => 'Lebanese', 'ar' => 'لبناني', 'region' => 'levant',
            'prompt' => 'Lebanese Arabic (Levantine)',
            'clone' => 'Lebanese Levantine Arabic. Do not use an Egyptian accent.'],
        'ar-SY' => ['en' => 'Syrian', 'ar' => 'سوري', 'region' => 'levant',
            'prompt' => 'Syrian Arabic (Levantine, Damascene)',
            'clone' => 'Syrian Levantine Arabic. Do not use an Egyptian accent.'],
        'ar-JO' => ['en' => 'Jordanian', 'ar' => 'أردني', 'region' => 'levant',
            'prompt' => 'Jordanian Arabic (Levantine)',
            'clone' => 'Jordanian Levantine Arabic, the way someone from Amman talks. Do not use an Egyptian accent.'],
        'ar-PS' => ['en' => 'Palestinian', 'ar' => 'فلسطيني', 'region' => 'levant',
            'prompt' => 'Palestinian Arabic (Levantine)',
            'clone' => 'Palestinian Levantine Arabic. Do not use an Egyptian accent.'],

        'ar-EG' => ['en' => 'Egyptian (Cairene)', 'ar' => 'مصري (قاهري)', 'region' => 'nile',
            'prompt' => 'Egyptian Arabic (Cairene)',
            'clone' => 'Cairene Egyptian colloquial Arabic. Pronounce ج like the English g, with Cairo rhythm.'],
        'ar-EG-SA' => ['en' => 'Egyptian (Saidi)', 'ar' => 'مصري (صعيدي)', 'region' => 'nile',
            'prompt' => 'Saidi Egyptian Arabic (Upper Egypt)',
            'clone' => 'Saidi Upper-Egyptian Arabic, with the Saidi ق as a hard g and Upper Egypt rhythm and wording.'],
        'ar-SD' => ['en' => 'Sudanese', 'ar' => 'سوداني', 'region' => 'nile',
            'prompt' => 'Sudanese Arabic',
            'clone' => 'Sudanese Arabic. Do not use an Egyptian accent.'],

        'ar-IQ' => ['en' => 'Iraqi', 'ar' => 'عراقي', 'region' => 'iraq_yemen',
            'prompt' => 'Iraqi Arabic (Baghdadi)',
            'clone' => 'Iraqi Arabic. Do not use an Egyptian accent.'],
        'ar-YE' => ['en' => 'Yemeni', 'ar' => 'يمني', 'region' => 'iraq_yemen',
            'prompt' => 'Yemeni Arabic (Sanaani)',
            'clone' => 'Yemeni Arabic. Do not use an Egyptian accent.'],

        'ar-MA' => ['en' => 'Moroccan', 'ar' => 'مغربي', 'region' => 'maghreb',
            'prompt' => 'Moroccan Arabic (Darija, Maghrebi)',
            'clone' => 'Moroccan Arabic. Do not use an Egyptian accent or Modern Standard Arabic.'],
        'ar-DZ' => ['en' => 'Algerian', 'ar' => 'جزائري', 'region' => 'maghreb',
            'prompt' => 'Algerian Arabic (Darja, Maghrebi)',
            'clone' => 'Algerian Arabic. Do not use an Egyptian accent.'],
        'ar-TN' => ['en' => 'Tunisian', 'ar' => 'تونسي', 'region' => 'maghreb',
            'prompt' => 'Tunisian Arabic (Maghrebi)',
            'clone' => 'Tunisian Arabic. Do not use an Egyptian accent.'],
        'ar-LY' => ['en' => 'Libyan', 'ar' => 'ليبي', 'region' => 'maghreb',
            'prompt' => 'Libyan Arabic (Maghrebi)',
            'clone' => 'Libyan Arabic. Do not use an Egyptian accent.'],
        'ar-MR' => ['en' => 'Mauritanian (Hassaniya)', 'ar' => 'موريتاني (حساني)', 'region' => 'maghreb',
            'prompt' => 'Mauritanian Hassaniya Arabic',
            'clone' => 'Mauritanian Hassaniya Arabic. Do not use an Egyptian accent.'],
    ];

    /**
     * @return array<string, array{en: string, ar: string}>
     */
    public static function regions(): array
    {
        return [
            'fusha' => ['en' => 'Standard', 'ar' => 'الفصحى'],
            'gulf' => ['en' => 'Gulf', 'ar' => 'الخليج'],
            'levant' => ['en' => 'Levant', 'ar' => 'بلاد الشام'],
            'nile' => ['en' => 'Egypt & Sudan', 'ar' => 'مصر والسودان'],
            'iraq_yemen' => ['en' => 'Iraq & Yemen', 'ar' => 'العراق واليمن'],
            'maghreb' => ['en' => 'Maghreb', 'ar' => 'المغرب العربي'],
        ];
    }

    /**
     * Rows for the studio dialect catalog.
     *
     * @return array<int, array{value: string, label: string, label_ar: string, region: string}>
     */
    public static function catalogRows(): array
    {
        $rows = [];
        foreach (self::ALL as $code => $row) {
            $rows[] = ['value' => $code, 'label' => $row['en'], 'label_ar' => $row['ar'], 'region' => $row['region']];
        }

        return $rows;
    }

    public static function prompt(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['prompt'] ?? null;
    }

    public static function cloneInstruction(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['clone'] ?? null;
    }

    private static function normalize(?string $code): string
    {
        $code = trim(str_replace('_', '-', (string) $code));
        foreach (array_keys(self::ALL) as $known) {
            if (strcasecmp($known, $code) === 0) {
                return $known;
            }
        }

        return $code;
    }
}
