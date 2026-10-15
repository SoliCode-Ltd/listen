<?php

namespace App\Support;

/**
 * Every English accent members can choose, grouped by country.
 *
 * Gemini 3.8 takes no language code on a single-speaker request, so the
 * accent travels as text: "prompt" names it for the stock voices, "clone"
 * directs a cloned voice away from the accent of its recording.
 * Codes stay within 10 characters (the dialect columns).
 */
final class EnglishAccents
{
    /**
     * @var array<string, array{en: string, ar: string, country: string, prompt: string, clone: string}>
     */
    public const ALL = [
        'en-US' => ['en' => 'American (General)', 'ar' => 'أمريكي (عام)', 'country' => 'US',
            'prompt' => 'General American English',
            'clone' => 'General American English accent.'],
        'en-US-SO' => ['en' => 'American (Southern)', 'ar' => 'أمريكي (جنوبي)', 'country' => 'US',
            'prompt' => 'Southern American English, a warm Texas and Georgia drawl',
            'clone' => 'Southern American English accent with a warm drawl, the way someone from Texas or Georgia talks.'],
        'en-US-NY' => ['en' => 'American (New York)', 'ar' => 'أمريكي (نيويورك)', 'country' => 'US',
            'prompt' => 'New York City English',
            'clone' => 'New York City English accent, the way a native New Yorker talks.'],
        'en-US-BO' => ['en' => 'American (Boston)', 'ar' => 'أمريكي (بوسطن)', 'country' => 'US',
            'prompt' => 'Boston English (non-rhotic New England accent)',
            'clone' => 'Boston English accent, the way a native Bostonian talks.'],
        'en-US-MW' => ['en' => 'American (Midwest)', 'ar' => 'أمريكي (الغرب الأوسط)', 'country' => 'US',
            'prompt' => 'Upper Midwestern American English (Chicago and Minnesota)',
            'clone' => 'Midwestern American English accent, the way someone from Chicago or Minnesota talks.'],
        'en-US-WC' => ['en' => 'American (West Coast)', 'ar' => 'أمريكي (الساحل الغربي)', 'country' => 'US',
            'prompt' => 'West Coast American English (California)',
            'clone' => 'West Coast American English accent, the way someone from California talks.'],
        'en-CA' => ['en' => 'Canadian', 'ar' => 'كندي', 'country' => 'CA',
            'prompt' => 'Canadian English',
            'clone' => 'Canadian English accent.'],
        'en-GB' => ['en' => 'British (Standard)', 'ar' => 'بريطاني (قياسي)', 'country' => 'GB',
            'prompt' => 'British English (standard Southern British, Received Pronunciation)',
            'clone' => 'British English accent.'],
        'en-GB-LD' => ['en' => 'British (London)', 'ar' => 'بريطاني (لندني)', 'country' => 'GB',
            'prompt' => 'London English (Estuary English)',
            'clone' => 'London English accent (Estuary), the way someone from London talks.'],
        'en-GB-NO' => ['en' => 'British (Northern)', 'ar' => 'بريطاني (شمالي)', 'country' => 'GB',
            'prompt' => 'Northern English (Manchester and Yorkshire)',
            'clone' => 'Northern English accent, the way someone from Manchester or Yorkshire talks.'],
        'en-GB-LV' => ['en' => 'British (Liverpool)', 'ar' => 'بريطاني (ليفربول)', 'country' => 'GB',
            'prompt' => 'Liverpool English (Scouse)',
            'clone' => 'Liverpool (Scouse) English accent.'],
        'en-GB-BM' => ['en' => 'British (Birmingham)', 'ar' => 'بريطاني (برمنغهام)', 'country' => 'GB',
            'prompt' => 'Birmingham English (Brummie)',
            'clone' => 'Birmingham (Brummie) English accent.'],
        'en-GB-GE' => ['en' => 'British (Newcastle)', 'ar' => 'بريطاني (نيوكاسل)', 'country' => 'GB',
            'prompt' => 'Newcastle English (Geordie)',
            'clone' => 'Newcastle (Geordie) English accent.'],
        'en-GB-WC' => ['en' => 'British (West Country)', 'ar' => 'بريطاني (الغرب الريفي)', 'country' => 'GB',
            'prompt' => 'West Country English (Bristol and Somerset)',
            'clone' => 'West Country English accent, the way someone from Bristol or Somerset talks.'],
        'en-GB-SC' => ['en' => 'British (Scottish)', 'ar' => 'بريطاني (اسكتلندي)', 'country' => 'GB',
            'prompt' => 'Scottish English',
            'clone' => 'Scottish English accent.'],
        'en-GB-WL' => ['en' => 'British (Welsh)', 'ar' => 'بريطاني (ويلزي)', 'country' => 'GB',
            'prompt' => 'Welsh English',
            'clone' => 'Welsh English accent.'],
        'en-GB-NI' => ['en' => 'British (Northern Irish)', 'ar' => 'بريطاني (أيرلندا الشمالية)', 'country' => 'GB',
            'prompt' => 'Northern Irish English (Belfast)',
            'clone' => 'Northern Irish English accent, the way someone from Belfast talks.'],
        'en-IE' => ['en' => 'Irish', 'ar' => 'أيرلندي', 'country' => 'IE',
            'prompt' => 'Irish English',
            'clone' => 'Irish English accent, the way someone from Dublin talks.'],
        'en-AU' => ['en' => 'Australian', 'ar' => 'أسترالي', 'country' => 'AU',
            'prompt' => 'Australian English',
            'clone' => 'Australian English accent.'],
        'en-NZ' => ['en' => 'New Zealand', 'ar' => 'نيوزيلندي', 'country' => 'NZ',
            'prompt' => 'New Zealand English',
            'clone' => 'New Zealand English accent.'],
        'en-ZA' => ['en' => 'South African', 'ar' => 'جنوب أفريقي', 'country' => 'ZA',
            'prompt' => 'South African English',
            'clone' => 'South African English accent.'],
        'en-IN' => ['en' => 'Indian', 'ar' => 'هندي', 'country' => 'IN',
            'prompt' => 'Indian English',
            'clone' => 'Indian English accent.'],
        'en-PK' => ['en' => 'Pakistani', 'ar' => 'باكستاني', 'country' => 'PK',
            'prompt' => 'Pakistani English',
            'clone' => 'Pakistani English accent.'],
        'en-PH' => ['en' => 'Filipino', 'ar' => 'فلبيني', 'country' => 'PH',
            'prompt' => 'Filipino English (standard English words, Philippine accent)',
            'clone' => 'Filipino English accent, keeping standard English words.'],
        'en-NG' => ['en' => 'Nigerian', 'ar' => 'نيجيري', 'country' => 'NG',
            'prompt' => 'Nigerian English',
            'clone' => 'Nigerian English accent.'],
        'en-GH' => ['en' => 'Ghanaian', 'ar' => 'غاني', 'country' => 'GH',
            'prompt' => 'Ghanaian English',
            'clone' => 'Ghanaian English accent.'],
        'en-KE' => ['en' => 'Kenyan', 'ar' => 'كيني', 'country' => 'KE',
            'prompt' => 'Kenyan English',
            'clone' => 'Kenyan English accent.'],
        'en-JM' => ['en' => 'Jamaican', 'ar' => 'جامايكي', 'country' => 'JM',
            'prompt' => 'Jamaican English (light Jamaican accent, standard English words)',
            'clone' => 'Jamaican English accent, keeping standard English words.'],
        'en-SG' => ['en' => 'Singaporean', 'ar' => 'سنغافوري', 'country' => 'SG',
            'prompt' => 'Singaporean English (standard English words, Singapore accent)',
            'clone' => 'Singaporean English accent, keeping standard English words.'],
        'en-AR' => ['en' => 'Arabic accent', 'ar' => 'بلكنة عربية', 'country' => 'AR',
            'prompt' => 'English spoken by a native Arabic speaker, with a natural Arabic accent',
            'clone' => 'English with a natural Arabic accent, the way a native Arabic speaker talks.'],
        'en-FR' => ['en' => 'French accent', 'ar' => 'بلكنة فرنسية', 'country' => 'FR',
            'prompt' => 'English spoken by a native French speaker, with a natural French accent',
            'clone' => 'English with a natural French accent, the way a native French speaker talks.'],
        'en-ES' => ['en' => 'Spanish accent', 'ar' => 'بلكنة إسبانية', 'country' => 'ES',
            'prompt' => 'English spoken by a native Spain speaker, with a natural Spanish accent',
            'clone' => 'English with a natural Spanish accent, the way a native Spain speaker talks.'],
        'en-MX' => ['en' => 'Latin American accent', 'ar' => 'بلكنة لاتينية', 'country' => 'MX',
            'prompt' => 'English spoken by a native Latin American Spanish speaker, with a natural Latin American Spanish accent',
            'clone' => 'English with a natural Latin American Spanish accent, the way a native Latin American Spanish speaker talks.'],
        'en-DE' => ['en' => 'German accent', 'ar' => 'بلكنة ألمانية', 'country' => 'DE',
            'prompt' => 'English spoken by a native German speaker, with a natural German accent',
            'clone' => 'English with a natural German accent, the way a native German speaker talks.'],
        'en-IT' => ['en' => 'Italian accent', 'ar' => 'بلكنة إيطالية', 'country' => 'IT',
            'prompt' => 'English spoken by a native Italian speaker, with a natural Italian accent',
            'clone' => 'English with a natural Italian accent, the way a native Italian speaker talks.'],
        'en-BR' => ['en' => 'Brazilian accent', 'ar' => 'بلكنة برازيلية', 'country' => 'BR',
            'prompt' => 'English spoken by a native Brazilian Portuguese speaker, with a natural Brazilian Portuguese accent',
            'clone' => 'English with a natural Brazilian Portuguese accent, the way a native Brazilian Portuguese speaker talks.'],
        'en-RU' => ['en' => 'Russian accent', 'ar' => 'بلكنة روسية', 'country' => 'RU',
            'prompt' => 'English spoken by a native Russian speaker, with a natural Russian accent',
            'clone' => 'English with a natural Russian accent, the way a native Russian speaker talks.'],
        'en-TR' => ['en' => 'Turkish accent', 'ar' => 'بلكنة تركية', 'country' => 'TR',
            'prompt' => 'English spoken by a native Turkish speaker, with a natural Turkish accent',
            'clone' => 'English with a natural Turkish accent, the way a native Turkish speaker talks.'],
        'en-NL' => ['en' => 'Dutch accent', 'ar' => 'بلكنة هولندية', 'country' => 'NL',
            'prompt' => 'English spoken by a native Dutch speaker, with a natural Dutch accent',
            'clone' => 'English with a natural Dutch accent, the way a native Dutch speaker talks.'],
        'en-PL' => ['en' => 'Polish accent', 'ar' => 'بلكنة بولندية', 'country' => 'PL',
            'prompt' => 'English spoken by a native Polish speaker, with a natural Polish accent',
            'clone' => 'English with a natural Polish accent, the way a native Polish speaker talks.'],
        'en-GR' => ['en' => 'Greek accent', 'ar' => 'بلكنة يونانية', 'country' => 'GR',
            'prompt' => 'English spoken by a native Greek speaker, with a natural Greek accent',
            'clone' => 'English with a natural Greek accent, the way a native Greek speaker talks.'],
        'en-IR' => ['en' => 'Persian accent', 'ar' => 'بلكنة فارسية', 'country' => 'IR',
            'prompt' => 'English spoken by a native Persian (Farsi) speaker, with a natural Persian accent',
            'clone' => 'English with a natural Persian accent, the way a native Persian (Farsi) speaker talks.'],
        'en-JP' => ['en' => 'Japanese accent', 'ar' => 'بلكنة يابانية', 'country' => 'JP',
            'prompt' => 'English spoken by a native Japanese speaker, with a natural Japanese accent',
            'clone' => 'English with a natural Japanese accent, the way a native Japanese speaker talks.'],
        'en-KR' => ['en' => 'Korean accent', 'ar' => 'بلكنة كورية', 'country' => 'KR',
            'prompt' => 'English spoken by a native Korean speaker, with a natural Korean accent',
            'clone' => 'English with a natural Korean accent, the way a native Korean speaker talks.'],
        'en-CN' => ['en' => 'Chinese accent', 'ar' => 'بلكنة صينية', 'country' => 'CN',
            'prompt' => 'English spoken by a native Mandarin Chinese speaker, with a natural Chinese accent',
            'clone' => 'English with a natural Chinese accent, the way a native Mandarin Chinese speaker talks.'],
    ];

    /**
     * Countries in the order members see them.
     *
     * @var array<string, array{en: string, ar: string}>
     */
    public const COUNTRIES = [
        'US' => ['en' => 'United States', 'ar' => 'الولايات المتحدة'],
        'GB' => ['en' => 'United Kingdom', 'ar' => 'المملكة المتحدة'],
        'CA' => ['en' => 'Canada', 'ar' => 'كندا'],
        'IE' => ['en' => 'Ireland', 'ar' => 'أيرلندا'],
        'AU' => ['en' => 'Australia', 'ar' => 'أستراليا'],
        'NZ' => ['en' => 'New Zealand', 'ar' => 'نيوزيلندا'],
        'ZA' => ['en' => 'South Africa', 'ar' => 'جنوب أفريقيا'],
        'IN' => ['en' => 'India', 'ar' => 'الهند'],
        'NG' => ['en' => 'Nigeria', 'ar' => 'نيجيريا'],
        'JM' => ['en' => 'Jamaica', 'ar' => 'جامايكا'],
        'SG' => ['en' => 'Singapore', 'ar' => 'سنغافورة'],
        'PK' => ['en' => 'Pakistan', 'ar' => 'باكستان'],
        'PH' => ['en' => 'Philippines', 'ar' => 'الفلبين'],
        'GH' => ['en' => 'Ghana', 'ar' => 'غانا'],
        'KE' => ['en' => 'Kenya', 'ar' => 'كينيا'],
        'AR' => ['en' => 'Arabic accent', 'ar' => 'لكنة عربية'],
        'FR' => ['en' => 'French accent', 'ar' => 'لكنة فرنسية'],
        'ES' => ['en' => 'Spanish accent', 'ar' => 'لكنة إسبانية'],
        'MX' => ['en' => 'Latin American accent', 'ar' => 'لكنة لاتينية'],
        'DE' => ['en' => 'German accent', 'ar' => 'لكنة ألمانية'],
        'IT' => ['en' => 'Italian accent', 'ar' => 'لكنة إيطالية'],
        'BR' => ['en' => 'Brazilian accent', 'ar' => 'لكنة برازيلية'],
        'RU' => ['en' => 'Russian accent', 'ar' => 'لكنة روسية'],
        'TR' => ['en' => 'Turkish accent', 'ar' => 'لكنة تركية'],
        'NL' => ['en' => 'Dutch accent', 'ar' => 'لكنة هولندية'],
        'PL' => ['en' => 'Polish accent', 'ar' => 'لكنة بولندية'],
        'GR' => ['en' => 'Greek accent', 'ar' => 'لكنة يونانية'],
        'IR' => ['en' => 'Persian accent', 'ar' => 'لكنة فارسية'],
        'JP' => ['en' => 'Japanese accent', 'ar' => 'لكنة يابانية'],
        'KR' => ['en' => 'Korean accent', 'ar' => 'لكنة كورية'],
        'CN' => ['en' => 'Chinese accent', 'ar' => 'لكنة صينية'],
    ];

    /**
     * @return array<int, array{value: string, label: string, label_ar: string}>
     */
    public static function catalogRows(): array
    {
        $rows = [];
        foreach (self::ALL as $code => $row) {
            $rows[] = ['value' => $code, 'label' => $row['en'], 'label_ar' => $row['ar']];
        }

        return $rows;
    }

    /**
     * One line about each accent for the accent cards, in English and Arabic.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ABOUT = [
        'en-US' => ['The neutral American voice of film and tech', 'الصوت الأمريكي المحايد في الأفلام والتقنية'],
        'en-US-SO' => ['Texas and Georgia · warm drawl', 'تكساس وجورجيا · مدّ دافئ'],
        'en-US-NY' => ['New York City · fast and direct', 'نيويورك · سريعة ومباشرة'],
        'en-US-BO' => ['Boston · drops the r ("pahk the cah")', 'بوسطن · بلا راء في آخر المقاطع'],
        'en-US-MW' => ['Chicago and Minnesota · friendly, flat vowels', 'شيكاغو ومينيسوتا · ودودة بحروف مسطحة'],
        'en-US-WC' => ['California · relaxed and laid-back', 'كاليفورنيا · مسترخية وعفوية'],
        'en-CA' => ['Toronto and Vancouver', 'تورونتو وفانكوفر'],
        'en-GB' => ['Received Pronunciation · classic British', 'النطق البريطاني القياسي الكلاسيكي'],
        'en-GB-LD' => ['London · Estuary English', 'لندن · إنجليزية المصبّ'],
        'en-GB-NO' => ['Manchester and Yorkshire', 'مانشستر ويوركشاير'],
        'en-GB-LV' => ['Liverpool · Scouse', 'ليفربول · سكاوس'],
        'en-GB-BM' => ['Birmingham · Brummie', 'برمنغهام · برومي'],
        'en-GB-GE' => ['Newcastle · Geordie', 'نيوكاسل · جوردي'],
        'en-GB-WC' => ['Bristol and Somerset · rural burr', 'بريستول وسومرست · لكنة ريفية'],
        'en-GB-SC' => ['Glasgow and Edinburgh · rolled r', 'غلاسكو وإدنبرة · راء مكررة'],
        'en-GB-WL' => ['Cardiff and Wales · sing-song melody', 'كارديف وويلز · نغمة غنائية'],
        'en-GB-NI' => ['Belfast', 'بلفاست'],
        'en-IE' => ['Dublin · soft and lilting', 'دبلن · ناعمة ومتموجة'],
        'en-AU' => ['Sydney and Melbourne · Aussie', 'سيدني وملبورن · أسترالية'],
        'en-NZ' => ['Auckland and Wellington · Kiwi', 'أوكلاند وولينغتون · كيوي'],
        'en-ZA' => ['Johannesburg and Cape Town', 'جوهانسبرغ وكيب تاون'],
        'en-IN' => ['Indian English · clear and rhythmic', 'الإنجليزية الهندية · واضحة وإيقاعية'],
        'en-PK' => ['Karachi and Lahore', 'كراتشي ولاهور'],
        'en-PH' => ['Manila · clear Filipino English', 'مانيلا · إنجليزية فلبينية واضحة'],
        'en-SG' => ['Singapore · crisp and quick', 'سنغافورة · واضحة وسريعة'],
        'en-NG' => ['Lagos and Abuja', 'لاغوس وأبوجا'],
        'en-GH' => ['Accra', 'أكرا'],
        'en-KE' => ['Nairobi', 'نيروبي'],
        'en-JM' => ['Kingston · light Caribbean lilt', 'كينغستون · لمسة كاريبية خفيفة'],
        'en-AR' => ['The way Arabic speakers say it', 'بنطق المتحدث العربي'],
        'en-FR' => ['The way French speakers say it', 'بنطق المتحدث الفرنسي'],
        'en-ES' => ['The way Spanish speakers say it', 'بنطق المتحدث الإسباني'],
        'en-MX' => ['Mexico and Latin America', 'المكسيك وأمريكا اللاتينية'],
        'en-DE' => ['The way German speakers say it', 'بنطق المتحدث الألماني'],
        'en-IT' => ['The way Italian speakers say it', 'بنطق المتحدث الإيطالي'],
        'en-BR' => ['The way Brazilians say it', 'بنطق المتحدث البرازيلي'],
        'en-RU' => ['The way Russian speakers say it', 'بنطق المتحدث الروسي'],
        'en-TR' => ['The way Turkish speakers say it', 'بنطق المتحدث التركي'],
        'en-NL' => ['The way Dutch speakers say it', 'بنطق المتحدث الهولندي'],
        'en-PL' => ['The way Polish speakers say it', 'بنطق المتحدث البولندي'],
        'en-GR' => ['The way Greek speakers say it', 'بنطق المتحدث اليوناني'],
        'en-IR' => ['The way Persian speakers say it', 'بنطق المتحدث الفارسي'],
        'en-JP' => ['The way Japanese speakers say it', 'بنطق المتحدث الياباني'],
        'en-KR' => ['The way Korean speakers say it', 'بنطق المتحدث الكوري'],
        'en-CN' => ['The way Mandarin speakers say it', 'بنطق المتحدث الصيني'],
    ];

    public static function about(?string $code, bool $arabic): string
    {
        return (string) (self::ABOUT[self::normalize($code)][$arabic ? 1 : 0] ?? '');
    }

    public static function prompt(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['prompt'] ?? null;
    }

    public static function cloneInstruction(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['clone'] ?? null;
    }

    public static function countryOf(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['country'] ?? null;
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
