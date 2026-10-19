<?php

namespace App\Support;

use App\Models\VoiceSample;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public SEO pages for Arabic dialect text to speech: one hub page
 * (/arabic-dialects) and one page per country (/arabic-dialects/{slug}).
 *
 * The accents, dialect words and pronunciation notes come from
 * ArabicDialects, the same catalog the studio uses, so every page describes
 * what members can actually pick.
 */
final class SeoDialectPages
{
    public const HUB_PAGE = 'arabic_dialects';

    /**
     * slug => country code, English adjective, Arabic adjective (as in "باللهجة …"),
     * and one sentence about the dialect in each language.
     *
     * @var array<string, array{country: string, en: string, ar: string, about_en: string, about_ar: string}>
     */
    public const PAGES = [
        'egyptian' => ['country' => 'EG', 'en' => 'Egyptian', 'ar' => 'المصرية',
            'about_en' => 'Egyptian Arabic is the most widely understood Arabic dialect, thanks to decades of Egyptian film, TV and music.',
            'about_ar' => 'اللهجة المصرية هي الأكثر انتشاراً وفهماً في العالم العربي بفضل السينما والدراما والأغاني المصرية.'],
        'saudi' => ['country' => 'SA', 'en' => 'Saudi', 'ar' => 'السعودية',
            'about_en' => 'Saudi Arabic spans several distinct regional accents, from Najdi in Riyadh to Hejazi in Jeddah, Southern, Eastern and Northern speech.',
            'about_ar' => 'تضم اللهجة السعودية لهجات إقليمية متعددة، من النجدية في الرياض إلى الحجازية في جدة، والجنوبية والشرقية والشمالية.'],
        'emirati' => ['country' => 'AE', 'en' => 'Emirati', 'ar' => 'الإماراتية',
            'about_en' => 'Emirati Arabic is the Gulf dialect of Dubai and Abu Dhabi, the voice that local UAE audiences recognise as their own.',
            'about_ar' => 'اللهجة الإماراتية هي لهجة دبي وأبوظبي الخليجية، والأقرب إلى الجمهور المحلي في دولة الإمارات.'],
        'kuwaiti' => ['country' => 'KW', 'en' => 'Kuwaiti', 'ar' => 'الكويتية',
            'about_en' => 'Kuwaiti Arabic is a Gulf dialect with a strong presence across the region through Kuwaiti drama and media.',
            'about_ar' => 'اللهجة الكويتية لهجة خليجية لها حضور قوي في المنطقة بفضل الدراما والإعلام الكويتي.'],
        'qatari' => ['country' => 'QA', 'en' => 'Qatari', 'ar' => 'القطرية',
            'about_en' => 'Qatari Arabic comes in an urban Doha accent and a Bedouin accent, each with its own words and melody.',
            'about_ar' => 'تأتي اللهجة القطرية بلكنة الدوحة الحضرية ولكنة بدوية، ولكل منهما مفرداتها ونغمتها الخاصة.'],
        'bahraini' => ['country' => 'BH', 'en' => 'Bahraini', 'ar' => 'البحرينية',
            'about_en' => 'Bahraini Arabic is a soft, quick Gulf dialect closely tied to the island\'s local identity.',
            'about_ar' => 'اللهجة البحرينية لهجة خليجية ناعمة وسريعة الإيقاع ومرتبطة بالهوية المحلية للبحرين.'],
        'omani' => ['country' => 'OM', 'en' => 'Omani', 'ar' => 'العُمانية',
            'about_en' => 'Omani Arabic has a calm northern accent and the distinct Dhofari accent of Salalah.',
            'about_ar' => 'للهجة العُمانية لكنة شمالية هادئة ولكنة ظفارية مميزة في صلالة.'],
        'lebanese' => ['country' => 'LB', 'en' => 'Lebanese', 'ar' => 'اللبنانية',
            'about_en' => 'Lebanese Arabic is a Levantine dialect known for its melodic rhythm and widely used in advertising and entertainment.',
            'about_ar' => 'اللهجة اللبنانية لهجة شامية معروفة بإيقاعها الموسيقي، وتُستخدم كثيراً في الإعلانات والترفيه.'],
        'syrian' => ['country' => 'SY', 'en' => 'Syrian', 'ar' => 'السورية',
            'about_en' => 'Syrian Arabic, especially the Damascene accent, is one of the most recognisable Levantine dialects thanks to Syrian drama and dubbing.',
            'about_ar' => 'اللهجة السورية، وخاصة الشامية الدمشقية، من أشهر اللهجات العربية بفضل الدراما والدبلجة السورية.'],
        'jordanian' => ['country' => 'JO', 'en' => 'Jordanian', 'ar' => 'الأردنية',
            'about_en' => 'Jordanian Arabic ranges from the urban Ammani accent to Bedouin and Horani speech.',
            'about_ar' => 'تتنوع اللهجة الأردنية بين اللكنة العمّانية الحضرية واللكنتين البدوية والحورانية.'],
        'palestinian' => ['country' => 'PS', 'en' => 'Palestinian', 'ar' => 'الفلسطينية',
            'about_en' => 'Palestinian Arabic is a Levantine dialect with distinct city, Gaza and Hebron accents.',
            'about_ar' => 'اللهجة الفلسطينية لهجة شامية تتنوع بين لكنة المدن ولكنتي غزة والخليل.'],
        'iraqi' => ['country' => 'IQ', 'en' => 'Iraqi', 'ar' => 'العراقية',
            'about_en' => 'Iraqi Arabic has a rich Baghdadi accent alongside Basrawi, Maslawi and western Anbari speech.',
            'about_ar' => 'للهجة العراقية لكنة بغدادية غنية إلى جانب اللكنات البصراوية والموصلية والأنبارية.'],
        'yemeni' => ['country' => 'YE', 'en' => 'Yemeni', 'ar' => 'اليمنية',
            'about_en' => 'Yemeni Arabic covers Sanaani, Taizzi, Adeni and Hadrami accents, each with its own melody.',
            'about_ar' => 'تشمل اللهجة اليمنية اللكنات الصنعانية والتعزية والعدنية والحضرمية، ولكل منها نغمتها الخاصة.'],
        'sudanese' => ['country' => 'SD', 'en' => 'Sudanese', 'ar' => 'السودانية',
            'about_en' => 'Sudanese Arabic is spoken with a measured rhythm and distinctive words like زول (person).',
            'about_ar' => 'اللهجة السودانية تُنطق بإيقاع متزن ومفردات مميزة مثل «زول».'],
        'moroccan' => ['country' => 'MA', 'en' => 'Moroccan', 'ar' => 'المغربية',
            'about_en' => 'Moroccan Darija is fast, drops short vowels and mixes in Amazigh, French and Spanish words.',
            'about_ar' => 'الدارجة المغربية سريعة الإيقاع، تُسقط الحركات القصيرة وتمزج كلمات أمازيغية وفرنسية وإسبانية.'],
        'algerian' => ['country' => 'DZ', 'en' => 'Algerian', 'ar' => 'الجزائرية',
            'about_en' => 'Algerian Darja is a quick Maghrebi dialect with French loanwords and distinct Oran and Constantine accents.',
            'about_ar' => 'الدارجة الجزائرية لهجة مغاربية سريعة فيها كلمات فرنسية، مع لكنتي وهران وقسنطينة المميزتين.'],
        'tunisian' => ['country' => 'TN', 'en' => 'Tunisian', 'ar' => 'التونسية',
            'about_en' => 'Tunisian Arabic is a Maghrebi dialect with words like برشا (a lot) and توا (now) and a quick Tunis rhythm.',
            'about_ar' => 'اللهجة التونسية لهجة مغاربية بمفردات مثل «برشا» و«توا» وإيقاع تونسي سريع.'],
        'libyan' => ['country' => 'LY', 'en' => 'Libyan', 'ar' => 'الليبية',
            'about_en' => 'Libyan Arabic has a Tripoli accent in the west and a Bedouin-influenced Benghazi accent in the east.',
            'about_ar' => 'للهجة الليبية لكنة طرابلسية في الغرب ولكنة بنغازية ذات طابع بدوي في الشرق.'],
        'mauritanian' => ['country' => 'MR', 'en' => 'Mauritanian Hassaniya', 'ar' => 'الحسانية الموريتانية',
            'about_en' => 'Mauritanian Hassaniya is a distinct Arabic variety that keeps classical sounds lost in many other dialects.',
            'about_ar' => 'الحسانية الموريتانية لهجة عربية مميزة تحافظ على أصوات فصيحة اختفت من لهجات أخرى.'],
        'chadian' => ['country' => 'TD', 'en' => 'Chadian', 'ar' => 'التشادية',
            'about_en' => 'Chadian Arabic (Shuwa) is the everyday Arabic of N\'Djamena and much of Chad, with its own simple grammar and Sahelian melody.',
            'about_ar' => 'العربية التشادية (الشوا) هي عربية الحياة اليومية في أنجمينا وأغلب تشاد، بقواعدها المبسطة ونغمتها الساحلية.'],
        'south-sudanese' => ['country' => 'SS', 'en' => 'South Sudanese', 'ar' => 'الجنوب سودانية',
            'about_en' => 'Juba Arabic is the shared language of South Sudan\'s markets and streets, simple and relaxed, with many local and English words.',
            'about_ar' => 'عربي جوبا هو اللغة المشتركة في أسواق جنوب السودان وشوارعه، بسيطة وهادئة وفيها كلمات محلية وإنجليزية كثيرة.'],
        'ahwazi' => ['country' => 'IR', 'en' => 'Ahwazi', 'ar' => 'الأهوازية',
            'about_en' => 'Ahwazi Arabic, spoken in Khuzestan, is close to southern Iraqi speech with its own local words and melody.',
            'about_ar' => 'اللهجة الأهوازية في خوزستان قريبة من لهجة جنوب العراق، ولها مفرداتها ونغمتها المحلية.'],
        'anatolian' => ['country' => 'TR', 'en' => 'Anatolian', 'ar' => 'الأناضولية',
            'about_en' => 'Anatolian Arabic is the old Arabic of south-east Türkiye, from Mardin and Urfa to Hatay, with Turkish loanwords.',
            'about_ar' => 'العربية الأناضولية هي العربية القديمة في جنوب شرق تركيا، من ماردين وأورفا إلى هاتاي، مع كلمات تركية.'],
        'nigerian' => ['country' => 'NG', 'en' => 'Nigerian Shuwa', 'ar' => 'النيجيرية (الشوا)',
            'about_en' => 'Shuwa Arabic is spoken by the Arab communities of Borno in north-east Nigeria and is close to Chadian Arabic.',
            'about_ar' => 'العربية الشوا لغة المجتمعات العربية في بورنو شمال شرق نيجيريا، وهي قريبة من العربية التشادية.'],
        'fusha' => ['country' => 'XA', 'en' => 'Modern Standard Arabic (Fusha)', 'ar' => 'العربية الفصحى',
            'about_en' => 'Modern Standard Arabic (Fusha) is the formal Arabic of news, education, audiobooks and official content in every Arab country.',
            'about_ar' => 'العربية الفصحى هي لغة الأخبار والتعليم والكتب الصوتية والمحتوى الرسمي في كل الدول العربية.'],
    ];

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys(self::PAGES);
    }

    public static function pageKey(string $slug): string
    {
        return 'dialect_'.str_replace('-', '_', $slug);
    }

    public static function slugForPageKey(string $page): ?string
    {
        if (! str_starts_with($page, 'dialect_')) {
            return null;
        }

        $slug = str_replace('_', '-', substr($page, 8));

        return isset(self::PAGES[$slug]) ? $slug : null;
    }

    public static function isFusha(string $slug): bool
    {
        return (self::PAGES[$slug]['country'] ?? '') === 'XA';
    }

    /**
     * Page definitions for SeoHelper::pageDefinitions().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(string $robots): array
    {
        $definitions = [
            self::HUB_PAGE => [
                'label' => 'Arabic Dialects',
                'title' => 'Arabic Dialects Text to Speech',
                'title_key' => 'ui.seo.arabic_dialects_title',
                'description' => 'AI text to speech in Fusha and '.DialectCounts::arabic().'+ Arabic accents from the Gulf, the Levant, Egypt, Sudan, Iraq, Yemen and the Maghreb.',
                'description_key' => 'ui.seo.arabic_dialects_description',
                'robots' => $robots,
                'path' => '/arabic-dialects',
                'localized' => true,
                'indexable' => true,
                'generated' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
        ];

        foreach (self::PAGES as $slug => $row) {
            $definitions[self::pageKey($slug)] = [
                'label' => $row['en'].' Text to Speech',
                'title' => self::title($slug, 'en'),
                'description' => self::description($slug, 'en'),
                'robots' => $robots,
                'path' => '/arabic-dialects/'.$slug,
                'localized' => true,
                'indexable' => true,
                'generated' => true,
                'sitemap_priority' => 0.8,
                'sitemap_change_frequency' => 'weekly',
            ];
        }

        return $definitions;
    }

    public static function title(string $slug, string $locale): string
    {
        $row = self::PAGES[$slug];

        if ($locale === 'ar') {
            return self::isFusha($slug)
                ? 'تحويل النص إلى صوت بالعربية الفصحى بالذكاء الاصطناعي'
                : 'تحويل النص إلى صوت باللهجة '.$row['ar'].' بالذكاء الاصطناعي';
        }

        return self::isFusha($slug)
            ? 'Modern Standard Arabic (Fusha) Text to Speech'
            : $row['en'].' Arabic Text to Speech';
    }

    public static function description(string $slug, string $locale): string
    {
        $row = self::PAGES[$slug];
        $accents = self::accents($slug);

        if ($locale === 'ar') {
            $names = implode('، ', array_map(fn (array $a): string => self::accentShortName($a['ar']), array_slice($accents, 0, 3)));
            $subject = self::isFusha($slug) ? 'بالعربية الفصحى' : 'باللهجة '.$row['ar'];

            return 'حوّل النص إلى صوت طبيعي '.$subject.' بالذكاء الاصطناعي'
                .(count($accents) > 1 ? ' بلكنات: '.$names : '')
                .(self::isFusha($slug) ? '. نطق فصيح سليم' : '. نطق حقيقي للهجة')
                .'، مع المشاعر وتحميل MP3.';
        }

        $names = implode(', ', array_map(fn (array $a): string => self::accentShortName($a['en']), array_slice($accents, 0, 3)));
        $subject = self::isFusha($slug) ? 'Modern Standard Arabic' : $row['en'].' Arabic';

        return 'Turn text into natural '.$subject.' speech with AI'
            .(count($accents) > 1 ? ', in '.$names.' accents' : '')
            .(self::isFusha($slug) ? '. Correct, clear pronunciation' : '. Real dialect pronunciation')
            .', with emotions and MP3 download.';
    }

    /**
     * Accents (studio dialect codes) that belong to the page's country.
     *
     * @return array<int, array{code: string, en: string, ar: string, markers: string, words: array<int, string>}>
     */
    public static function accents(string $slug): array
    {
        $country = self::PAGES[$slug]['country'] ?? '';
        $rows = [];

        foreach (ArabicDialects::ALL as $code => $row) {
            $parts = explode('-', $code);
            if (($parts[1] ?? '') !== $country) {
                continue;
            }

            $markers = (string) (ArabicDialects::MARKERS[$code] ?? '');
            $rows[] = [
                'code' => $code,
                'en' => $row['en'],
                'ar' => $row['ar'],
                'markers' => $markers,
                'words' => self::arabicWords($markers),
            ];
        }

        return $rows;
    }

    /**
     * Arabic words and phrases quoted in a pronunciation note.
     *
     * @return array<int, string>
     */
    public static function arabicWords(string $markers): array
    {
        // Drop the words the note says not to use ("never رفيجي", "not مو").
        $markers = (string) preg_replace('/\b(?:never|not)\s+[^\s,;:)]+/u', '', $markers);

        $w = '[\x{0621}-\x{064A}\x{0671}-\x{06D3}\x{0686}\x{06AF}\x{0651}\x{064B}-\x{0652}]+';
        preg_match_all('/'.$w.'(?:\s?[\/ ]\s?'.$w.')*/u', $markers, $matches);

        $words = [];
        foreach ($matches[0] as $word) {
            $word = trim($word);
            // Single letters are pronunciation notes (ق, ج), not words.
            if (mb_strlen(preg_replace('/[\x{064B}-\x{0652}]/u', '', $word)) < 2 || in_array($word, ['ما', 'لا'], true)) {
                continue;
            }
            $words[$word] = true;
        }

        return array_keys($words);
    }

    /**
     * Landing page content in the same shape as SeoHelper::landingPageContentDefaults().
     *
     * @return array<string, mixed>
     */
    public static function content(string $slug, string $locale): array
    {
        $row = self::PAGES[$slug];
        $accents = self::accents($slug);
        $fusha = self::isFusha($slug);
        $words = [];
        foreach ($accents as $accent) {
            foreach ($accent['words'] as $word) {
                $words[$word] = true;
            }
        }
        $words = array_slice(array_keys($words), 0, 24);

        if ($locale === 'ar') {
            $dialect = $fusha ? 'العربية الفصحى' : 'اللهجة '.$row['ar'];
            $with = $fusha ? 'بالعربية الفصحى' : 'باللهجة '.$row['ar'];
            $accentNames = implode('، ', array_map(fn (array $a): string => $a['ar'], $accents));

            return [
                'eyebrow' => $fusha ? 'صوت عربي فصيح' : 'صوت '.$row['ar'].' بالذكاء الاصطناعي',
                'h1' => self::title($slug, 'ar'),
                'intro' => $row['about_ar'].' مع منصة إسمع تكتب نصك وتختار '.$dialect.' فتحصل على تعليق صوتي طبيعي بنطق وإيقاع '
                    .($fusha ? 'فصيح واضح' : 'أهل البلد')
                    .'، جاهز للتحميل بصيغة MP3 خلال ثوانٍ.',
                'accents_title' => count($accents) > 1 ? 'اللكنات المتاحة' : 'اللكنة المتاحة',
                'accents_intro' => count($accents) > 1
                    ? 'اختر اللكنة الأقرب لجمهورك من داخل الاستوديو: '.$accentNames.'.'
                    : 'متاحة من داخل الاستوديو مع جميع الأصوات عالية الدقة.',
                'accents' => array_map(fn (array $a): array => ['title' => $a['ar'], 'body' => ''], $accents),
                'words_title' => $fusha ? 'نطق فصيح كامل' : 'كلمات ونطق '.$dialect,
                'words_intro' => $fusha
                    ? 'تُنطق الحروف كاملة: القاف قافاً والجيم جيماً والثاء والذال بمخرجهما، دون كلمات عامية.'
                    : 'تُوجَّه الأصوات لنطق الحروف كما ينطقها أهل '.(ArabicDialects::COUNTRIES[$row['country']]['ar'] ?? '').' واستخدام مفرداتهم، مثل:',
                'words' => $words,
                'highlights_title' => 'لماذا منصة إسمع '.$with.'؟',
                'highlights_intro' => 'مبنية للعربية أولاً، وليست أداة إنجليزية أُضيفت لها العربية.',
                'highlights' => [
                    ['title' => $fusha ? 'فصحى سليمة النطق' : 'لهجة حقيقية وليست فصحى بلكنة', 'body' => $fusha
                        ? 'نطق واضح ومخارج حروف صحيحة يناسب التعليم والكتب الصوتية والأخبار.'
                        : 'يتبع الصوت نطق الحروف والمفردات وإيقاع الكلام في '.$dialect.'، ليبدو قريباً من متحدث محلي.'],
                    ['title' => 'أصوات عالية الدقة مع تحكم في الأداء', 'body' => 'اختر نبرة الصوت والمشاعر وأسلوب الأداء: إعلاني، قصصي، تعليمي، أو حواري.'],
                    ['title' => 'عربي وإنجليزي من منصة واحدة', 'body' => 'أنشئ النسخة العربية والإنجليزية من المحتوى نفسه، مع ترجمة اختيارية قبل التحويل إلى صوت.'],
                ],
                'use_cases_title' => 'استخدامات شائعة',
                'use_cases_intro' => 'تعليق صوتي '.$with.' لكل ما تنشره.',
                'use_cases' => [
                    ['title' => 'إعلانات وفيديوهات تيك توك ويوتيوب', 'body' => 'تعليق صوتي يخاطب جمهورك بلهجته فيرفع التفاعل ونسبة المشاهدة.'],
                    ['title' => 'خدمة العملاء والرد الآلي', 'body' => 'رسائل ترحيب وقوائم صوتية ورسائل تنبيه بصوت مألوف لعملائك.'],
                    ['title' => 'التعليم والتدريب', 'body' => 'دروس ومواد تدريبية مسموعة يسهل فهمها ومتابعتها.'],
                    ['title' => 'البودكاست والقصص', 'body' => 'حوّل المقالات والقصص إلى محتوى مسموع بأداء طبيعي.'],
                ],
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'هل يتكلم الصوت '.$dialect.' فعلاً أم فصحى بلكنة؟', 'answer' => $fusha
                        ? 'تُنطق الفصحى نطقاً كاملاً بمخارج الحروف الصحيحة ودون مفردات عامية.'
                        : 'عند اختيار '.$dialect.' في الاستوديو تُوجَّه الأصوات عالية الدقة لنطق الحروف وإيقاع الكلام كما يتكلم أهل البلد. للحصول على أفضل نتيجة اكتب النص نفسه بالعامية.'],
                    ['question' => 'ما اللكنات المتاحة '.$with.'؟', 'answer' => $accentNames.'.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي، ثم تختار الباقة المناسبة لاستخدامك.'],
                    ['question' => 'هل يمكن تحميل الصوت واستخدامه تجارياً؟', 'answer' => 'في كل باقة مدفوعة يمكنك تحميل كل مقطع بصيغة MP3. حقوق الاستخدام التجاري تعتمد على خطتك، وتجد تفاصيلها في صفحة الأسعار.'],
                ],
                'cta_title' => 'ابدأ التعليق الصوتي '.$with,
                'cta_body' => 'أنشئ حسابك واسمع نصك '.$with.' خلال ثوانٍ.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        $dialect = $fusha ? 'Modern Standard Arabic' : $row['en'].' Arabic';
        $accentNames = implode(', ', array_map(fn (array $a): string => $a['en'], $accents));

        return [
            'eyebrow' => $fusha ? 'Fusha AI voice' : $row['en'].' AI voice',
            'h1' => $fusha
                ? 'Modern Standard Arabic (Fusha) Text to Speech'
                : $row['en'].' Arabic Text to Speech with Real Dialect Voices',
            'intro' => $row['about_en'].' With Listen you paste your script, choose '.$dialect
                .' and get a natural voiceover with '.($fusha ? 'clear, formal pronunciation' : 'local pronunciation and rhythm')
                .', ready to download as MP3 in seconds.',
            'accents_title' => count($accents) > 1 ? 'Accents you can choose' : 'Available accent',
            'accents_intro' => count($accents) > 1
                ? 'Pick the accent closest to your audience in the studio: '.$accentNames.'.'
                : 'Available in the studio with every HD voice.',
            'accents' => array_map(fn (array $a): array => ['title' => $a['en'].' · '.$a['ar'], 'body' => $a['markers']], $accents),
            'words_title' => $fusha ? 'Full classical pronunciation' : 'Words and sounds of '.$dialect,
            'words_intro' => $fusha
                ? 'Every letter is pronounced in full, with no colloquial words.'
                : 'Voices are directed to use the letters and everyday words people actually say, such as:',
            'words' => $words,
            'highlights_title' => 'Why Listen for '.$dialect,
            'highlights_intro' => 'Built Arabic-first, not an English tool with Arabic bolted on.',
            'highlights' => [
                ['title' => $fusha ? 'Correct Fusha pronunciation' : 'Real dialect, not Fusha with an accent', 'body' => $fusha
                    ? 'Clear articulation suited to education, audiobooks and news.'
                    : 'Pronunciation, vocabulary and rhythm follow '.$dialect.', so it sounds like a local speaker.'],
                ['title' => 'HD voices with performance control', 'body' => 'Set tone, emotion and delivery style: advert, storytelling, teaching or conversational.'],
                ['title' => 'Arabic and English in one place', 'body' => 'Produce Arabic and English versions of the same content, with optional translation before speech.'],
            ],
            'use_cases_title' => 'Popular uses',
            'use_cases_intro' => $dialect.' voiceovers for everything you publish.',
            'use_cases' => [
                ['title' => 'Ads, TikTok and YouTube videos', 'body' => 'Speak to your audience in their own dialect to lift engagement and watch time.'],
                ['title' => 'Customer service and IVR', 'body' => 'Welcome messages, phone menus and notifications in a voice your customers recognise.'],
                ['title' => 'E-learning and training', 'body' => 'Lessons and training material that are easy to follow by ear.'],
                ['title' => 'Podcasts and stories', 'body' => 'Turn articles and stories into audio with a natural delivery.'],
            ],
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => $fusha ? 'Does it pronounce Fusha correctly?' : 'Does it really speak '.$dialect.', or Fusha with an accent?', 'answer' => $fusha
                    ? 'Yes. Fusha is spoken with full, correct articulation and no colloquial words.'
                    : 'When you choose '.$dialect.' in the studio, HD voices are directed to use local pronunciation and rhythm. For the most natural result, write the script itself in the dialect.'],
                ['question' => 'Which '.$dialect.' accents are available?', 'answer' => $accentNames.'.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo, then you pick the plan that fits your use.'],
                ['question' => 'Can I download the audio and use it commercially?', 'answer' => 'On every paid plan, each clip downloads as MP3. Commercial use depends on your plan; see the pricing page for details.'],
            ],
            'cta_title' => 'Start your '.$dialect.' voiceover',
            'cta_body' => 'Create your account and hear your script in '.$dialect.' in seconds.',
            'cta_primary' => 'Create your account',
        ];
    }

    /**
     * Hub page content.
     *
     * @return array<string, mixed>
     */
    public static function hubContent(string $locale): array
    {
        // Fusha and its styles are named apart, not counted among the accents or their countries.
        $accents = DialectCounts::spokenArabic();
        $countries = count(ArabicDialects::COUNTRIES) - 1;

        if ($locale === 'ar') {
            return [
                'eyebrow' => 'اللهجات العربية',
                'h1' => 'تحويل النص إلى صوت باللهجات العربية والفصحى',
                'intro' => 'منصة إسمع تتكلم '.$accents.' لكنة عربية من '.$countries.' دولة بالإضافة إلى الفصحى: لهجات الخليج والشام ومصر والسودان والعراق واليمن والمغرب العربي. اختر لهجة جمهورك واسمع نصك بنطق أهل البلد.',
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'كم لهجة عربية تدعمها منصة إسمع؟', 'answer' => $accents.' لكنة من '.$countries.' دولة، بالإضافة إلى العربية الفصحى.'],
                    ['question' => 'هل أكتب النص بالعامية أم بالفصحى؟', 'answer' => 'للحصول على أكثر نتيجة طبيعية اكتب النص باللهجة نفسها. ويمكنك أيضاً كتابة نص فصيح واختيار اللهجة.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات واللهجات في العرض التجريبي.'],
                ],
                'cta_title' => 'اسمع نصك بلهجة جمهورك',
                'cta_body' => 'أنشئ حسابك واختر الفصحى أو واحدة من '.$accents.' لكنة عربية.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        return [
            'eyebrow' => 'Arabic dialects',
            'h1' => 'Text to Speech in Every Major Arabic Dialect',
            'intro' => 'Listen speaks '.$accents.' Arabic accents from '.$countries.' countries plus Modern Standard Arabic: Gulf, Levantine, Egyptian, Sudanese, Iraqi, Yemeni and Maghrebi dialects. Pick your audience\'s dialect and hear your script the way locals say it.',
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => 'How many Arabic dialects does Listen support?', 'answer' => $accents.' accents from '.$countries.' countries, plus Modern Standard Arabic (Fusha).'],
                ['question' => 'Should I write the script in dialect or in Fusha?', 'answer' => 'For the most natural result, write the script in the dialect itself. You can also write in Fusha and choose a dialect.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices and dialects in the demo.'],
            ],
            'cta_title' => 'Speak your audience\'s dialect',
            'cta_body' => 'Create your account and choose Fusha or one of '.$accents.' Arabic accents.',
            'cta_primary' => 'Create your account',
        ];
    }

    /**
     * Hub cards grouped by region.
     *
     * @return array<int, array{region: string, items: array<int, array{slug: string, label: string, accents: int}>}>
     */
    public static function hubGroups(string $locale): array
    {
        $regions = ArabicDialects::regions();
        $byRegion = [];

        foreach (self::PAGES as $slug => $row) {
            $accents = self::accents($slug);
            if ($accents === []) {
                continue;
            }
            $region = ArabicDialects::ALL[$accents[0]['code']]['region'] ?? 'fusha';
            $byRegion[$region][] = [
                'slug' => $slug,
                'url' => route('seo.dialect', ['dialect' => $slug]),
                'label' => $locale === 'ar'
                    ? (self::isFusha($slug) ? 'العربية الفصحى' : 'اللهجة '.$row['ar'])
                    : ($row['en'].(self::isFusha($slug) ? '' : ' Arabic')),
                'accents' => count($accents),
            ];
        }

        $groups = [];
        foreach ($regions as $key => $names) {
            if (! isset($byRegion[$key])) {
                continue;
            }
            $groups[] = ['region' => $locale === 'ar' ? $names['ar'] : $names['en'], 'items' => $byRegion[$key]];
        }

        return $groups;
    }

    /**
     * Short link list for footers and related sections.
     *
     * @return array<int, array{slug: string, label: string}>
     */
    public static function popular(string $locale, int $limit = 8): array
    {
        // Region by region (Fusha, Gulf, Levant, Egypt, Iraq, Maghreb), not one country first.
        $order = ['fusha', 'saudi', 'emirati', 'lebanese', 'egyptian', 'iraqi', 'moroccan', 'kuwaiti', 'syrian', 'jordanian', 'qatari', 'algerian', 'tunisian'];
        $links = [];

        foreach (array_slice($order, 0, $limit) as $slug) {
            $row = self::PAGES[$slug];
            $links[] = [
                'slug' => $slug,
                'label' => $locale === 'ar'
                    ? (self::isFusha($slug) ? 'صوت بالفصحى' : 'صوت باللهجة '.$row['ar'])
                    : ($row['en'].(self::isFusha($slug) ? '' : ' Arabic').' TTS'),
            ];
        }

        return $links;
    }

    /**
     * A real hero sample for the page's main accent, when one exists.
     *
     * @return array{text: string, audio_url: string}|null
     */
    public static function sample(string $slug, string $locale): ?array
    {
        $codes = array_map(fn (array $a): string => $a['code'], self::accents($slug));
        if ($codes === []) {
            return null;
        }

        try {
            return Cache::remember('seo_dialect_sample:'.$slug.':'.$locale, 600, function () use ($codes, $locale): ?array {
                $samples = VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', 'ar')
                    ->whereIn('dialect', $codes)
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->get();

                foreach ($codes as $code) {
                    $sample = $samples->first(fn (VoiceSample $s): bool => strcasecmp((string) $s->dialect, $code) === 0
                        && trim((string) $s->audio_path) !== '');
                    if ($sample) {
                        return [
                            'text' => trim((string) ($sample->sample_text_ar ?: $sample->sample_text)),
                            'audio_url' => AudioUrl::fromPath(ltrim((string) $sample->audio_path, '/')),
                        ];
                    }
                }

                return null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    private static function accentShortName(string $name): string
    {
        // "Egyptian (Cairene)" → "Cairene", "Egyptian (Delta, rural)" → "Delta"; "Emirati" stays.
        $short = preg_match('/\(([^)]+)\)/u', $name, $m) ? $m[1] : $name;

        return trim(explode(',', $short)[0]);
    }
}
