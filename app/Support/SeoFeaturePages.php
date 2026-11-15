<?php

namespace App\Support;

/**
 * One page per studio feature that people (and AI assistants) search for by name:
 * emotions, laughs and whispers written into the text; two voices in one recording;
 * cloning your own voice. Every number comes from the studio itself (StudioFacts), and
 * a page about a plan feature is shown only while a plan on sale includes it.
 */
final class SeoFeaturePages
{
    /** @var array<string, array{en: string, ar: string}> */
    public const PAGES = [
        'text-to-speech-with-emotions' => ['en' => 'Emotions, laughs and whispers', 'ar' => 'المشاعر والضحك والهمس'],
        'two-voice-dialogue' => ['en' => 'Two-voice dialogue', 'ar' => 'حوار بصوتين'],
        'voice-cloning' => ['en' => 'Voice cloning', 'ar' => 'استنساخ الصوت'],
    ];

    /**
     * Every feature page's address, whether or not it is shown now (for the route).
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys(self::PAGES);
    }

    /** The feature is in the studio now: a plan on sale includes it. */
    public static function available(string $slug): bool
    {
        return match ($slug) {
            'text-to-speech-with-emotions' => StudioFacts::emotions() > 0 && StudioFacts::soundTags() > 0,
            'two-voice-dialogue' => StudioFacts::dialoguePlans('en') !== [],
            'voice-cloning' => StudioFacts::cloning(),
            default => false,
        };
    }

    public static function pageKey(string $slug): string
    {
        return 'feature_'.str_replace('-', '_', $slug);
    }

    public static function slugForPageKey(string $page): ?string
    {
        if (! str_starts_with($page, 'feature_')) {
            return null;
        }
        $slug = str_replace('_', '-', substr($page, 8));

        return isset(self::PAGES[$slug]) ? $slug : null;
    }

    /**
     * Page definitions for SeoHelper::pageDefinitions().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pageDefinitions(string $robots): array
    {
        $definitions = [];
        foreach (self::slugs() as $slug) {
            $available = self::available($slug);
            $definitions[self::pageKey($slug)] = [
                'label' => self::PAGES[$slug]['en'],
                'title' => self::title($slug, 'en'),
                'description' => self::description($slug, 'en'),
                'robots' => $available ? $robots : 'noindex, follow',
                'path' => '/'.$slug,
                'localized' => true,
                'indexable' => $available,
                'generated' => true,
                'sitemap_priority' => 0.85,
                'sitemap_change_frequency' => 'weekly',
            ];
        }

        return $definitions;
    }

    public static function title(string $slug, string $locale): string
    {
        $ar = $locale === 'ar';

        return match ($slug) {
            'text-to-speech-with-emotions' => $ar
                ? 'تحويل النص إلى صوت بالمشاعر والضحك والهمس'
                : 'Text to Speech with Emotions, Laughs and Whispers',
            'two-voice-dialogue' => $ar
                ? 'حوار وبودكاست بصوتين بالذكاء الاصطناعي'
                : 'Two-Voice Dialogue and Podcast AI Voice Generator',
            'voice-cloning' => $ar
                ? 'استنساخ الصوت بالذكاء الاصطناعي باللهجات العربية'
                : 'AI Voice Cloning in Arabic Dialects and English',
            default => '',
        };
    }

    public static function description(string $slug, string $locale): string
    {
        $ar = $locale === 'ar';

        return match ($slug) {
            'text-to-speech-with-emotions' => $ar
                ? 'اكتب الضحكة والهمسة والتنهيدة داخل النص، واختر من '.StudioFacts::emotions().' شعوراً و'.StudioFacts::styles().' أسلوب أداء، بالعربية بلهجاتها وبالإنجليزية.'
                : 'Write laughs, whispers and sighs into the script and choose from '.StudioFacts::emotions().' emotions and '.StudioFacts::styles().' performance styles, in Arabic dialects and English.',
            'two-voice-dialogue' => $ar
                ? 'صوتان في تسجيل واحد: اكتب من يقول ماذا، واختر لكل متحدث صوته ولهجته. للبودكاست والإعلانات والقصص.'
                : 'Two voices in one recording: write who says what and give each speaker their own voice and dialect. For podcasts, ads and stories.',
            'voice-cloning' => $ar
                ? 'سجّل صوتك مرة واحدة واستخدمه في كل ما تكتبه، باللهجة العربية التي تختارها أو بالإنجليزية.'
                : 'Record your voice once and use it for anything you write, in the Arabic dialect you choose or in English.',
            default => '',
        };
    }

    /**
     * The page's words in the landing page's shape (h1, intro, highlights, use cases, FAQs).
     *
     * @return array<string, mixed>
     */
    public static function content(string $slug, string $locale): array
    {
        $ar = $locale === 'ar';
        $common = [
            'eyebrow' => self::PAGES[$slug][$ar ? 'ar' : 'en'],
            'h1' => self::title($slug, $locale),
            'faq_title' => $ar ? 'الأسئلة الشائعة' : 'Frequently asked questions',
            'cta_primary' => $ar ? 'أنشئ حسابك' : 'Create your account',
        ];

        return $common + match ($slug) {
            'text-to-speech-with-emotions' => self::emotionsContent($ar),
            'two-voice-dialogue' => self::dialogueContent($ar),
            'voice-cloning' => self::cloningContent($ar),
            default => [],
        };
    }

    /**
     * Pages for the other features, for "see also" links.
     *
     * @return array<int, array{slug: string, label: string}>
     */
    public static function links(string $locale): array
    {
        $links = [];
        foreach (self::slugs() as $slug) {
            if (self::available($slug)) {
                $links[] = ['slug' => $slug, 'label' => self::title($slug, $locale)];
            }
        }

        return $links;
    }

    /** @return array<string, mixed> */
    private static function emotionsContent(bool $ar): array
    {
        $emotions = StudioFacts::emotions();
        $styles = StudioFacts::styles();
        $sounds = StudioFacts::soundTags();
        $delivery = StudioFacts::deliveryTags();
        $rooms = StudioFacts::environments();
        $languages = $ar
            ? StudioFacts::arabicDialects().' لهجة عربية من '.StudioFacts::arabicCountries().' دولة والفصحى، و'.StudioFacts::englishAccents().' لكنة إنجليزية'
            : StudioFacts::arabicDialects().' Arabic dialects from '.StudioFacts::arabicCountries().' countries plus Fusha, and '.StudioFacts::englishAccents().' English accents';

        if ($ar) {
            return [
                'intro' => 'اكتب الضحكة حيث يضحك المتحدث، والهمسة حيث يهمس، والتنهيدة حيث يتنهد، فيؤديها الصوت في مكانها. واختر للنص كله شعوراً وأسلوب أداء، ثم ضعه في بيئة صوتية مثل مكالمة هاتفية أو راديو قديم.',
                'highlights_title' => 'ما الذي تتحكم فيه',
                'highlights_intro' => 'كل ذلك من المحرر نفسه، دون برامج مونتاج.',
                'highlights' => [
                    ['title' => $sounds.' صوتاً تكتبه داخل النص', 'body' => 'ضحكة، ضحكة خفيفة، تنهيدة، شهقة، همس، صراخ، سعال، تثاؤب، بكاء وغيرها. اكتب <ضحكة> أو <laugh> في المكان الذي تريده.'],
                    ['title' => $emotions.' شعوراً و'.$styles.' أسلوب أداء', 'body' => 'من الدفء والحماس والجدية إلى الحزن والسخرية، وأساليب مثل الإعلان والسرد القصصي والتعليم والحوار.'],
                    ['title' => $delivery.' طريقة إلقاء', 'body' => 'ببطء أو بسرعة، بصوت منخفض أو عالٍ، همساً أو غناءً، أو بصوت متقطع من الخوف.'],
                    ['title' => $rooms.' بيئة صوتية', 'body' => 'مكالمة هاتفية، لاسلكي، راديو قديم، ملعب، كهف، داخل سيارة، ومن الغرفة المجاورة.'],
                ],
                'use_cases_title' => 'أين تستخدمه',
                'use_cases_intro' => 'حين يحتاج النص إلى أداء وليس قراءة فقط.',
                'use_cases' => [
                    ['title' => 'الإعلانات والمحتوى القصير', 'body' => 'ضحكة في المكان الصحيح أو همسة قبل العرض تجعل الإعلان أقرب إلى الناس.'],
                    ['title' => 'القصص والكتب الصوتية', 'body' => 'شخصيات تضحك وتتنهد وتهمس كما في التمثيل الإذاعي.'],
                    ['title' => 'الألعاب والرسوم المتحركة', 'body' => 'أصوات شخصيات بانفعالات واضحة دون جلسات تسجيل.'],
                    ['title' => 'التعليم والتدريب', 'body' => 'نبرة هادئة وواضحة للدروس، وحماس محسوب للمقدمات.'],
                ],
                'faqs' => [
                    ['question' => 'كيف أضيف ضحكة أو همسة إلى النص؟', 'answer' => 'اكتب اسم الصوت بين علامتين في المكان الذي يحدث فيه، مثل <ضحكة> أو <laugh> أو <همس>، أو اختره من قائمة المؤثرات في المحرر.'],
                    ['question' => 'هل يعمل ذلك مع اللهجات العربية؟', 'answer' => 'نعم، مع '.$languages.'.'],
                    ['question' => 'ما الفرق بين الشعور والصوت داخل النص؟', 'answer' => 'الشعور وأسلوب الأداء يُطبقان على النص كله أو على المقطع، أما الضحكة أو التنهيدة فتحدث مرة واحدة في المكان الذي كتبتها فيه.'],
                    ['question' => 'هل يمكن التجربة قبل الاشتراك؟', 'answer' => 'نعم، يمكن للأعضاء الجدد تجربة الأصوات مرة واحدة في العرض التجريبي.'],
                ],
                'cta_title' => 'اكتب الأداء داخل النص',
                'cta_body' => 'أنشئ حسابك وجرّب الضحك والهمس والمشاعر بصوتك المفضل.',
            ];
        }

        return [
            'intro' => 'Write the laugh where the speaker laughs, the whisper where they whisper and the sigh where they sigh, and the voice performs it right there. Choose an emotion and a performance style for the whole script, then place it in a sound environment such as a phone call or an old radio.',
            'highlights_title' => 'What you control',
            'highlights_intro' => 'All from the same editor, with no audio editing software.',
            'highlights' => [
                ['title' => $sounds.' sounds you write into the text', 'body' => 'Laugh, chuckle, sigh, gasp, whisper, shout, cough, yawn, cry and more. Write <laugh> or <whispers> exactly where it happens.'],
                ['title' => $emotions.' emotions and '.$styles.' performance styles', 'body' => 'From warm, excited and serious to sad and sarcastic, with styles such as advert, storytelling, teaching and conversation.'],
                ['title' => $delivery.' ways to deliver a line', 'body' => 'Slowly or quickly, softly or loudly, whispering, singing, or trembling with fear.'],
                ['title' => $rooms.' sound environments', 'body' => 'Phone call, walkie-talkie, old radio, stadium, cave, inside a car, or from the next room.'],
            ],
            'use_cases_title' => 'Where it is used',
            'use_cases_intro' => 'When a script needs a performance, not just a reading.',
            'use_cases' => [
                ['title' => 'Ads and short videos', 'body' => 'A laugh in the right place or a whisper before the offer makes an ad sound human.'],
                ['title' => 'Stories and audiobooks', 'body' => 'Characters who laugh, sigh and whisper, as in radio drama.'],
                ['title' => 'Games and animation', 'body' => 'Character voices with clear emotion and no recording sessions.'],
                ['title' => 'Education and training', 'body' => 'A calm, clear tone for lessons and measured energy for intros.'],
            ],
            'faqs' => [
                ['question' => 'How do I add a laugh or a whisper to the text?', 'answer' => 'Write the sound in angle brackets where it happens, such as <laugh>, <sigh> or <whispers>, or pick it from the effects menu in the editor.'],
                ['question' => 'Does it work in Arabic dialects?', 'answer' => 'Yes, in '.$languages.'.'],
                ['question' => 'What is the difference between an emotion and a sound in the text?', 'answer' => 'An emotion or performance style applies to the whole script or passage. A laugh or a sigh happens once, at the spot where you wrote it.'],
                ['question' => 'Can I try it before I subscribe?', 'answer' => 'Yes. New members can try the voices once in the demo.'],
            ],
            'cta_title' => 'Write the performance into the script',
            'cta_body' => 'Create your account and try laughs, whispers and emotions with your favourite voice.',
        ];
    }

    /** @return array<string, mixed> */
    private static function dialogueContent(bool $ar): array
    {
        $plans = StudioFacts::planList(StudioFacts::dialoguePlans($ar ? 'ar' : 'en'), $ar ? 'ar' : 'en');

        if ($ar) {
            return [
                'intro' => 'اكتب الحوار سطراً سطراً واذكر من يتكلم، واختر لكل متحدث صوته ولهجته، فيؤدي الصوتان النص بالتناوب في تسجيل واحد. مصري يحاور سعودياً، أو مذيعة بالفصحى مع ضيف بالإنجليزية.',
                'highlights_title' => 'كيف يعمل',
                'highlights_intro' => 'تسجيل واحد جاهز، دون تركيب مقاطع منفصلة.',
                'highlights' => [
                    ['title' => 'متحدثان باسميهما', 'body' => 'اكتب اسم المتحدث قبل كلامه، مثل «سارة:» و«أحمد:»، وتعرف المنصة من يقول كل سطر.'],
                    ['title' => 'صوت ولهجة لكل متحدث', 'body' => 'اختر لكل متحدث صوتاً مستقلاً ولهجة عربية أو لكنة إنجليزية مختلفة.'],
                    ['title' => 'مشاعر وضحك داخل الحوار', 'body' => 'أضف الضحكة والتنهيدة والهمسة في سطور أي من المتحدثين.'],
                ],
                'use_cases_title' => 'أين تستخدمه',
                'use_cases_intro' => 'حيث يحتاج المحتوى إلى أكثر من صوت.',
                'use_cases' => [
                    ['title' => 'بودكاست بمقدمَين', 'body' => 'حلقة كاملة بصوتين يتحاوران بطبيعية.'],
                    ['title' => 'إعلانات حوارية', 'body' => 'مشهد قصير بين بائع وزبون أو بين صديقين.'],
                    ['title' => 'دروس ومحادثات لتعلم اللغة', 'body' => 'حوار بين متحدثين بلهجتين أو بلغتين.'],
                    ['title' => 'قصص وتمثيل إذاعي', 'body' => 'شخصيتان تتبادلان الكلام في مشهد واحد.'],
                ],
                'faqs' => [
                    ['question' => 'كم متحدثاً في الحوار؟', 'answer' => 'متحدثان في التسجيل الواحد، لكل منهما صوته ولهجته.'],
                    ['question' => 'هل يمكن أن يتكلم كل متحدث بلهجة مختلفة؟', 'answer' => 'نعم، تختار لكل متحدث لهجته العربية أو لكنته الإنجليزية.'],
                    ['question' => 'في أي باقة تتوفر الحوارات؟', 'answer' => 'تتوفر الحوارات بصوتين في '.$plans.'.'],
                    ['question' => 'كيف أكتب النص؟', 'answer' => 'اكتب اسم المتحدث ثم نقطتين ثم كلامه، وكل سطر في سطر جديد.'],
                ],
                'cta_title' => 'اكتب حوارك واسمعه بصوتين',
                'cta_body' => 'أنشئ حسابك واختر لكل متحدث صوته ولهجته.',
            ];
        }

        return [
            'intro' => 'Write the conversation line by line and say who is speaking, give each speaker their own voice and dialect, and the two voices perform the script turn by turn in one recording. An Egyptian host talking to a Saudi guest, or a Fusha presenter with an English-speaking guest.',
            'highlights_title' => 'How it works',
            'highlights_intro' => 'One finished recording, with no separate clips to stitch together.',
            'highlights' => [
                ['title' => 'Two named speakers', 'body' => 'Write the speaker\'s name before their line, such as "Sara:" and "Ahmed:", and the studio knows who says each line.'],
                ['title' => 'A voice and dialect for each speaker', 'body' => 'Give each speaker their own voice and a different Arabic dialect or English accent.'],
                ['title' => 'Emotions and laughs inside the dialogue', 'body' => 'Add laughs, sighs and whispers to either speaker\'s lines.'],
            ],
            'use_cases_title' => 'Where it is used',
            'use_cases_intro' => 'Wherever the content needs more than one voice.',
            'use_cases' => [
                ['title' => 'Two-host podcasts', 'body' => 'A whole episode with two voices talking naturally.'],
                ['title' => 'Conversational ads', 'body' => 'A short scene between a seller and a customer, or two friends.'],
                ['title' => 'Language-learning dialogues', 'body' => 'Conversations between speakers of two dialects or two languages.'],
                ['title' => 'Stories and radio drama', 'body' => 'Two characters trading lines in one scene.'],
            ],
            'faqs' => [
                ['question' => 'How many speakers can a dialogue have?', 'answer' => 'Two speakers in one recording, each with their own voice and dialect.'],
                ['question' => 'Can each speaker use a different dialect?', 'answer' => 'Yes. You choose each speaker\'s Arabic dialect or English accent.'],
                ['question' => 'Which plan includes dialogue?', 'answer' => 'Two-voice dialogue is included in '.$plans.'.'],
                ['question' => 'How do I write the script?', 'answer' => 'Write the speaker\'s name, a colon, then their line, with each line on a new line.'],
            ],
            'cta_title' => 'Write your dialogue and hear it in two voices',
            'cta_body' => 'Create your account and give each speaker a voice and a dialect.',
        ];
    }

    /** @return array<string, mixed> */
    private static function cloningContent(bool $ar): array
    {
        [$min, $max] = StudioFacts::cloningRange() ?? [0, 0];
        $range = $min === $max ? (string) $max : $min.'–'.$max;

        if ($ar) {
            return [
                'intro' => 'سجّل عينة قصيرة من صوتك وجملة موافقة، فتبني منصة إسمع صوتاً يقرأ أي نص تكتبه، باللهجة العربية التي تختارها أو بالإنجليزية.',
                'highlights_title' => 'كيف يعمل',
                'highlights_intro' => 'من التسجيل إلى صوت جاهز في المحرر.',
                'highlights' => [
                    ['title' => 'سجّل مرة واحدة', 'body' => 'عينة قصيرة من صوتك وجملة تؤكد فيها موافقتك على استنساخه.'],
                    ['title' => 'بأي لهجة تختارها', 'body' => 'استخدم صوتك المستنسخ بالفصحى أو بلهجة عربية أو بالإنجليزية.'],
                    ['title' => 'مع المشاعر والضحك', 'body' => 'أضف الشعور وأسلوب الأداء والضحكة والهمسة كما مع أي صوت آخر.'],
                ],
                'use_cases_title' => 'أين تستخدمه',
                'use_cases_intro' => 'حين يجب أن يبقى الصوت صوتك.',
                'use_cases' => [
                    ['title' => 'صناع المحتوى', 'body' => 'فيديوهاتك بصوتك دون أن تسجل كل حلقة من جديد.'],
                    ['title' => 'المدربون والمعلمون', 'body' => 'دروس كثيرة بصوت المدرب نفسه.'],
                    ['title' => 'العلامات التجارية', 'body' => 'صوت ثابت للإعلانات والرسائل الصوتية.'],
                    ['title' => 'المحتوى بلغتين', 'body' => 'النسخة العربية والإنجليزية من المحتوى بصوتك.'],
                ],
                'faqs' => [
                    ['question' => 'ماذا أحتاج لاستنساخ صوتي؟', 'answer' => 'عينة قصيرة من صوتك وجملة موافقة تسجلها بنفسك.'],
                    ['question' => 'هل يتكلم الصوت المستنسخ بلهجات أخرى؟', 'answer' => 'نعم، تستخدمه باللهجة التي تختارها عند الإنشاء.'],
                    ['question' => 'كم صوتاً يمكنني استنساخه؟', 'answer' => ($min === $max ? StudioFacts::arabicCount($max, 'صوت', 'أصوات', 'صوتاً') : 'من '.$min.' إلى '.StudioFacts::arabicCount($max, 'صوت', 'أصوات', 'صوتاً')).' حسب الباقة.'],
                    ['question' => 'هل يمكن استنساخ صوت شخص آخر؟', 'answer' => 'لا، يتطلب الاستنساخ جملة موافقة بصوت صاحب الصوت نفسه.'],
                ],
                'cta_title' => 'اصنع صوتك الرقمي',
                'cta_body' => 'أنشئ حسابك وسجّل صوتك مرة واحدة.',
            ];
        }

        return [
            'intro' => 'Record a short sample of your voice and a consent line, and Listen builds a voice that reads anything you write, in the Arabic dialect you choose or in English.',
            'highlights_title' => 'How it works',
            'highlights_intro' => 'From recording to a voice ready in the editor.',
            'highlights' => [
                ['title' => 'Record once', 'body' => 'A short sample of your voice and a line confirming you agree to it being cloned.'],
                ['title' => 'In the dialect you choose', 'body' => 'Use your cloned voice in Fusha, an Arabic dialect or English.'],
                ['title' => 'With emotions and laughs', 'body' => 'Add emotions, performance styles, laughs and whispers as with any other voice.'],
            ],
            'use_cases_title' => 'Where it is used',
            'use_cases_intro' => 'When the voice has to stay yours.',
            'use_cases' => [
                ['title' => 'Content creators', 'body' => 'Your videos in your voice without recording every episode again.'],
                ['title' => 'Trainers and teachers', 'body' => 'Many lessons in the same trainer\'s voice.'],
                ['title' => 'Brands', 'body' => 'One consistent voice for ads and voice messages.'],
                ['title' => 'Content in two languages', 'body' => 'The Arabic and English versions of your content in your voice.'],
            ],
            'faqs' => [
                ['question' => 'What do I need to clone my voice?', 'answer' => 'A short sample of your voice and a consent line you record yourself.'],
                ['question' => 'Does the cloned voice speak other dialects?', 'answer' => 'Yes. You use it in the dialect you choose when you generate.'],
                ['question' => 'How many voices can I clone?', 'answer' => $range.' voices, depending on the plan.'],
                ['question' => 'Can I clone someone else\'s voice?', 'answer' => 'No. Cloning needs a consent line spoken by the owner of the voice.'],
            ],
            'cta_title' => 'Make your digital voice',
            'cta_body' => 'Create your account and record your voice once.',
        ];
    }
}
