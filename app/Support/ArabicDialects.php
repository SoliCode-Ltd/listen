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
        'ar-LB-TR' => ['en' => 'Lebanese (Tripolitan)', 'ar' => 'لبناني (طرابلسي)', 'region' => 'levant',
            'prompt' => 'Tripolitan Lebanese Arabic (Tripoli, North Lebanon)',
            'clone' => 'North Lebanese Arabic, the way someone from Tripoli in Lebanon talks. Do not use an Egyptian accent.'],
        'ar-SY' => ['en' => 'Syrian (Damascene)', 'ar' => 'سوري (شامي)', 'region' => 'levant',
            'prompt' => 'Syrian Arabic (Levantine, Damascene)',
            'clone' => 'Syrian Levantine Arabic. Do not use an Egyptian accent.'],
        'ar-SY-AL' => ['en' => 'Syrian (Aleppine)', 'ar' => 'سوري (حلبي)', 'region' => 'levant',
            'prompt' => 'Aleppine Syrian Arabic (Aleppo)',
            'clone' => 'Aleppine Syrian Arabic, the way someone from Aleppo talks. Do not use an Egyptian accent.'],
        'ar-SY-DZ' => ['en' => 'Syrian (Deiri)', 'ar' => 'سوري (ديري)', 'region' => 'levant',
            'prompt' => 'Deiri Syrian Arabic (Deir ez-Zor, Euphrates)',
            'clone' => 'Euphrates Syrian Arabic, the way someone from Deir ez-Zor talks. Do not use an Egyptian accent.'],
        'ar-JO' => ['en' => 'Jordanian (Ammani)', 'ar' => 'أردني (عمّاني)', 'region' => 'levant',
            'prompt' => 'Jordanian Arabic (Levantine)',
            'clone' => 'Jordanian Levantine Arabic, the way someone from Amman talks. Do not use an Egyptian accent.'],
        'ar-JO-BD' => ['en' => 'Jordanian (Bedouin)', 'ar' => 'أردني (بدوي)', 'region' => 'levant',
            'prompt' => 'Jordanian Bedouin Arabic',
            'clone' => 'Jordanian Bedouin Arabic, with the Bedouin g for ق. Do not use an Egyptian accent.'],
        'ar-JO-HR' => ['en' => 'Jordanian (Horani)', 'ar' => 'أردني (حوراني)', 'region' => 'levant',
            'prompt' => 'Horani Arabic (Irbid and the Hauran)',
            'clone' => 'Horani Arabic, the way someone from Irbid or the Hauran talks. Do not use an Egyptian accent.'],
        'ar-PS' => ['en' => 'Palestinian', 'ar' => 'فلسطيني', 'region' => 'levant',
            'prompt' => 'Palestinian Arabic (Levantine)',
            'clone' => 'Palestinian Levantine Arabic. Do not use an Egyptian accent.'],

        'ar-PS-GZ' => ['en' => 'Palestinian (Gazan)', 'ar' => 'فلسطيني (غزاوي)', 'region' => 'levant',
            'prompt' => 'Gazan Palestinian Arabic (Gaza)',
            'clone' => 'Gazan Palestinian Arabic, the way someone from Gaza talks. Do not use an Egyptian accent.'],
        'ar-PS-HB' => ['en' => 'Palestinian (Khalili)', 'ar' => 'فلسطيني (خليلي)', 'region' => 'levant',
            'prompt' => 'Khalili Palestinian Arabic (Hebron)',
            'clone' => 'Hebron Palestinian Arabic, the way someone from Al-Khalil talks. Do not use an Egyptian accent.'],
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
        'ar-IQ-AN' => ['en' => 'Iraqi (Anbari)', 'ar' => 'عراقي (أنباري)', 'region' => 'iraq_yemen',
            'prompt' => 'Anbari Iraqi Arabic (Ramadi and western Iraq, Bedouin-influenced)',
            'clone' => 'Western Iraqi Arabic, the way someone from Ramadi in Anbar talks. Do not use an Egyptian accent.'],
        'ar-YE' => ['en' => 'Yemeni (Sanaani)', 'ar' => 'يمني (صنعاني)', 'region' => 'iraq_yemen',
            'prompt' => 'Yemeni Arabic (Sanaani)',
            'clone' => 'Yemeni Arabic. Do not use an Egyptian accent.'],

        'ar-YE-TZ' => ['en' => 'Yemeni (Taizzi)', 'ar' => 'يمني (تعزي)', 'region' => 'iraq_yemen',
            'prompt' => "Ta'izzi Yemeni Arabic (Taiz)",
            'clone' => "Ta'izzi Yemeni Arabic, the way someone from Taiz talks. Do not use an Egyptian accent."],
        'ar-YE-AD' => ['en' => 'Yemeni (Adeni)', 'ar' => 'يمني (عدني)', 'region' => 'iraq_yemen',
            'prompt' => 'Adeni Yemeni Arabic (Aden)',
            'clone' => 'Adeni Yemeni Arabic, the way someone from Aden talks. Do not use an Egyptian accent.'],
        'ar-YE-HD' => ['en' => 'Yemeni (Hadrami)', 'ar' => 'يمني (حضرمي)', 'region' => 'iraq_yemen',
            'prompt' => 'Hadrami Yemeni Arabic (Hadramawt)',
            'clone' => 'Hadrami Arabic, the way someone from Mukalla talks. Do not use an Egyptian accent.'],
        'ar-MA' => ['en' => 'Moroccan', 'ar' => 'مغربي', 'region' => 'maghreb',
            'prompt' => 'Moroccan Arabic (Darija, Maghrebi)',
            'clone' => 'Moroccan Arabic. Do not use an Egyptian accent or Modern Standard Arabic.'],
        'ar-MA-MK' => ['en' => 'Moroccan (Marrakchi)', 'ar' => 'مغربي (مراكشي)', 'region' => 'maghreb',
            'prompt' => 'Marrakchi Moroccan Arabic (Marrakech)',
            'clone' => 'Moroccan Darija the way someone from Marrakech talks. Do not use an Egyptian accent or Modern Standard Arabic.'],
        'ar-MA-NO' => ['en' => 'Moroccan (Northern)', 'ar' => 'مغربي (شمالي)', 'region' => 'maghreb',
            'prompt' => 'Northern Moroccan Arabic (Tangier and Tetouan, Jebli)',
            'clone' => 'Northern Moroccan Arabic, the way someone from Tangier or Tetouan talks. Do not use an Egyptian accent.'],
        'ar-DZ' => ['en' => 'Algerian', 'ar' => 'جزائري', 'region' => 'maghreb',
            'prompt' => 'Algerian Arabic (Darja, Maghrebi)',
            'clone' => 'Algerian Arabic. Do not use an Egyptian accent.'],
        'ar-DZ-OR' => ['en' => 'Algerian (Oranian)', 'ar' => 'جزائري (وهراني)', 'region' => 'maghreb',
            'prompt' => 'Oranian Algerian Arabic (Oran)',
            'clone' => 'Oranian Algerian Arabic, the way someone from Oran talks. Do not use an Egyptian accent.'],
        'ar-DZ-CO' => ['en' => 'Algerian (Constantinois)', 'ar' => 'جزائري (قسنطيني)', 'region' => 'maghreb',
            'prompt' => 'Constantinois Algerian Arabic (Constantine, eastern Algeria)',
            'clone' => 'Eastern Algerian Arabic, the way someone from Constantine talks. Do not use an Egyptian accent.'],
        'ar-TN' => ['en' => 'Tunisian', 'ar' => 'تونسي', 'region' => 'maghreb',
            'prompt' => 'Tunisian Arabic (Maghrebi)',
            'clone' => 'Tunisian Arabic. Do not use an Egyptian accent.'],
        'ar-TN-SF' => ['en' => 'Tunisian (Sfaxi)', 'ar' => 'تونسي (صفاقسي)', 'region' => 'maghreb',
            'prompt' => 'Sfaxi Tunisian Arabic (Sfax)',
            'clone' => 'Sfaxi Tunisian Arabic, the way someone from Sfax talks. Do not use an Egyptian accent.'],
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
     * Countries in the order the studio lists them; Standard Arabic first.
     *
     * @var array<string, array{en: string, ar: string}>
     */
    public const COUNTRIES = [
        'XA' => ['en' => 'Standard Arabic (Fusha)', 'ar' => 'الفصحى'],
        'EG' => ['en' => 'Egypt', 'ar' => 'مصر'],
        'SA' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'],
        'AE' => ['en' => 'UAE', 'ar' => 'الإمارات'],
        'KW' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
        'QA' => ['en' => 'Qatar', 'ar' => 'قطر'],
        'BH' => ['en' => 'Bahrain', 'ar' => 'البحرين'],
        'OM' => ['en' => 'Oman', 'ar' => 'عُمان'],
        'LB' => ['en' => 'Lebanon', 'ar' => 'لبنان'],
        'SY' => ['en' => 'Syria', 'ar' => 'سوريا'],
        'JO' => ['en' => 'Jordan', 'ar' => 'الأردن'],
        'PS' => ['en' => 'Palestine', 'ar' => 'فلسطين'],
        'IQ' => ['en' => 'Iraq', 'ar' => 'العراق'],
        'YE' => ['en' => 'Yemen', 'ar' => 'اليمن'],
        'SD' => ['en' => 'Sudan', 'ar' => 'السودان'],
        'MA' => ['en' => 'Morocco', 'ar' => 'المغرب'],
        'DZ' => ['en' => 'Algeria', 'ar' => 'الجزائر'],
        'TN' => ['en' => 'Tunisia', 'ar' => 'تونس'],
        'LY' => ['en' => 'Libya', 'ar' => 'ليبيا'],
        'MR' => ['en' => 'Mauritania', 'ar' => 'موريتانيا'],
    ];

    /**
     * The country a dialect code belongs to ("ar-EG-AL" → "EG"), or null.
     */
    public static function countryOf(string $code): ?string
    {
        $parts = explode('-', strtoupper(trim($code)));
        $country = $parts[1] ?? '';

        return ($parts[0] ?? '') === 'AR' && isset(self::COUNTRIES[$country]) ? $country : null;
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

    /**
     * What makes each variety recognisable: how ق, ج and ك are said, the
     * words people of that place use, and the rhythm. Given to the speech
     * model with the dialect name, and to the rewrite of the script.
     *
     * @var array<string, string>
     */
    public const MARKERS = [
        'ar-XA' => 'Formal Fusha: every letter pronounced in full (ق as a true q, ج as j, ث and ذ as th sounds), full case endings where natural, no colloquial words.',
        'ar-SA' => 'Najdi: ق as g (گال), ج as j, ك of feminine you as ts in traditional speech; words وش (what), أبي (I want), زين (good), مرّه (very), الحين (now); steady, low Najdi rhythm.',
        'ar-SA-HJ' => 'Hejazi: ق as g or a light glottal stop in Jeddah, ج as j, ث as t or s, ذ as d or z; words إيش (what), أبغى (I want), دحين (now), كدا (like this), مرّة (very); quick, sing-song Jeddah rhythm.',
        'ar-SA-SO' => 'Southern Saudi (Asir and Jazan): ق as g, ج as j; southern words and rhythm of Abha and Jazan, clearly not Najdi or Hejazi.',
        'ar-SA-QS' => 'Qassimi Najdi: ق as g, feminine ك as ts (تس), ج as j; words وش, أبي, الحين; the slower, drawn Qassim intonation.',
        'ar-SA-EA' => 'Eastern Saudi (Al-Ahsa, Dammam): close to Bahraini and Kuwaiti; ج often as y (ريّال), ق as g; words وايد (very), شلون (how), شنو (what).',
        'ar-SA-NO' => 'Northern Saudi Bedouin (Hail, Al-Jouf, Shammar): ق as g, feminine ك as ch (چ), ج as j; Bedouin words زين, وش, هقيت (I thought); strong Bedouin melody.',
        'ar-AE' => 'Emirati: ج as y (ريّال, ياي), ك sometimes as ch (چ), ق as g or j; words شو (what), وايد (very), ابا (I want), الحين (now).',
        'ar-KW' => 'Kuwaiti: ج as y (يبي), ق as g or j, ك as ch (چ) in some words; words شلونك (how are you), شنو (what), وايد (very), زين, الحين.',
        'ar-QA' => 'Qatari: ج as y, ق as g, ك as ch (چ) in some words; words شلونك, شو/شنو, وايد, زين, الحين.',
        'ar-BH' => 'Bahraini: ج as y, ق as g; words شلونك, شنو (what), وايد (very), زين; soft, quick Bahraini rhythm.',
        'ar-OM' => 'Omani: ق as g, ج as j; words شو (what), كيف حالك, بغيت (I want), واجد (a lot); calm Omani rhythm.',
        'ar-OM-DH' => 'Dhofari (Salalah): ق as g, ج as j; southern Omani words and the distinct Dhofari melody, not northern Omani.',
        'ar-LB' => 'Lebanese: ق as a glottal stop (ألّي for قلّي), strong imala (final a toward e); words شو (what), كيفك, هيدا/هيدي (this), كتير (very), هلّق (now), بدّي (I want).',
        'ar-LB-TR' => 'Tripolitan (North Lebanon): ق as a glottal stop, Lebanese words شو, هيدا, كتير, with the heavier, drawn Tripoli intonation.',
        'ar-SY' => 'Damascene Syrian: ق as a glottal stop, ث as t or s; words شو (what), شلونك/كيفك, هاد/هي (this), كتير, هلّق (now), بدّي; soft, melodic Damascus rhythm.',
        'ar-SY-AL' => 'Aleppine: ق as a glottal stop, very strong imala, words شلونك, هلّق, كتير; the distinctive Aleppo melody and lengthened vowels.',
        'ar-SY-DZ' => 'Deiri (Euphrates): ق as g, ك as ch (چ), close to Iraqi; words شلونك, هسّه (now), هواية (a lot), عيني.',
        'ar-JO' => 'Urban Jordanian (Amman): ق as a glottal stop or g, words شو (what), هسّا (now), زلمة (man), كثير/كتير, بدّي; firm Jordanian rhythm.',
        'ar-JO-BD' => 'Jordanian Bedouin: ق as g, ك as ch (چ), ج as j; words هسّاع (now), زلمة, وش (what), يا رجّال.',
        'ar-JO-HR' => 'Horani (Irbid, Hauran): ق as g, ك as ch (چ); words هسّع (now), زلمة, شو; rural Hauran melody.',
        'ar-PS' => 'Palestinian: ق as a glottal stop in cities, ك as ch (چ) in villages; words شو/إيش (what), هلقيت (now), إشي (something), بدّي, كتير; strong imala.',
        'ar-PS-GZ' => 'Gazan: ق as g, ج as j; words إيش (what), هلقيت, بدّي; the Gaza accent with its coastal, slightly Egyptian-influenced rhythm.',
        'ar-PS-HB' => 'Khalili (Hebron): ق as a glottal stop or g, distinct Hebron vowels and melody; words إيش, هسّا, بدّي.',
        'ar-EG' => 'Cairene: ج as g (جميل = gameel), ق as a glottal stop (قال = aal), ث as t or s, ذ as d or z; words عايز (I want), إزيّك (how are you), دلوقتي (now), أوي (very), ده/دي (this); negation ما...ش.',
        'ar-EG-SA' => 'Saidi (Upper Egypt): ق as g (قال = گال, قلبي = گلبي), ج as j (not g); words إكده (like this), دلوك (now), عاوز (I want), يا ولد; slower, heavier southern rhythm.',
        'ar-EG-AL' => 'Alexandrian: Cairene sounds (ج as g, ق as a glottal stop) but the n- prefix for I in the present (نروح = I go, نقول = I say, نكتبوا = we write); words أهو, يا عم, خالص; quicker, more emphatic coastal intonation.',
        'ar-EG-DL' => 'Rural Delta (Fallahi): Egyptian base with broad rural vowels and countryside words like يا وله, يابا, ياما, اللي; slower, drawn village rhythm, clearly not Cairo city speech.',
        'ar-EG-PO' => 'Port Said (Canal cities): Cairene base sounds with the Port Said sing-song intonation, lengthened final vowels and a quick coastal pace; words يا واد, يا معلّم, بقى, أوي.',
        'ar-EG-BD' => 'Sinai Bedouin: ق as g, ج as j; Bedouin words وش (what), زين (good), هسّا (now); Bedouin melody, not Cairo speech.',
        'ar-SD' => 'Sudanese: ق as g, ج as j; words زول (person), شنو (what), كيفنك (how are you), ياخ/ياخي, شديد (very), هسّي (now); measured Sudanese rhythm.',
        'ar-IQ' => 'Baghdadi: ق as g in many words (گال), ك as ch (چ) in some (چبير); words شلونك (how are you), هسّه (now), هواية (a lot), شكو ماكو, أريد/أريدك; Baghdad melody.',
        'ar-IQ-BS' => 'Basrawi: Baghdadi base with Gulf influence; words شلونك, هواية, هسّه, وايد; the southern Basra melody.',
        'ar-IQ-MS' => 'Maslawi (Mosul): ق kept as a true q, ر pronounced like غ (راح = غاح), first person ending -tu/-tu (كنتو for I was); words شلونك, هوايي.',
        'ar-IQ-AN' => 'Anbari (western Iraq): ق as g, ك as ch (چ); Bedouin-influenced words شلونك, هسّه, زين; the western Iraqi melody.',
        'ar-YE' => 'Sanaani: ق as g, ج as j; words إيش (what), شَيْ, قد (already); the high-plateau Sanaa melody.',
        'ar-YE-TZ' => 'Taizzi (Taiz): ق kept close to q, ج as j; words إيش, اشتي (I want), خبيري (my friend); the Taiz melody.',
        'ar-YE-AD' => 'Adeni (Aden): ق as g, ج as j; words إيش, شُوف, اشتي; the coastal Aden rhythm.',
        'ar-YE-HD' => 'Hadrami (Hadramawt): ج often as y, ق as g; words إيش, با (will); the Hadrami melody, not Sanaani.',
        'ar-MA' => 'Moroccan Darija: short vowels dropped (كتب = ktb), ق as q or g; words واش (question), بغيت (I want), دابا (now), بزاف (a lot), ديال (of), مزيان (good); fast Moroccan rhythm.',
        'ar-MA-MK' => 'Marrakchi Darija: Moroccan words واش, بغيت, دابا, بزاف, ديال with the broad, drawn Marrakech intonation.',
        'ar-MA-NO' => 'Northern Moroccan (Tetouan, Tangier): ق as a glottal stop in the cities, Spanish loanwords, Moroccan words دابا, بزاف, ديال; the Jebli northern melody.',
        'ar-DZ' => 'Algerian: words واش (what), راني/راك (I am/you are), درك (now), بزاف (a lot), برك (only), ماكاش; French loanwords; quick Algerian rhythm.',
        'ar-DZ-OR' => 'Oranian: ق as g, Algerian words واش, راك, درك, بزاف; the Oran melody.',
        'ar-DZ-CO' => 'Constantinois: ق kept as q, Algerian words واش, درك, بزاف with the eastern Algerian melody close to Tunisian.',
        'ar-TN' => 'Tunisian: words برشا (a lot), شنوة (what), توا (now), باهي (good), نحب (I want), يزّي (enough); French loanwords; quick Tunis rhythm.',
        'ar-TN-SF' => 'Sfaxi: Tunisian words برشا, شنية, توا, باهي with the distinct Sfax vowels and melody.',
        'ar-LY' => 'Tripoli Libyan: ق as g, words شن (what), توا (now), باهي (good), هلبا (a lot), نبّي (I want).',
        'ar-LY-BG' => 'Benghazi (eastern Libyan): ق as g, Bedouin-influenced; words شن, توا, هلبا, باهي; the eastern Libyan melody.',
        'ar-MR' => 'Mauritanian Hassaniya: ق as g, ذ and ث kept; Hassaniya words and the Mauritanian melody, not Moroccan Darija.',
    ];

    public static function markers(?string $code): ?string
    {
        return self::MARKERS[self::normalize($code)] ?? null;
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
