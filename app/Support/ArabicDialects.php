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
        'ar-SA-SO' => ['en' => 'Saudi (Southern)', 'ar' => 'سعودي (جنوبي)', 'region' => 'gulf',
            'prompt' => 'Southern Saudi Arabic (Asir and Jazan)',
            'clone' => 'Southern Saudi Arabic, the way someone from Abha or Jazan talks. Do not use an Egyptian accent.'],
        'ar-SA-QS' => ['en' => 'Saudi (Qassimi)', 'ar' => 'سعودي (قصيمي)', 'region' => 'gulf',
            'prompt' => 'Qassimi Saudi Arabic (Al-Qassim, Najdi)',
            'clone' => 'Qassimi Najdi Arabic, the way someone from Buraydah talks. Do not use an Egyptian accent.'],
        'ar-SA-EA' => ['en' => 'Saudi (Eastern)', 'ar' => 'سعودي (شرقاوي)', 'region' => 'gulf',
            'prompt' => 'Eastern Province Saudi Arabic (Al-Ahsa and Dammam)',
            'clone' => 'Eastern Saudi Arabic, the way someone from Al-Ahsa or Dammam talks. Do not use an Egyptian accent.'],
        'ar-SA-NO' => ['en' => 'Saudi (Northern)', 'ar' => 'سعودي (شمالي)', 'region' => 'gulf',
            'prompt' => 'Northern Saudi Arabic (Hail and Al-Jouf, Bedouin)',
            'clone' => 'Northern Saudi Bedouin Arabic, the way someone from Hail talks. Do not use an Egyptian accent.'],
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

        'ar-OM-DH' => ['en' => 'Omani (Dhofari)', 'ar' => 'عُماني (ظفاري)', 'region' => 'gulf',
            'prompt' => 'Dhofari Omani Arabic (Salalah)',
            'clone' => 'Dhofari Omani Arabic, the way someone from Salalah talks. Do not use an Egyptian accent.'],
        'ar-LB' => ['en' => 'Lebanese', 'ar' => 'لبناني', 'region' => 'levant',
            'prompt' => 'Lebanese Arabic (Levantine)',
            'clone' => 'Lebanese Levantine Arabic. Do not use an Egyptian accent.'],
        'ar-SY' => ['en' => 'Syrian (Damascene)', 'ar' => 'سوري (شامي)', 'region' => 'levant',
            'prompt' => 'Syrian Arabic (Levantine, Damascene)',
            'clone' => 'Syrian Levantine Arabic. Do not use an Egyptian accent.'],
        'ar-SY-AL' => ['en' => 'Syrian (Aleppine)', 'ar' => 'سوري (حلبي)', 'region' => 'levant',
            'prompt' => 'Aleppine Syrian Arabic (Aleppo)',
            'clone' => 'Aleppine Syrian Arabic, the way someone from Aleppo talks. Do not use an Egyptian accent.'],
        'ar-JO' => ['en' => 'Jordanian (Ammani)', 'ar' => 'أردني (عمّاني)', 'region' => 'levant',
            'prompt' => 'Jordanian Arabic (Levantine)',
            'clone' => 'Jordanian Levantine Arabic, the way someone from Amman talks. Do not use an Egyptian accent.'],
        'ar-JO-BD' => ['en' => 'Jordanian (Bedouin)', 'ar' => 'أردني (بدوي)', 'region' => 'levant',
            'prompt' => 'Jordanian Bedouin Arabic',
            'clone' => 'Jordanian Bedouin Arabic, with the Bedouin g for ق. Do not use an Egyptian accent.'],
        'ar-PS' => ['en' => 'Palestinian', 'ar' => 'فلسطيني', 'region' => 'levant',
            'prompt' => 'Palestinian Arabic (Levantine)',
            'clone' => 'Palestinian Levantine Arabic. Do not use an Egyptian accent.'],

        'ar-PS-GZ' => ['en' => 'Palestinian (Gazan)', 'ar' => 'فلسطيني (غزاوي)', 'region' => 'levant',
            'prompt' => 'Gazan Palestinian Arabic (Gaza)',
            'clone' => 'Gazan Palestinian Arabic, the way someone from Gaza talks. Do not use an Egyptian accent.'],
        'ar-EG' => ['en' => 'Egyptian (Cairene)', 'ar' => 'مصري (قاهري)', 'region' => 'nile',
            'prompt' => 'Egyptian Arabic (Cairene)',
            'clone' => 'Cairene Egyptian colloquial Arabic. Pronounce ج like the English g, with Cairo rhythm.'],
        'ar-EG-SA' => ['en' => 'Egyptian (Saidi)', 'ar' => 'مصري (صعيدي)', 'region' => 'nile',
            'prompt' => 'Saidi Egyptian Arabic (Upper Egypt)',
            'clone' => 'Saidi Upper-Egyptian Arabic, with the Saidi ق as a hard g and Upper Egypt rhythm and wording.'],
        'ar-EG-AL' => ['en' => 'Egyptian (Alexandrian)', 'ar' => 'مصري (إسكندراني)', 'region' => 'nile',
            'prompt' => 'Alexandrian Egyptian Arabic (Alexandria)',
            'clone' => 'Alexandrian Egyptian Arabic, the way someone from Alexandria talks, with Alexandrian rhythm and wording rather than Cairene.'],
        'ar-EG-DL' => ['en' => 'Egyptian (Delta, rural)', 'ar' => 'مصري (فلاحي)', 'region' => 'nile',
            'prompt' => 'Rural Delta Egyptian Arabic (Fallahi)',
            'clone' => 'Rural Delta Egyptian Arabic (Fallahi), the way villagers in the Nile Delta talk, rather than Cairene.'],
        'ar-EG-PO' => ['en' => 'Egyptian (Port Said)', 'ar' => 'مصري (بورسعيدي)', 'region' => 'nile',
            'prompt' => 'Port Said and Canal cities Egyptian Arabic',
            'clone' => 'Port Said Egyptian Arabic, the way someone from Port Said talks, with Canal-city rhythm rather than Cairene.'],
        'ar-EG-BD' => ['en' => 'Egyptian (Sinai Bedouin)', 'ar' => 'مصري (بدوي سيناوي)', 'region' => 'nile',
            'prompt' => 'Sinai Bedouin Arabic (Egypt)',
            'clone' => 'Sinai Bedouin Arabic, the way Bedouin from Sinai talk, with the Bedouin g for ق, not Cairene.'],
        'ar-SD' => ['en' => 'Sudanese', 'ar' => 'سوداني', 'region' => 'nile',
            'prompt' => 'Sudanese Arabic',
            'clone' => 'Sudanese Arabic. Do not use an Egyptian accent.'],

        'ar-IQ' => ['en' => 'Iraqi (Baghdadi)', 'ar' => 'عراقي (بغدادي)', 'region' => 'iraq_yemen',
            'prompt' => 'Iraqi Arabic (Baghdadi)',
            'clone' => 'Iraqi Arabic. Do not use an Egyptian accent.'],
        'ar-IQ-BS' => ['en' => 'Iraqi (Basrawi)', 'ar' => 'عراقي (بصراوي)', 'region' => 'iraq_yemen',
            'prompt' => 'Basrawi Iraqi Arabic (Basra, Gulf-influenced)',
            'clone' => 'Basrawi Iraqi Arabic, the way someone from Basra talks. Do not use an Egyptian accent.'],
        'ar-IQ-MS' => ['en' => 'Iraqi (Maslawi)', 'ar' => 'عراقي (موصلي)', 'region' => 'iraq_yemen',
            'prompt' => 'Maslawi Iraqi Arabic (Mosul)',
            'clone' => 'Maslawi Iraqi Arabic, the way someone from Mosul talks. Do not use an Egyptian accent.'],
        'ar-YE' => ['en' => 'Yemeni (Sanaani)', 'ar' => 'يمني (صنعاني)', 'region' => 'iraq_yemen',
            'prompt' => 'Yemeni Arabic (Sanaani)',
            'clone' => 'Yemeni Arabic. Do not use an Egyptian accent.'],

        'ar-YE-AD' => ['en' => 'Yemeni (Adeni)', 'ar' => 'يمني (عدني)', 'region' => 'iraq_yemen',
            'prompt' => 'Adeni Yemeni Arabic (Aden)',
            'clone' => 'Adeni Yemeni Arabic, the way someone from Aden talks. Do not use an Egyptian accent.'],
        'ar-YE-HD' => ['en' => 'Yemeni (Hadrami)', 'ar' => 'يمني (حضرمي)', 'region' => 'iraq_yemen',
            'prompt' => 'Hadrami Yemeni Arabic (Hadramawt)',
            'clone' => 'Hadrami Arabic, the way someone from Mukalla talks. Do not use an Egyptian accent.'],
        'ar-MA' => ['en' => 'Moroccan', 'ar' => 'مغربي', 'region' => 'maghreb',
            'prompt' => 'Moroccan Arabic (Darija, Maghrebi)',
            'clone' => 'Moroccan Arabic. Do not use an Egyptian accent or Modern Standard Arabic.'],
        'ar-MA-NO' => ['en' => 'Moroccan (Northern)', 'ar' => 'مغربي (شمالي)', 'region' => 'maghreb',
            'prompt' => 'Northern Moroccan Arabic (Tangier and Tetouan, Jebli)',
            'clone' => 'Northern Moroccan Arabic, the way someone from Tangier or Tetouan talks. Do not use an Egyptian accent.'],
        'ar-DZ' => ['en' => 'Algerian', 'ar' => 'جزائري', 'region' => 'maghreb',
            'prompt' => 'Algerian Arabic (Darja, Maghrebi)',
            'clone' => 'Algerian Arabic. Do not use an Egyptian accent.'],
        'ar-DZ-OR' => ['en' => 'Algerian (Oranian)', 'ar' => 'جزائري (وهراني)', 'region' => 'maghreb',
            'prompt' => 'Oranian Algerian Arabic (Oran)',
            'clone' => 'Oranian Algerian Arabic, the way someone from Oran talks. Do not use an Egyptian accent.'],
        'ar-TN' => ['en' => 'Tunisian', 'ar' => 'تونسي', 'region' => 'maghreb',
            'prompt' => 'Tunisian Arabic (Maghrebi)',
            'clone' => 'Tunisian Arabic. Do not use an Egyptian accent.'],
        'ar-LY' => ['en' => 'Libyan (Tripolitanian)', 'ar' => 'ليبي (طرابلسي)', 'region' => 'maghreb',
            'prompt' => 'Libyan Arabic (Maghrebi)',
            'clone' => 'Libyan Arabic. Do not use an Egyptian accent.'],
        'ar-LY-BG' => ['en' => 'Libyan (Benghazi)', 'ar' => 'ليبي (بنغازي)', 'region' => 'maghreb',
            'prompt' => 'Eastern Libyan Arabic (Benghazi, Cyrenaican)',
            'clone' => 'Eastern Libyan Arabic, the way someone from Benghazi talks. Do not use an Egyptian accent.'],
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
