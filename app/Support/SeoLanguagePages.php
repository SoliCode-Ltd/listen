<?php

namespace App\Support;

/**
 * Public SEO pages for the languages beyond Arabic and English: one hub page
 * (/languages) and one page per language (/languages/{slug}), in English and
 * Arabic. The languages come from SpeechLanguages, the list the studio uses.
 */
final class SeoLanguagePages
{
    public const HUB_PAGE = 'languages';

    /**
     * slug => language code and one sentence about where it is spoken.
     *
     * @var array<string, array{code: string, about_en: string, about_ar: string}>
     */
    public const PAGES = [
        'spanish' => ['code' => 'es',
            'about_en' => 'Spanish is spoken across Spain and most of the Americas, one of the largest audiences in the world.',
            'about_ar' => 'تُتحدث الإسبانية في إسبانيا ومعظم الأمريكتين، وجمهورها من الأكبر في العالم.'],
        'french' => ['code' => 'fr',
            'about_en' => 'French is spoken in France, Belgium, Switzerland and Canada, and across North and West Africa.',
            'about_ar' => 'تُتحدث الفرنسية في فرنسا وبلجيكا وسويسرا وكندا، وفي شمال أفريقيا وغربها.'],
        'german' => ['code' => 'de',
            'about_en' => 'German is spoken in Germany, Austria and Switzerland, one of Europe\'s largest markets.',
            'about_ar' => 'تُتحدث الألمانية في ألمانيا والنمسا وسويسرا، وهي من أكبر أسواق أوروبا.'],
        'italian' => ['code' => 'it',
            'about_en' => 'Italian is the language of Italy and of Italian-speaking Switzerland.',
            'about_ar' => 'الإيطالية لغة إيطاليا والجزء الناطق بالإيطالية من سويسرا.'],
        'portuguese' => ['code' => 'pt',
            'about_en' => 'Portuguese is spoken here in its Brazilian form, the voice of Brazil\'s large and fast-growing online audience.',
            'about_ar' => 'البرتغالية هنا بنطقها البرازيلي، صوت الجمهور البرازيلي الكبير والسريع النمو على الإنترنت.'],
        'turkish' => ['code' => 'tr',
            'about_en' => 'Turkish is spoken in Türkiye and Northern Cyprus, and by large communities across Europe.',
            'about_ar' => 'تُتحدث التركية في تركيا وشمال قبرص، ولدى جاليات كبيرة في أوروبا.'],
        'russian' => ['code' => 'ru',
            'about_en' => 'Russian is spoken across Russia, Central Asia and Eastern Europe.',
            'about_ar' => 'تُتحدث الروسية في روسيا وآسيا الوسطى وأوروبا الشرقية.'],
        'dutch' => ['code' => 'nl',
            'about_en' => 'Dutch is spoken in the Netherlands, in Flanders in Belgium, and in Suriname.',
            'about_ar' => 'تُتحدث الهولندية في هولندا ومنطقة الفلاندرز في بلجيكا وفي سورينام.'],
        'polish' => ['code' => 'pl',
            'about_en' => 'Polish is the language of Poland, one of the largest markets in Central Europe.',
            'about_ar' => 'البولندية لغة بولندا، من أكبر أسواق أوروبا الوسطى.'],
        'hindi' => ['code' => 'hi',
            'about_en' => 'Hindi is one of the most spoken languages in the world, heard across India.',
            'about_ar' => 'الهندية من أكثر اللغات تحدثاً في العالم، وتُسمع في أنحاء الهند.'],
        'bengali' => ['code' => 'bn',
            'about_en' => 'Bengali is spoken in Bangladesh and in the Indian state of West Bengal.',
            'about_ar' => 'تُتحدث البنغالية في بنغلاديش وفي ولاية البنغال الغربية في الهند.'],
        'indonesian' => ['code' => 'id',
            'about_en' => 'Indonesian is the national language of Indonesia, the largest country in Southeast Asia.',
            'about_ar' => 'الإندونيسية هي اللغة الوطنية لإندونيسيا، أكبر دول جنوب شرق آسيا.'],
        'japanese' => ['code' => 'ja',
            'about_en' => 'Japanese is the language of Japan, with its own rhythm and polite registers.',
            'about_ar' => 'اليابانية لغة اليابان، بإيقاعها الخاص ومستويات التهذيب في الكلام.'],
        'korean' => ['code' => 'ko',
            'about_en' => 'Korean is the language of Korea, heard worldwide through its music, drama and games.',
            'about_ar' => 'الكورية لغة كوريا، ويسمعها العالم في موسيقاها ومسلسلاتها وألعابها.'],
        'thai' => ['code' => 'th',
            'about_en' => 'Thai is the language of Thailand, a tonal language where the voice carries the meaning.',
            'about_ar' => 'التايلاندية لغة تايلاند، وهي لغة نغمية تحمل فيها نبرة الصوت المعنى.'],
        'vietnamese' => ['code' => 'vi',
            'about_en' => 'Vietnamese is the language of Vietnam, a tonal language with six tones.',
            'about_ar' => 'الفيتنامية لغة فيتنام، وهي لغة نغمية بست نغمات.'],
        'marathi' => ['code' => 'mr',
            'about_en' => 'Marathi is spoken in the Indian state of Maharashtra, home to Mumbai.',
            'about_ar' => 'تُتحدث الماراثية في ولاية ماهاراشترا الهندية، موطن مدينة مومباي.'],
        'tamil' => ['code' => 'ta',
            'about_en' => 'Tamil is spoken in Tamil Nadu in India, in Sri Lanka and in Singapore.',
            'about_ar' => 'تُتحدث التاميلية في ولاية تاميل نادو الهندية وفي سريلانكا وسنغافورة.'],
        'telugu' => ['code' => 'te',
            'about_en' => 'Telugu is spoken in the Indian states of Andhra Pradesh and Telangana.',
            'about_ar' => 'تُتحدث التيلوغوية في ولايتي أندرا براديش وتيلانغانا في الهند.'],
        'gujarati' => ['code' => 'gu',
            'about_en' => 'Gujarati is spoken in the Indian state of Gujarat and by Gujarati communities around the world.',
            'about_ar' => 'تُتحدث الغوجاراتية في ولاية غوجارات الهندية ولدى الجاليات الغوجاراتية حول العالم.'],
        'kannada' => ['code' => 'kn',
            'about_en' => 'Kannada is spoken in the Indian state of Karnataka, home to Bengaluru.',
            'about_ar' => 'تُتحدث الكانادية في ولاية كارناتاكا الهندية، موطن مدينة بنغالورو.'],
        'malayalam' => ['code' => 'ml',
            'about_en' => 'Malayalam is spoken in the Indian state of Kerala and by Malayali communities in the Gulf.',
            'about_ar' => 'تُتحدث المالايالامية في ولاية كيرالا الهندية ولدى الجاليات المالايالية في الخليج.'],
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
        return 'language_'.$slug;
    }

    public static function slugForPageKey(string $page): ?string
    {
        if (! str_starts_with($page, 'language_')) {
            return null;
        }
        $slug = substr($page, 9);

        return isset(self::PAGES[$slug]) ? $slug : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(string $robots): array
    {
        $definitions = [
            self::HUB_PAGE => [
                'label' => 'Languages',
                'title' => 'Text to Speech in 24 Languages',
                'title_key' => 'ui.seo.languages_title',
                'description' => 'AI text to speech in 24 languages.',
                'description_key' => 'ui.seo.languages_description',
                'robots' => $robots,
                'path' => '/languages',
                'localized' => true,
                'indexable' => true,
                'generated' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ],
        ];

        foreach (self::PAGES as $slug => $row) {
            $definitions[self::pageKey($slug)] = [
                'label' => SpeechLanguages::englishName($row['code']).' Text to Speech',
                'title' => self::title($slug, 'en'),
                'description' => self::description($slug, 'en'),
                'robots' => $robots,
                'path' => '/languages/'.$slug,
                'localized' => true,
                'indexable' => true,
                'generated' => true,
                'sitemap_priority' => 0.8,
                'sitemap_change_frequency' => 'weekly',
            ];
        }

        return $definitions;
    }

    public static function name(string $slug, string $locale): string
    {
        $code = self::PAGES[$slug]['code'];
        if ($code === 'pt') {
            return $locale === 'ar' ? 'البرتغالية البرازيلية' : 'Brazilian Portuguese';
        }

        return SpeechLanguages::name($code, $locale === 'ar');
    }

    public static function title(string $slug, string $locale): string
    {
        return $locale === 'ar'
            ? 'تحويل النص إلى صوت باللغة '.self::name($slug, 'ar').' بالذكاء الاصطناعي'
            : self::name($slug, 'en').' Text to Speech with Natural AI Voices';
    }

    public static function description(string $slug, string $locale): string
    {
        $name = self::name($slug, $locale);

        return $locale === 'ar'
            ? 'حوّل النص إلى كلام طبيعي باللغة '.$name.' بالذكاء الاصطناعي. اكتب بها أو بالعربية أو الإنجليزية مع الترجمة المدمجة، أضف المشاعر وحمّل MP3.'
            : 'Turn text into natural '.$name.' speech with AI voices. Write in '.$name.', or in Arabic or English with built-in translation, add emotions and download MP3.';
    }

    public static function label(string $slug, string $locale): string
    {
        $native = SpeechLanguages::nativeName(self::PAGES[$slug]['code']);

        return self::name($slug, $locale).' · '.$native;
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(string $slug, string $locale): array
    {
        $row = self::PAGES[$slug];
        $name = self::name($slug, $locale);
        $native = SpeechLanguages::nativeName($row['code']);

        if ($locale === 'ar') {
            return [
                'eyebrow' => 'صوت '.$name.' بالذكاء الاصطناعي',
                'h1' => self::title($slug, 'ar'),
                'intro' => $row['about_ar'].' مع منصة إسمع تكتب نصك باللغة '.$name.' ('.$native.')، أو بالعربية أو الإنجليزية مع الترجمة المدمجة، وتحصل على تعليق صوتي طبيعي جاهز للتحميل خلال ثوانٍ.',
                'highlights_title' => 'لماذا منصة إسمع للصوت باللغة '.$name.'؟',
                'highlights_intro' => 'أصوات طبيعية مع تحكم كامل في الأداء.',
                'highlights' => [
                    ['title' => 'اكتب بالعربية واسمع باللغة '.$name, 'body' => 'الترجمة المدمجة تحوّل نصك العربي أو الإنجليزي إلى '.$name.' قبل توليد الصوت.'],
                    ['title' => 'المشاعر وأساليب الأداء', 'body' => 'اختر النبرة والمشاعر وأسلوب الإلقاء: إعلاني، قصصي، تعليمي، أو حواري.'],
                    ['title' => 'بيئات صوتية جاهزة', 'body' => 'ضع الصوت في قاعة أو مكالمة هاتفية أو راديو قديم أو مكبر صوت بنقرة واحدة.'],
                ],
                'use_cases_title' => 'استخدامات شائعة',
                'use_cases_intro' => 'تعليق صوتي باللغة '.$name.' لكل ما تنشره.',
                'use_cases' => [
                    ['title' => 'الإعلانات وفيديوهات التواصل', 'body' => 'وصّل علامتك التجارية إلى جمهور يتحدث '.$name.' بصوت طبيعي.'],
                    ['title' => 'الدورات والتعليم', 'body' => 'دروس مسموعة ونطق واضح يساعد على التعلم.'],
                    ['title' => 'عروض المنتجات والشركات', 'body' => 'شروحات وعروض تقديمية بصوت واثق.'],
                    ['title' => 'المحتوى متعدد اللغات', 'body' => 'أنشئ النسخة العربية والإنجليزية والنسخة باللغة '.$name.' من مكان واحد.'],
                ],
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'هل يمكنني كتابة النص بالعربية والحصول على صوت باللغة '.$name.'؟', 'answer' => 'نعم، اختر '.$name.' لغةً للصوت وفعّل الترجمة في الاستوديو، فيُترجم النص ثم يُقرأ بصوت طبيعي.'],
                    ['question' => 'أي باقة أحتاج للصوت باللغة '.$name.'؟', 'answer' => 'اللغة '.$name.' و21 لغة أخرى متاحة بالأصوات المميزة في الباقات المميزة. العربية والإنجليزية متاحتان في كل الباقات.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي.'],
                    ['question' => 'هل يمكن تحميل الصوت واستخدامه تجارياً؟', 'answer' => 'يمكنك تحميل كل مقطع بصيغة MP3. حقوق الاستخدام التجاري تعتمد على باقتك، وتجد تفاصيلها في صفحة الأسعار.'],
                ],
                'cta_title' => 'ابدأ التعليق الصوتي باللغة '.$name,
                'cta_body' => 'أنشئ حسابك واسمع نصك خلال ثوانٍ.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        return [
            'eyebrow' => $name.' AI voice',
            'h1' => self::title($slug, 'en'),
            'intro' => $row['about_en'].' With Listen you write your script in '.$name.' ('.$native.'), or in Arabic or English with built-in translation, and get a natural voiceover ready to download in seconds.',
            'highlights_title' => 'Why Listen for '.$name.' voiceovers',
            'highlights_intro' => 'Natural voices with full control over the performance.',
            'highlights' => [
                ['title' => 'Write in Arabic or English, hear '.$name, 'body' => 'Built-in translation turns your script into '.$name.' before it is spoken.'],
                ['title' => 'Emotions and performance styles', 'body' => 'Set tone, emotion and delivery: advert, storytelling, teaching or conversational.'],
                ['title' => 'Ready-made sound environments', 'body' => 'Put the voice in a hall, on a phone call, an old radio or a megaphone in one click.'],
            ],
            'use_cases_title' => 'Popular uses',
            'use_cases_intro' => $name.' voiceovers for everything you publish.',
            'use_cases' => [
                ['title' => 'Ads and social video', 'body' => 'Reach '.$name.'-speaking audiences with a natural voice.'],
                ['title' => 'Courses and learning', 'body' => 'Clear spoken lessons and pronunciation.'],
                ['title' => 'Product demos and presentations', 'body' => 'Explainers and pitches in a confident voice.'],
                ['title' => 'Multilingual content', 'body' => 'Make the Arabic, English and '.$name.' versions from one place.'],
            ],
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => 'Can I write in Arabic or English and get '.$name.' audio?', 'answer' => 'Yes. Choose '.$name.' as the voice language and turn on translation in the studio: your script is translated, then read in a natural voice.'],
                ['question' => 'Which plan do I need for '.$name.'?', 'answer' => $name.' and 21 more languages use the premium voices on premium plans. Arabic and English are on every plan.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo.'],
                ['question' => 'Can I download the audio and use it commercially?', 'answer' => 'Every clip downloads as MP3. Commercial use depends on your plan; see the pricing page for details.'],
            ],
            'cta_title' => 'Start your '.$name.' voiceover',
            'cta_body' => 'Create your account and hear your script in '.$name.' in seconds.',
            'cta_primary' => 'Create your account',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hubContent(string $locale): array
    {
        $count = count(SpeechLanguages::ALL);
        $more = count(self::PAGES);

        if ($locale === 'ar') {
            return [
                'eyebrow' => 'اللغات',
                'h1' => 'تحويل النص إلى صوت بـ'.$count.' لغة',
                'intro' => 'منصة إسمع تتكلم العربية بالفصحى وبأكثر من 100 لكنة، والإنجليزية بأكثر من 30 لكنة، و'.$more.' لغة أخرى بالأصوات المميزة: الإسبانية والفرنسية والألمانية والبرتغالية والتركية والهندية واليابانية والكورية وغيرها. اكتب بأي لغة واختر لغة الصوت مع الترجمة المدمجة.',
                'faq_title' => 'الأسئلة الشائعة',
                'faqs' => [
                    ['question' => 'كم لغة تدعمها منصة إسمع؟', 'answer' => $count.' لغة: العربية بلهجاتها، والإنجليزية بلكناتها، و'.$more.' لغة أخرى بالأصوات المميزة.'],
                    ['question' => 'هل يمكنني الكتابة بالعربية والحصول على صوت بلغة أخرى؟', 'answer' => 'نعم، اختر لغة الصوت وفعّل الترجمة في الاستوديو، فيُترجم النص ثم يُحوَّل إلى صوت.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يحصل كل حساب جديد على رصيد صغير لتجربة الأصوات في العرض التجريبي.'],
                ],
                'cta_title' => 'اسمع نصك بأي لغة',
                'cta_body' => 'أنشئ حسابك واختر من '.$count.' لغة.',
                'cta_primary' => 'أنشئ حسابك',
            ];
        }

        return [
            'eyebrow' => 'Languages',
            'h1' => 'Text to Speech in '.$count.' Languages',
            'intro' => 'Listen speaks Arabic in Fusha and more than 100 accents, English in more than 30 accents, and '.$more.' more languages on its premium voices: Spanish, French, German, Portuguese, Turkish, Hindi, Japanese, Korean and more. Write in any language and pick the voice language, with built-in translation.',
            'faq_title' => 'Frequently asked questions',
            'faqs' => [
                ['question' => 'How many languages does Listen speak?', 'answer' => $count.' languages: Arabic with its dialects, English with its accents, and '.$more.' more languages on the premium voices.'],
                ['question' => 'Can I write in Arabic and get audio in another language?', 'answer' => 'Yes. Choose the voice language and turn on translation in the studio: your script is translated, then spoken.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. Every new account gets a small allowance to try the voices in the demo.'],
            ],
            'cta_title' => 'Hear your script in any language',
            'cta_body' => 'Create your account and choose from '.$count.' languages.',
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
        $ar = $locale === 'ar';
        $groups = [
            'europe' => [$ar ? 'أوروبا والأمريكتان' : 'Europe and the Americas', ['spanish', 'french', 'german', 'italian', 'portuguese', 'dutch', 'polish', 'russian', 'turkish']],
            'asia' => [$ar ? 'آسيا' : 'Asia', ['japanese', 'korean', 'indonesian', 'thai', 'vietnamese']],
            'india' => [$ar ? 'الهند وبنغلاديش' : 'India and Bangladesh', ['hindi', 'bengali', 'marathi', 'tamil', 'telugu', 'gujarati', 'kannada', 'malayalam']],
        ];
        $out = [[
            'region' => $ar ? 'العربية والإنجليزية' : 'Arabic and English',
            'items' => [
                ['url' => route('seo.arabic-dialects'), 'label' => $ar ? 'العربية بكل لهجاتها' : 'Arabic, every dialect', 'accents' => 0],
                ['url' => route('seo.english-accents'), 'label' => $ar ? 'الإنجليزية بكل لكناتها' : 'English, every accent', 'accents' => 0],
            ],
        ]];
        foreach ($groups as [$region, $slugs]) {
            $out[] = [
                'region' => $region,
                'items' => array_map(fn (string $slug): array => [
                    'url' => route('seo.language', ['language' => $slug]),
                    'label' => self::label($slug, $locale),
                    'accents' => 0,
                ], $slugs),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public static function popular(string $locale, int $limit = 8): array
    {
        $links = [];
        foreach (array_slice(['spanish', 'french', 'german', 'turkish', 'portuguese', 'hindi', 'japanese', 'korean', 'indonesian', 'russian'], 0, $limit) as $slug) {
            $links[] = ['slug' => $slug, 'label' => self::name($slug, $locale)];
        }

        return $links;
    }
}
