<?php

namespace App\Support;

/**
 * The studio's features page (/features): everything the studio does, in one
 * place a person or a search engine can read, with every count and every list
 * taken from the studio's own catalogs (StudioFacts) and what depends on a plan
 * read from the plans on sale. Nothing here is written about other products.
 */
final class SeoFeaturesPage
{
    public const PAGE = 'features';

    /**
     * The page's content in the shape the SEO page template reads.
     *
     * @return array<string, mixed>
     */
    public static function content(string $locale): array
    {
        $ar = $locale === 'ar';
        $dialects = StudioFacts::arabicDialects();
        $fusha = StudioFacts::fushaStyles();
        $countries = StudioFacts::arabicCountries();
        $english = StudioFacts::englishAccents();
        $englishCountries = StudioFacts::englishCountries();
        $emotions = StudioFacts::emotions();
        $styles = StudioFacts::styles();
        $sounds = StudioFacts::soundTags();
        $delivery = StudioFacts::deliveryTags();
        $environments = StudioFacts::environments();
        $dialoguePlans = StudioFacts::dialoguePlans($locale);
        $cloning = StudioFacts::cloningRange();
        $formats = StudioFacts::downloadFormats();

        $cards = $ar ? [
            ['title' => $dialects.' لهجة عربية و'.$english.' لكنة إنجليزية', 'body' => 'اختر اللهجة فيُقرأ النص بنطق أهلها ومفرداتهم: الخليج والشام ومصر والسودان والعراق واليمن والمغرب العربي، والفصحى بـ'.$fusha.' أساليب، والإنجليزية بلكنات من '.$englishCountries.' دولة.'],
            ['title' => $emotions.' شعوراً للنص كله', 'body' => 'حدّد إحساس التسجيل كله: دافئ أو مبتهج أو فخور، أو حزين أو متوتر أو غاضب.'],
            ['title' => $styles.' أسلوب أداء', 'body' => 'اقرأ النص كإعلان تلفزيوني أو إعلان سينمائي أو كتاب صوتي أو قصة قبل النوم أو نشرة أخبار أو درس.'],
            ['title' => 'إحساس يتغيّر داخل النص', 'body' => 'اكتب وسماً مثل [متحمس] أو [هامس] حيث يجب أن تتغيّر طريقة القراءة، فيبقى الصوت عليها حتى الوسم التالي. '.$delivery.' وسماً للمزاج والسرعة وطبقة الصوت.'],
            ['title' => 'ضحك وتنهيد ووقفات حقيقية', 'body' => 'اكتب صوتاً مثل <ضحكة> أو <تنهيدة> أو <وقفة قصيرة> في مكانه من النص، فيؤديه الصوت هناك. '.$sounds.' صوتاً، بأسمائها العربية أو الإنجليزية.'],
        ] : [
            ['title' => $dialects.' Arabic dialects and '.$english.' English accents', 'body' => 'Pick a dialect and the script is spoken the way locals say it, in their words: Gulf, Levantine, Egyptian, Sudanese, Iraqi, Yemeni and Maghrebi Arabic, Modern Standard Arabic in '.$fusha.' styles, and English accents from '.$englishCountries.' countries.'],
            ['title' => $emotions.' emotions for a whole script', 'body' => 'Set the feeling of the whole recording: warm, joyful or proud, sad, tense or furious.'],
            ['title' => $styles.' performance styles', 'body' => 'Read a script as a TV ad, a cinematic trailer, an audiobook, a bedtime story, a newscast or a lesson.'],
            ['title' => 'Feelings that change inside the script', 'body' => 'Write a tag such as [excited] or [whispering] where the delivery should change, and the voice keeps it until the next tag. '.$delivery.' tags for mood, pace and voice.'],
            ['title' => 'Real laughs, sighs and pauses', 'body' => 'Write a sound such as <laugh>, <sigh> or <short pause> where it happens, and the voice performs it there. '.$sounds.' sounds, written in English or Arabic.'],
        ];
        if ($dialoguePlans !== []) {
            $cards[] = $ar
                ? ['title' => 'صوتان في تسجيل واحد', 'body' => 'اكتب حواراً لمتحدثَين، لكل منهما لهجته، واحصل على تسجيل واحد: مقابلات وبودكاست وإعلانات ودروس. في '.StudioFacts::planList($dialoguePlans, 'ar').'.']
                : ['title' => 'Two voices in one recording', 'body' => 'Write a conversation for two speakers, each in their own dialect, and get one recording: interviews, podcasts, ads and lessons. On '.StudioFacts::planList($dialoguePlans, 'en').'.'];
        }
        if ($cloning !== null) {
            [$fewest, $most] = $cloning;
            $howMany = $fewest === $most ? (string) $most : $fewest.($ar ? ' إلى ' : ' to ').$most;
            $cards[] = $ar
                ? ['title' => 'استنساخ الصوت', 'body' => 'استنسخ صوتاً من تسجيل قصير بموافقة صاحبه، واجعله يتكلم بأي لهجة. من '.$howMany.' أصوات بحسب الباقة.']
                : ['title' => 'Voice cloning', 'body' => 'Clone a voice from a short recording, with the speaker\'s consent, and have it speak any dialect. '.$howMany.' voices, depending on the plan.'];
        }
        $cards[] = $ar
            ? ['title' => $environments.' بيئة صوتية', 'body' => 'ضع الصوت في قاعة أو سيارة أو مكالمة هاتفية أو راديو قديم أو ملعب، أو حسّنه بلمسة استوديو جاهزة للبث.']
            : ['title' => $environments.' sound environments', 'body' => 'Place the voice in a concert hall, a car, a phone call, an old radio or a stadium, or give it a broadcast-ready studio polish.'];
        $cards[] = $ar
            ? ['title' => 'السرعة والطبقة والترجمة', 'body' => 'اضبط سرعة الكلام وطبقة الصوت، أو اكتب بلغة واسمع النص بالأخرى.']
            : ['title' => 'Speed, pitch and translation', 'body' => 'Set the speaking speed and the pitch, or write in one language and hear it in the other.'];
        $cards[] = $ar
            ? ['title' => 'نصوص طويلة بتسجيل واحد', 'body' => 'يُقرأ النص الطويل على أجزاء ويُجمع في تسجيل واحد، فيبدو الفصل كله أداءً واحداً.']
            : ['title' => 'Long scripts, one recording', 'body' => 'A long text is read in parts and joined into one recording, so a whole chapter sounds like one take.'];
        $cards[] = self::historyCard($ar, $formats);
        $cards[] = $ar
            ? ['title' => 'واجهة برمجية للمطورين', 'body' => 'ولّد الأصوات نفسها من تطبيقك عبر الواجهة البرمجية: جميع اللهجات واللكنات والمشاعر والوسوم، محسوبة من رصيد باقتك.']
            : ['title' => 'An API for developers', 'body' => 'Make the same voices from your own app with the API: all the dialects, accents, emotions and tags, counted against your plan\'s credits.'];
        $cards[] = $ar
            ? ['title' => 'إضافة لمتصفح كروم', 'body' => 'حدّد نصاً في أي صفحة ويب واسمعه بصوت عربي أو إنجليزي طبيعي.']
            : ['title' => 'A Chrome extension', 'body' => 'Select text on any web page and hear it read aloud in natural Arabic or English.'];

        $faqs = $ar ? [
            ['question' => 'كم لهجة ولكنة في استوديو إسمع؟', 'answer' => $dialects.' لهجة عربية من '.$countries.' دولة، والعربية الفصحى بـ'.$fusha.' أساليب، و'.$english.' لكنة إنجليزية: أكثر من '.StudioFacts::roundedDown(StudioFacts::dialectsAndAccents()).' لهجة ولكنة، في كل باقة.'],
            ['question' => 'هل يمكن أن يتغيّر الإحساس داخل النص نفسه؟', 'answer' => 'نعم. اكتب وسماً مثل [متحمس] أو [حزين] أو [هامس] حيث يجب أن يتغيّر الأداء، فيبقى الصوت عليه حتى الوسم التالي.'],
            ['question' => 'هل يضحك الصوت أو يتنهد أو يتوقف؟', 'answer' => 'نعم. اكتب <ضحكة> أو <تنهيدة> أو <وقفة قصيرة> في مكانها من النص، فيؤديها الصوت هناك مرة واحدة.'],
        ] : [
            ['question' => 'How many dialects and accents does Listen have?', 'answer' => $dialects.' Arabic dialects from '.$countries.' countries, Modern Standard Arabic in '.$fusha.' styles and '.$english.' English accents: more than '.StudioFacts::roundedDown(StudioFacts::dialectsAndAccents()).' dialects and accents, on every plan.'],
            ['question' => 'Can the feeling change inside one script?', 'answer' => 'Yes. Write a tag such as [excited], [sad] or [whispering] where the delivery should change, and the voice keeps it until the next tag.'],
            ['question' => 'Can the voice laugh, sigh or pause?', 'answer' => 'Yes. Write <laugh>, <sigh> or <short pause> where it happens in the text, and the voice performs it there, once.'],
        ];
        if ($dialoguePlans !== []) {
            $faqs[] = $ar
                ? ['question' => 'هل يمكن أن يتكلم صوتان في تسجيل واحد؟', 'answer' => 'نعم، في وضع الحوار: اكتب أسطر كل متحدث واختر صوته ولهجته، فتحصل على تسجيل واحد. وضع الحوار متاح في '.StudioFacts::planList($dialoguePlans, 'ar').'.']
                : ['question' => 'Can two voices talk in one recording?', 'answer' => 'Yes, in dialogue mode: write each speaker\'s lines, pick their voice and dialect, and get one recording. Dialogue mode is on '.StudioFacts::planList($dialoguePlans, 'en').'.'];
        }
        $faqs[] = $ar
            ? ['question' => 'هل توجد واجهة برمجية؟', 'answer' => 'نعم. الواجهة البرمجية تولّد الأصوات نفسها بجميع اللهجات واللكنات والمشاعر والوسوم، ويُحسب استخدامها من رصيد باقتك.']
            : ['question' => 'Is there an API?', 'answer' => 'Yes. The API makes the same voices with all the dialects, accents, emotions and tags, and its use is counted against your plan\'s credits.'];

        return [
            'eyebrow' => $ar ? 'مميزات الاستوديو' : 'Studio features',
            'h1' => $ar ? 'كل ما يقدّمه استوديو إسمع للأصوات' : 'Everything the Listen voice studio can do',
            'intro' => $ar
                ? 'استوديو إسمع استوديو أصوات احترافي بالعربية والإنجليزية: '.$dialects.' لهجة عربية من '.$countries.' دولة، والفصحى بـ'.$fusha.' أساليب، و'.$english.' لكنة إنجليزية. توجّه كل سطر بـ'.$emotions.' شعوراً و'.$styles.' أسلوب أداء ووسوم تكتبها داخل النص، من الضحكة إلى الهمس.'
                : 'Listen is a professional AI voice studio for Arabic and English: '.$dialects.' Arabic dialects from '.$countries.' countries, Modern Standard Arabic in '.$fusha.' styles and '.$english.' English accents. You direct every line with '.$emotions.' emotions, '.$styles.' performance styles and tags written inside the text, from a laugh to a whisper.',
            'highlights_title' => $ar ? 'ما الذي تفعله في الاستوديو' : 'What you can do in the studio',
            'highlights_intro' => $ar ? 'جميع اللهجات واللكنات في كل باقة. هذا ما يفعله الاستوديو اليوم.' : 'All the dialects and accents are on every plan. This is what the studio does today.',
            'highlights' => $cards,
            'lists' => self::lists($ar),
            'faq_title' => $ar ? 'الأسئلة الشائعة' : 'Frequently asked questions',
            'faqs' => $faqs,
            'cta_title' => $ar ? 'اسمعه بلهجتك' : 'Hear it in your dialect',
            'cta_body' => $ar ? 'يمكن للأعضاء الجدد تجربة الأصوات مرة واحدة في العرض التجريبي.' : 'New members can try the voices once in the demo.',
            'cta_primary' => $ar ? 'أنشئ حسابك' : 'Create your account',
        ];
    }

    /**
     * @param  array{every: array<int, string>, some: array<int, string>}  $formats
     * @return array{title: string, body: string}
     */
    private static function historyCard(bool $ar, array $formats): array
    {
        $join = fn (array $items): string => implode($ar ? ' و' : ', ', $items);
        $download = match (true) {
            $formats['every'] !== [] && $formats['some'] !== [] => $ar
                ? ' وحمّله بصيغة '.$join($formats['every']).'، و'.$join($formats['some']).' في الباقات الأعلى.'
                : ' and download it as '.$join($formats['every']).', with '.$join($formats['some']).' on higher plans.',
            $formats['every'] !== [] => $ar ? ' وحمّله بصيغة '.$join($formats['every']).'.' : ' and download it as '.$join($formats['every']).'.',
            default => '.',
        };

        return $ar
            ? ['title' => 'سجل وتحميل', 'body' => 'يُحفظ كل صوت في سجلّك بإعداداته، فتشغّله أو تعيد استخدامه متى شئت'.$download]
            : ['title' => 'History and downloads', 'body' => 'Every voice is kept in your history with its settings, to play again or reuse'.$download];
    }

    /**
     * The studio's choices by name: emotions, styles, the tags written in the text,
     * and the sound environments, each in its picker's groups.
     *
     * @return array<int, array{title: string, intro: string, groups: array<int, array{title: string, items: array<int, string>}>}>
     */
    private static function lists(bool $ar): array
    {
        $byGroup = function (array $rows, array $groupOf, array $groupNames) use ($ar): array {
            $groups = [];
            $first = array_key_first($groupNames);
            foreach ($rows as $row) {
                $value = (string) ($row['value'] ?? '');
                $label = trim((string) ($ar ? ($row['label_ar'] ?? '') : ($row['label'] ?? '')));
                if ($value === '' || $label === '') {
                    continue;
                }
                $groups[$groupOf[$value][1] ?? $first][] = $label;
            }
            $out = [];
            foreach ($groupNames as $key => $names) {
                if (! empty($groups[$key])) {
                    $out[] = ['title' => $names[$ar ? 'ar' : 'en'], 'items' => $groups[$key]];
                }
            }

            return $out;
        };

        $tagGroups = [];
        foreach (GeminiVocalTags::GROUPS as $group => $tags) {
            $tagGroups[] = [
                'title' => (string) __('ui.dashboard.vocal_tag_groups.'.$group, [], $ar ? 'ar' : 'en'),
                'items' => array_map(fn (string $tag): string => '<'.($ar ? (GeminiVocalTags::ARABIC_NAMES[$tag] ?? $tag) : $tag).'>', $tags),
            ];
        }
        foreach (GeminiVocalTags::panelDeliveryGroups() as $group => $tags) {
            $tagGroups[] = [
                'title' => (string) __('ui.dashboard.vocal_delivery_groups.'.$group, [], $ar ? 'ar' : 'en'),
                'items' => array_map(fn (string $tag): string => '['.($ar ? (GeminiVocalTags::DELIVERY_ARABIC[$tag] ?? $tag) : $tag).']', $tags),
            ];
        }

        $environments = [];
        foreach (AudioEffects::GROUPS as $group => $names) {
            $items = [];
            foreach (AudioEffects::ALL as $key => $row) {
                if ($row[1] === $group) {
                    $items[] = $ar ? $row[3] : $row[2];
                }
            }
            if ($items !== []) {
                $environments[] = ['title' => $names[$ar ? 'ar' : 'en'], 'items' => $items];
            }
        }

        return [
            [
                'title' => $ar ? 'المشاعر' : 'Emotions',
                'intro' => $ar ? 'إحساس النص كله، تختاره من قائمة المشاعر.' : 'The feeling of a whole script, chosen from the emotions list.',
                'groups' => $byGroup(TtsStudioConfig::emotionCatalog(), StudioChoices::EMOTIONS, StudioChoices::EMOTION_GROUPS),
            ],
            [
                'title' => $ar ? 'أساليب الأداء' : 'Performance styles',
                'intro' => $ar ? 'كيف يُقرأ النص كله، من الإعلان إلى الكتاب الصوتي.' : 'How the whole script is read, from an ad to an audiobook.',
                'groups' => $byGroup(TtsStudioConfig::performanceStyleCatalog(), StudioChoices::STYLES, StudioChoices::STYLE_GROUPS),
            ],
            [
                'title' => $ar ? 'الوسوم داخل النص' : 'Tags inside the text',
                'intro' => $ar
                    ? 'الأصوات بين < > تُسمع مرة واحدة حيث تكتبها، ووسوم الأداء بين [ ] تغيّر طريقة القراءة من مكانها.'
                    : 'Sounds in < > are heard once where you write them; delivery tags in [ ] change how the text is read from that point on.',
                'groups' => $tagGroups,
            ],
            [
                'title' => $ar ? 'البيئات الصوتية' : 'Sound environments',
                'intro' => $ar ? 'المكان الذي يُسمع فيه الصوت، أو لمسة تحسين له.' : 'Where the voice is heard, or a polish for the voice itself.',
                'groups' => $environments,
            ],
        ];
    }
}
