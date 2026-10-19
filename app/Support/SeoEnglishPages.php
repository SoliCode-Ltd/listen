<?php

namespace App\Support;

use App\Models\VoiceSample;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public SEO pages for English accent text to speech: one hub page
 * (/english-accents) and one page per country (/english-accents/{slug}),
 * in English and Arabic. Accents come from EnglishAccents, the catalog the
 * studio uses.
 */
final class SeoEnglishPages
{
    public const HUB_PAGE = 'english_accents';

    /**
     * slug => country code, English adjective, Arabic adjective (as in "بلكنة …"),
     * and one sentence about the accent in each language.
     *
     * @var array<string, array{country: string, en: string, ar: string, about_en: string, about_ar: string}>
     */
    public const PAGES = [
        'american' => ['country' => 'US', 'en' => 'American', 'ar' => 'أمريكية',
            'about_en' => 'American English is the most widely heard accent in film, apps and online video, from General American to a Southern drawl or a New York accent.',
            'about_ar' => 'الإنجليزية الأمريكية هي اللكنة الأكثر انتشاراً في الأفلام والتطبيقات والفيديو، من الأمريكية العامة إلى اللكنة الجنوبية ولكنة نيويورك.'],
        'british' => ['country' => 'GB', 'en' => 'British', 'ar' => 'بريطانية',
            'about_en' => 'British English ranges from standard Received Pronunciation to London, Northern, Scottish and Welsh accents.',
            'about_ar' => 'تتنوع الإنجليزية البريطانية من اللكنة القياسية إلى لكنات لندن والشمال واسكتلندا وويلز.'],
        'canadian' => ['country' => 'CA', 'en' => 'Canadian', 'ar' => 'كندية',
            'about_en' => 'Canadian English sounds close to American English, with its own vowels and a friendly, neutral tone.',
            'about_ar' => 'الإنجليزية الكندية قريبة من الأمريكية، مع حروف علة خاصة بها ونبرة ودودة ومحايدة.'],
        'irish' => ['country' => 'IE', 'en' => 'Irish', 'ar' => 'أيرلندية',
            'about_en' => 'Irish English has a warm, melodic rhythm that stands out in storytelling and advertising.',
            'about_ar' => 'للإنجليزية الأيرلندية إيقاع دافئ وموسيقي يبرز في القصص والإعلانات.'],
        'australian' => ['country' => 'AU', 'en' => 'Australian', 'ar' => 'أسترالية',
            'about_en' => 'Australian English is relaxed and instantly recognisable, popular for travel, lifestyle and friendly brand voices.',
            'about_ar' => 'الإنجليزية الأسترالية مريحة ومميزة فوراً، ومحبوبة في محتوى السفر وأسلوب الحياة والعلامات التجارية الودودة.'],
        'new-zealand' => ['country' => 'NZ', 'en' => 'New Zealand', 'ar' => 'نيوزيلندية',
            'about_en' => 'New Zealand English has its own distinctive vowels, clearly different from Australian speech.',
            'about_ar' => 'للإنجليزية النيوزيلندية حروف علة مميزة تختلف بوضوح عن اللكنة الأسترالية.'],
        'south-african' => ['country' => 'ZA', 'en' => 'South African', 'ar' => 'جنوب أفريقية',
            'about_en' => 'South African English has a crisp, clear delivery shaped by the country\'s many languages.',
            'about_ar' => 'للإنجليزية الجنوب أفريقية إلقاء واضح ومحدد تأثر بتعدد لغات البلد.'],
        'indian' => ['country' => 'IN', 'en' => 'Indian', 'ar' => 'هندية',
            'about_en' => 'Indian English is spoken by hundreds of millions of people and is a natural choice for South Asian audiences.',
            'about_ar' => 'يتحدث الإنجليزية الهندية مئات الملايين، وهي الخيار الطبيعي لجمهور جنوب آسيا.'],
        'nigerian' => ['country' => 'NG', 'en' => 'Nigerian', 'ar' => 'نيجيرية',
            'about_en' => 'Nigerian English is the voice of Africa\'s largest English-speaking audience.',
            'about_ar' => 'الإنجليزية النيجيرية هي صوت أكبر جمهور ناطق بالإنجليزية في أفريقيا.'],
        'jamaican' => ['country' => 'JM', 'en' => 'Jamaican', 'ar' => 'جامايكية',
            'about_en' => 'Jamaican English carries a light, lively Caribbean accent while keeping standard English words.',
            'about_ar' => 'تحمل الإنجليزية الجامايكية لكنة كاريبية خفيفة ونابضة مع الحفاظ على الكلمات الإنجليزية القياسية.'],
        'singaporean' => ['country' => 'SG', 'en' => 'Singaporean', 'ar' => 'سنغافورية',
            'about_en' => 'Singaporean English pairs standard English words with the quick rhythm of Singapore.',
            'about_ar' => 'تجمع الإنجليزية السنغافورية بين الكلمات الإنجليزية القياسية وإيقاع سنغافورة السريع.'],
        'pakistani' => ['country' => 'PK', 'en' => 'Pakistani', 'ar' => 'باكستانية',
            'about_en' => 'Pakistani English is clear and widely understood, the natural voice for audiences in Pakistan and its diaspora in the Gulf and the UK.',
            'about_ar' => 'الإنجليزية الباكستانية واضحة ومفهومة على نطاق واسع، والصوت الطبيعي لجمهور باكستان وجالياتها في الخليج وبريطانيا.'],
        'filipino' => ['country' => 'PH', 'en' => 'Filipino', 'ar' => 'فلبينية',
            'about_en' => 'Filipino English keeps standard English words with the warm rhythm of the Philippines, familiar across Asia and the Gulf.',
            'about_ar' => 'تحتفظ الإنجليزية الفلبينية بالكلمات الإنجليزية القياسية مع إيقاع الفلبين الدافئ، وهي مألوفة في آسيا والخليج.'],
        'ghanaian' => ['country' => 'GH', 'en' => 'Ghanaian', 'ar' => 'غانية',
            'about_en' => 'Ghanaian English has a clear, steady delivery, the voice of one of West Africa\'s English-speaking audiences.',
            'about_ar' => 'للإنجليزية الغانية إلقاء واضح وثابت، وهي صوت أحد جماهير غرب أفريقيا الناطقة بالإنجليزية.'],
        'kenyan' => ['country' => 'KE', 'en' => 'Kenyan', 'ar' => 'كينية',
            'about_en' => 'Kenyan English is crisp and measured, the voice of East Africa\'s English-speaking audience.',
            'about_ar' => 'الإنجليزية الكينية واضحة ومتزنة، وهي صوت الجمهور الناطق بالإنجليزية في شرق أفريقيا.'],
        'trinidadian' => ['country' => 'TT', 'en' => 'Trinidadian', 'ar' => 'ترينيدادية',
            'about_en' => 'Trinidadian English has a musical Caribbean rhythm, warm and lively, the voice of Trinidad and Tobago.',
            'about_ar' => 'الإنجليزية الترينيدادية ذات إيقاع كاريبي موسيقي دافئ وحيوي، وهي صوت ترينيداد وتوباغو.'],
        'bangladeshi' => ['country' => 'BD', 'en' => 'Bangladeshi', 'ar' => 'بنغلاديشية',
            'about_en' => 'Bangladeshi English is clear and rhythmic, the English heard across Dhaka\'s business, education and media.',
            'about_ar' => 'الإنجليزية البنغلاديشية واضحة وإيقاعية، وهي إنجليزية الأعمال والتعليم والإعلام في دكا.'],
        'sri-lankan' => ['country' => 'LK', 'en' => 'Sri Lankan', 'ar' => 'سريلانكية',
            'about_en' => 'Sri Lankan English is soft and even, with a gentle rhythm that local audiences recognise as their own.',
            'about_ar' => 'الإنجليزية السريلانكية ناعمة ومتزنة بإيقاع هادئ يعرفه الجمهور المحلي.'],
        'malaysian' => ['country' => 'MY', 'en' => 'Malaysian', 'ar' => 'ماليزية',
            'about_en' => 'Malaysian English is quick and friendly, the everyday English of Kuala Lumpur and Malaysian business.',
            'about_ar' => 'الإنجليزية الماليزية سريعة وودودة، وهي إنجليزية الحياة اليومية والأعمال في كوالالمبور.'],
        'ugandan' => ['country' => 'UG', 'en' => 'Ugandan', 'ar' => 'أوغندية',
            'about_en' => 'Ugandan English is clear and warm, the English of Kampala\'s media, schools and business.',
            'about_ar' => 'الإنجليزية الأوغندية واضحة ودافئة، وهي إنجليزية الإعلام والتعليم والأعمال في كمبالا.'],
        'zimbabwean' => ['country' => 'ZW', 'en' => 'Zimbabwean', 'ar' => 'زيمبابوية',
            'about_en' => 'Zimbabwean English is crisp and measured, widely understood across southern Africa.',
            'about_ar' => 'الإنجليزية الزيمبابوية واضحة ومتزنة ومفهومة على نطاق واسع في جنوب أفريقيا.'],
        'french-accent' => ['country' => 'FR', 'en' => 'French', 'ar' => 'فرنسية',
            'about_en' => 'English with a natural French accent suits characters, ads and content made for French-speaking audiences.',
            'about_ar' => 'الإنجليزية بلكنة فرنسية طبيعية تناسب الشخصيات والإعلانات والمحتوى الموجّه للجمهور الناطق بالفرنسية.'],
        'spanish-accent' => ['country' => 'ES', 'en' => 'Spanish', 'ar' => 'إسبانية',
            'about_en' => 'English with a natural Spanish accent, the way Spanish speakers in Spain talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة إسبانية طبيعية كما ينطقها المتحدثين بالإسبانية في إسبانيا، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'latin-american-accent' => ['country' => 'MX', 'en' => 'Latin American', 'ar' => 'لاتينية',
            'about_en' => 'English with a natural Latin American accent, the way Spanish speakers across Mexico and Latin America talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة لاتينية طبيعية كما ينطقها المتحدثين بالإسبانية في المكسيك وأمريكا اللاتينية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'german-accent' => ['country' => 'DE', 'en' => 'German', 'ar' => 'ألمانية',
            'about_en' => 'English with a natural German accent, the way German speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة ألمانية طبيعية كما ينطقها المتحدثين بالألمانية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'italian-accent' => ['country' => 'IT', 'en' => 'Italian', 'ar' => 'إيطالية',
            'about_en' => 'English with a natural Italian accent, the way Italian speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة إيطالية طبيعية كما ينطقها المتحدثين بالإيطالية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'brazilian-accent' => ['country' => 'BR', 'en' => 'Brazilian', 'ar' => 'برازيلية',
            'about_en' => 'English with a natural Brazilian accent, the way Brazilians talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة برازيلية طبيعية كما ينطقها البرازيليين، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'russian-accent' => ['country' => 'RU', 'en' => 'Russian', 'ar' => 'روسية',
            'about_en' => 'English with a natural Russian accent, the way Russian speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة روسية طبيعية كما ينطقها المتحدثين بالروسية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'turkish-accent' => ['country' => 'TR', 'en' => 'Turkish', 'ar' => 'تركية',
            'about_en' => 'English with a natural Turkish accent, the way Turkish speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة تركية طبيعية كما ينطقها المتحدثين بالتركية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'dutch-accent' => ['country' => 'NL', 'en' => 'Dutch', 'ar' => 'هولندية',
            'about_en' => 'English with a natural Dutch accent, the way Dutch speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة هولندية طبيعية كما ينطقها المتحدثين بالهولندية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'polish-accent' => ['country' => 'PL', 'en' => 'Polish', 'ar' => 'بولندية',
            'about_en' => 'English with a natural Polish accent, the way Polish speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة بولندية طبيعية كما ينطقها المتحدثين بالبولندية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'greek-accent' => ['country' => 'GR', 'en' => 'Greek', 'ar' => 'يونانية',
            'about_en' => 'English with a natural Greek accent, the way Greek speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة يونانية طبيعية كما ينطقها المتحدثين باليونانية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'persian-accent' => ['country' => 'IR', 'en' => 'Persian', 'ar' => 'فارسية',
            'about_en' => 'English with a natural Persian accent, the way Persian speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة فارسية طبيعية كما ينطقها المتحدثين بالفارسية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'japanese-accent' => ['country' => 'JP', 'en' => 'Japanese', 'ar' => 'يابانية',
            'about_en' => 'English with a natural Japanese accent, the way Japanese speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة يابانية طبيعية كما ينطقها المتحدثين باليابانية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'korean-accent' => ['country' => 'KR', 'en' => 'Korean', 'ar' => 'كورية',
            'about_en' => 'English with a natural Korean accent, the way Korean speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة كورية طبيعية كما ينطقها المتحدثين بالكورية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'chinese-accent' => ['country' => 'CN', 'en' => 'Chinese', 'ar' => 'صينية',
            'about_en' => 'English with a natural Chinese accent, the way Mandarin speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة صينية طبيعية كما ينطقها المتحدثين بالصينية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'vietnamese-accent' => ['country' => 'VN', 'en' => 'Vietnamese', 'ar' => 'فيتنامية',
            'about_en' => 'English with a natural Vietnamese accent, the way Vietnamese speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة فيتنامية طبيعية كما ينطقها المتحدثون بالفيتنامية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'indonesian-accent' => ['country' => 'ID', 'en' => 'Indonesian', 'ar' => 'إندونيسية',
            'about_en' => 'English with a natural Indonesian accent, the way Indonesian speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة إندونيسية طبيعية كما ينطقها المتحدثون بالإندونيسية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'thai-accent' => ['country' => 'TH', 'en' => 'Thai', 'ar' => 'تايلاندية',
            'about_en' => 'English with a natural Thai accent, the way Thai speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة تايلاندية طبيعية كما ينطقها المتحدثون بالتايلاندية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'portuguese-accent' => ['country' => 'PT', 'en' => 'Portuguese', 'ar' => 'برتغالية',
            'about_en' => 'English with a natural Portuguese accent, the way speakers from Portugal talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة برتغالية طبيعية كما ينطقها المتحدثون من البرتغال، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'swedish-accent' => ['country' => 'SE', 'en' => 'Swedish', 'ar' => 'سويدية',
            'about_en' => 'English with a natural Swedish accent, the way Swedish speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة سويدية طبيعية كما ينطقها المتحدثون بالسويدية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'ukrainian-accent' => ['country' => 'UA', 'en' => 'Ukrainian', 'ar' => 'أوكرانية',
            'about_en' => 'English with a natural Ukrainian accent, the way Ukrainian speakers talk, for characters, ads and content made for that audience.',
            'about_ar' => 'الإنجليزية بلكنة أوكرانية طبيعية كما ينطقها المتحدثون بالأوكرانية، للشخصيات والإعلانات والمحتوى الموجّه لهذا الجمهور.'],
        'arabic-accent' => ['country' => 'AR', 'en' => 'Arabic', 'ar' => 'عربية',
            'about_en' => 'English with a natural Arabic accent sounds familiar to audiences across the Middle East and North Africa.',
            'about_ar' => 'الإنجليزية بلكنة عربية طبيعية مألوفة لدى الجمهور في الشرق الأوسط وشمال أفريقيا.'],
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
        return 'accent_'.str_replace('-', '_', $slug);
    }

    public static function slugForPageKey(string $page): ?string
    {
        if (! str_starts_with($page, 'accent_')) {
            return null;
        }

        $slug = str_replace('_', '-', substr($page, 7));

        return isset(self::PAGES[$slug]) ? $slug : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(string $robots): array
    {
        $definitions = [
            self::HUB_PAGE => [
                'label' => 'English Accents',
                'title' => 'English Accents Text to Speech',
                'title_key' => 'ui.seo.english_accents_title',
                'description' => 'AI text to speech in '.DialectCounts::english().'+ English accents: American, British, Australian, Canadian, Irish, Indian and more.',
                'description_key' => 'ui.seo.english_accents_description',
                'robots' => $robots,
                'path' => '/english-accents',
                'localized' => true,
                'indexable' => true,
                'generated' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
        ];

        foreach (self::PAGES as $slug => $row) {
            $definitions[self::pageKey($slug)] = [
                'label' => $row['en'].' Accent Text to Speech',
                'title' => self::title($slug, 'en'),
                'description' => self::description($slug, 'en'),
                'robots' => $robots,
                'path' => '/english-accents/'.$slug,
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
            return 'تحويل النص إلى صوت إنجليزي بلكنة '.$row['ar'];
        }

        return self::isForeignAccent($slug)
            ? 'English Text to Speech with '.self::article($row['en']).' '.$row['en'].' Accent'
            : $row['en'].' Accent Text to Speech';
    }

    public static function description(string $slug, string $locale): string
    {
        $row = self::PAGES[$slug];
        $accents = self::accents($slug);

        if ($locale === 'ar') {
            $names = implode('، ', array_map(fn (array $a): string => self::shortName($a['ar']), array_slice($accents, 0, 3)));

            return 'حوّل النص إلى صوت إنجليزي طبيعي بلكنة '.$row['ar'].' بالذكاء الاصطناعي'
                .(count($accents) > 1 ? ' ('.$names.')' : '')
                .'. اكتب بالعربية أو الإنجليزية وحمّل MP3 من منصة إسمع.';
        }

        $names = implode(', ', array_map(fn (array $a): string => self::shortName($a['en']), array_slice($accents, 0, 3)));
        $subject = self::isForeignAccent($slug) ? 'English with a natural '.$row['en'].' accent' : $row['en'].' English';

        return 'Turn text into natural '.$subject.' speech with AI'
            .(count($accents) > 1 ? ' ('.$names.')' : '')
            .'. Write in English or Arabic and download MP3 on Listen.';
    }

    /**
     * @return array<int, array{code: string, en: string, ar: string, prompt: string}>
     */
    public static function accents(string $slug): array
    {
        $country = self::PAGES[$slug]['country'] ?? '';
        $rows = [];
        foreach (EnglishAccents::ALL as $code => $row) {
            if ($row['country'] === $country) {
                $rows[] = ['code' => $code, 'en' => $row['en'], 'ar' => $row['ar'], 'prompt' => $row['prompt']];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(string $slug, string $locale): array
    {
        $row = self::PAGES[$slug];
        $accents = self::accents($slug);

        if ($locale === 'ar') {
            $accent = 'بلكنة '.$row['ar'];
            $names = implode('، ', array_map(fn (array $a): string => $a['ar'], $accents));

            return [
                'eyebrow' => 'صوت إنجليزي '.$accent,
                'h1' => self::title($slug, 'ar'),
                'intro' => $row['about_ar'].' مع منصة إسمع تكتب نصك بالإنجليزية، أو بالعربية مع الترجمة المدمجة، وتحصل على تعليق صوتي إنجليزي '.$accent.' جاهز للتحميل خلال ثوانٍ.',
                'accents_title' => count($accents) > 1 ? 'اللكنات المتاحة' : 'اللكنة المتاحة',
                'accents_intro' => count($accents) > 1 ? 'اختر من داخل الاستوديو: '.$names.'.' : 'متاحة من داخل الاستوديو مع جميع الأصوات عالية الدقة.',
                'accents' => array_map(fn (array $a): array => ['title' => $a['ar'], 'body' => ''], $accents),
                'highlights_title' => 'لماذا منصة إسمع للصوت الإنجليزي؟',
                'highlights_intro' => 'صوت إنجليزي طبيعي، مع ميزة لا تجدها في الأدوات الأخرى: اكتب بالعربية واسمع بالإنجليزية.',
                'highlights' => [
                    ['title' => 'اكتب بالعربية، اسمع بالإنجليزية', 'body' => 'الترجمة المدمجة تحوّل نصك العربي إلى إنجليزية قبل توليد الصوت '.$accent.'.'],
                    ['title' => 'أصوات عالية الدقة مع تحكم في الأداء', 'body' => 'اختر النبرة والمشاعر وأسلوب الإلقاء: إعلاني، قصصي، تعليمي، أو حواري.'],
                    ['title' => 'نسخة عربية ونسخة إنجليزية من مكان واحد', 'body' => 'أنشئ المحتوى نفسه بالإنجليزية وبأي لهجة عربية دون التنقل بين أدوات.'],
                ],
                'use_cases_title' => 'استخدامات شائعة',
                'use_cases_intro' => 'تعليق صوتي إنجليزي '.$accent.' لكل ما تنشره.',
                'use_cases' => [
                    ['title' => 'فيديوهات يوتيوب وتيك توك والإعلانات', 'body' => 'تعليق صوتي إنجليزي احترافي لجمهورك الدولي.'],
                    ['title' => 'الدورات وتعلم الإنجليزية', 'body' => 'دروس مسموعة ونطق واضح يساعد على التعلم والاستماع.'],
                    ['title' => 'عروض المنتجات والشركات', 'body' => 'شروحات وعروض تقديمية بصوت إنجليزي واثق.'],
                    ['title' => 'الكتب الصوتية والقصص', 'body' => 'حوّل المقالات والقصص الإنجليزية إلى محتوى مسموع.'],
                ],
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'هل يمكنني كتابة النص بالعربية والحصول على صوت إنجليزي '.$accent.'؟', 'answer' => 'نعم، فعّل الترجمة في الاستوديو فيُترجم النص إلى الإنجليزية ثم يُحوَّل إلى صوت باللكنة التي تختارها.'],
                    ['question' => 'ما اللكنات المتاحة؟', 'answer' => $names.'. وتتوفر لكنات إنجليزية أخرى من صفحة اللكنات الإنجليزية.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي.'],
                    ['question' => 'هل يمكن تحميل الصوت واستخدامه تجارياً؟', 'answer' => 'في كل باقة مدفوعة يمكنك تحميل كل مقطع بصيغة MP3. حقوق الاستخدام التجاري تعتمد على خطتك، وتجد تفاصيلها في صفحة الأسعار.'],
                ],
                'cta_title' => 'ابدأ التعليق الصوتي الإنجليزي '.$accent,
                'cta_body' => 'أنشئ حسابك واسمع نصك خلال ثوانٍ.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        $accentName = self::article($row['en']).' '.$row['en'].' accent';
        $names = implode(', ', array_map(fn (array $a): string => $a['en'], $accents));

        return [
            'eyebrow' => $row['en'].' accent AI voice',
            'h1' => self::title($slug, 'en'),
            'intro' => $row['about_en'].' With Listen you write your script in English, or in Arabic with built-in translation, and get a natural English voiceover with '.$accentName.', ready to download in seconds.',
            'accents_title' => count($accents) > 1 ? 'Accents you can choose' : 'Available accent',
            'accents_intro' => count($accents) > 1 ? 'Pick one in the studio: '.$names.'.' : 'Available in the studio with every HD voice.',
            'accents' => array_map(fn (array $a): array => ['title' => $a['en'].' · '.$a['ar'], 'body' => $a['prompt'].'.'], $accents),
            'highlights_title' => 'Why Listen for English voiceovers',
            'highlights_intro' => 'Natural English voices, plus something other tools don\'t do: write in Arabic, hear it in English.',
            'highlights' => [
                ['title' => 'Write in Arabic, hear English', 'body' => 'Built-in translation turns your Arabic script into English before it is spoken with '.$accentName.'.'],
                ['title' => 'HD voices with performance control', 'body' => 'Set tone, emotion and delivery: advert, storytelling, teaching or conversational.'],
                ['title' => 'English and Arabic from one place', 'body' => 'Produce the same content in English and in any Arabic dialect without switching tools.'],
            ],
            'use_cases_title' => 'Popular uses',
            'use_cases_intro' => 'English voiceovers with '.$accentName.' for everything you publish.',
            'use_cases' => [
                ['title' => 'YouTube, TikTok and ads', 'body' => 'Professional English narration for international audiences.'],
                ['title' => 'Courses and language learning', 'body' => 'Clear spoken lessons and pronunciation practice.'],
                ['title' => 'Product demos and presentations', 'body' => 'Explainers and pitches in a confident English voice.'],
                ['title' => 'Audiobooks and stories', 'body' => 'Turn English articles and stories into audio.'],
            ],
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => 'Can I write in Arabic and get English audio with '.$accentName.'?', 'answer' => 'Yes. Turn on translation in the studio and your script is translated into English, then spoken in the accent you choose.'],
                ['question' => 'Which accents are available?', 'answer' => $names.'. More English accents are listed on the English accents page.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo.'],
                ['question' => 'Can I download the audio and use it commercially?', 'answer' => 'On every paid plan, each clip downloads as MP3. Commercial use depends on your plan; see the pricing page for details.'],
            ],
            'cta_title' => 'Start your English voiceover',
            'cta_body' => 'Create your account and hear your script with '.$accentName.' in seconds.',
            'cta_primary' => 'Create your account',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hubContent(string $locale): array
    {
        $count = count(EnglishAccents::ALL);
        $countries = count(EnglishAccents::COUNTRIES) - 1;

        if ($locale === 'ar') {
            return [
                'eyebrow' => 'اللكنات الإنجليزية',
                'h1' => 'تحويل النص إلى صوت إنجليزي بكل اللكنات الرئيسية',
                'intro' => 'منصة إسمع تتكلم الإنجليزية بـ'.$count.' لكنة من '.$countries.' دولة: الأمريكية والبريطانية والأسترالية والكندية والأيرلندية والهندية وغيرها، ومنها الإنجليزية بلكنة عربية. اكتب بالإنجليزية أو بالعربية مع الترجمة واختر اللكنة.',
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'كم لكنة إنجليزية تدعمها منصة إسمع؟', 'answer' => $count.' لكنة من '.$countries.' دولة، منها الإنجليزية بلكنة عربية.'],
                    ['question' => 'هل يمكنني الكتابة بالعربية والحصول على صوت إنجليزي؟', 'answer' => 'نعم، فعّل الترجمة في الاستوديو فيُترجم النص ثم يُحوَّل إلى صوت إنجليزي.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات واللكنات في العرض التجريبي.'],
                ],
                'cta_title' => 'اسمع نصك بالإنجليزية',
                'cta_body' => 'أنشئ حسابك واختر من '.$count.' لكنة إنجليزية.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        return [
            'eyebrow' => 'English accents',
            'h1' => 'English Text to Speech in Every Major Accent',
            'intro' => 'Listen speaks English in '.$count.' accents from '.$countries.' countries: American, British, Australian, Canadian, Irish, Indian and more, including English with an Arabic accent. Write in English, or in Arabic with translation, and pick the accent.',
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => 'How many English accents does Listen support?', 'answer' => $count.' accents from '.$countries.' countries, including English with an Arabic accent.'],
                ['question' => 'Can I write in Arabic and get English audio?', 'answer' => 'Yes. Turn on translation in the studio and your script is translated, then spoken in English.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices and accents in the demo.'],
            ],
            'cta_title' => 'Hear your script in English',
            'cta_body' => 'Create your account and choose from '.$count.' English accents.',
            'cta_primary' => 'Create your account',
        ];
    }

    /**
     * Hub cards in the shape the landing view renders.
     *
     * @return array<int, array{region: string, items: array<int, array{url: string, label: string, accents: int}>}>
     */
    public static function hubGroups(string $locale): array
    {
        $items = [];
        foreach (self::PAGES as $slug => $row) {
            $items[] = [
                'url' => route('seo.accent', ['accent' => $slug]),
                'label' => self::label($slug, $locale),
                'accents' => count(self::accents($slug)),
            ];
        }

        return [['region' => $locale === 'ar' ? 'اللكنات' : 'Accents', 'items' => $items]];
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public static function popular(string $locale, int $limit = 6): array
    {
        $links = [];
        foreach (array_slice(['american', 'british', 'australian', 'canadian', 'irish', 'indian', 'arabic-accent'], 0, $limit) as $slug) {
            $links[] = ['slug' => $slug, 'label' => self::label($slug, $locale)];
        }

        return $links;
    }

    public static function label(string $slug, string $locale): string
    {
        $row = self::PAGES[$slug];

        if ($locale === 'ar') {
            return 'إنجليزي بلكنة '.$row['ar'];
        }

        return self::isForeignAccent($slug) ? 'English, '.$row['en'].' accent' : $row['en'].' English';
    }

    /**
     * A real English sample for the page's accent, when one exists.
     *
     * @return array{text: string, audio_url: string, lang?: string}|null
     */
    public static function sample(string $slug): ?array
    {
        $codes = array_map(fn (array $a): string => $a['code'], self::accents($slug));
        if ($codes === []) {
            return null;
        }

        try {
            return Cache::remember('seo_accent_sample:'.$slug, 600, function () use ($codes): ?array {
                $samples = VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', 'en')
                    ->whereIn('dialect', $codes)
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->get();

                foreach ($codes as $code) {
                    $sample = $samples->first(fn (VoiceSample $s): bool => strcasecmp((string) $s->dialect, $code) === 0
                        && trim((string) $s->audio_path) !== '');
                    if ($sample) {
                        return [
                            'text' => trim((string) $sample->sample_text),
                            'audio_url' => AudioUrl::fromPath(ltrim((string) $sample->audio_path, '/')),
                            'lang' => 'en',
                        ];
                    }
                }

                return null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * English spoken with another language's accent (Arabic, French), not a country's English.
     */
    private static function isForeignAccent(string $slug): bool
    {
        return str_ends_with($slug, '-accent');
    }

    private static function article(string $word): string
    {
        return preg_match('/^[AEIOU]/', $word) ? 'an' : 'a';
    }

    private static function shortName(string $name): string
    {
        return preg_match('/\(([^)]+)\)/u', $name, $m) ? $m[1] : $name;
    }
}
