<?php

namespace App\Support;

/**
 * What the studio pickers show for each choice: its Listen icon (drawn by
 * js/voice-studio-icons.js in the brand colours), the category it is listed
 * under, and one line about it in English and Arabic.
 *
 * Choices added from the admin catalog have no entry here; they show the
 * default icon in the first category, with no description line.
 */
final class StudioChoices
{
    /**
     * @var array<string, array{en: string, ar: string}>
     */
    public const EMOTION_GROUPS = [
        'bright' => ['en' => 'Positive & bright', 'ar' => 'إيجابية ومشرقة'],
        'gentle' => ['en' => 'Calm & gentle', 'ar' => 'هادئة ولطيفة'],
        'dramatic' => ['en' => 'Serious & dramatic', 'ar' => 'جادة ودرامية'],
        'strong' => ['en' => 'Strong feelings', 'ar' => 'مشاعر قوية'],
    ];

    /**
     * value => [icon, group, about in English, about in Arabic]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const EMOTIONS = [
        'neutral' => ['f-neutral', 'gentle', 'Natural and balanced', 'طبيعي ومتوازن'],
        'warm' => ['f-smile', 'bright', 'Friendly and inviting', 'ودود وقريب من القلب'],
        'joyful' => ['f-grin', 'bright', 'Bright and full of delight', 'مشرق ومليء بالبهجة'],
        'excited' => ['f-starstruck', 'bright', 'High energy and enthusiasm', 'طاقة عالية وحماس'],
        'hopeful' => ['f-hopeful', 'bright', 'Uplifting and optimistic', 'متفائل يبعث الأمل'],
        'playful' => ['f-playful', 'bright', 'Light, fun and cheeky', 'خفيف ومرح وشقي'],
        'confident' => ['f-cool', 'bright', 'Assured and commanding', 'واثق ومسيطر'],
        'proud' => ['f-proud', 'bright', 'Dignified, with quiet triumph', 'بكرامة وانتصار هادئ'],
        'grateful' => ['f-grateful', 'bright', 'Sincere thanks', 'امتنان صادق'],
        'determined' => ['f-determined', 'bright', 'Firm and resolute', 'حازم وعازم'],
        'amazed' => ['f-amazed', 'bright', 'Full of wonder', 'مليء بالدهشة والإعجاب'],
        'relieved' => ['f-relieved', 'bright', 'The worry finally lifts', 'زوال القلق أخيرًا'],
        'curious' => ['f-curious', 'bright', 'Intrigued and interested', 'مهتم ومتشوّق للمعرفة'],
        'calm' => ['f-calm', 'gentle', 'Serene and relaxing', 'هادئ ومريح'],
        'tender' => ['f-tender', 'gentle', 'Soft and caring', 'رقيق وحنون'],
        'empathetic' => ['f-empathetic', 'gentle', 'Understanding and kind', 'متفهم ورحيم'],
        'romantic' => ['f-love', 'gentle', 'Intimate and soft', 'حميمي وناعم'],
        'nostalgic' => ['f-nostalgic', 'gentle', 'Wistful memories', 'حنين إلى الذكريات'],
        'reassuring' => ['f-reassuring', 'gentle', 'Steady: all will be well', 'ثابت ويطمئن السامع'],
        'apologetic' => ['f-apologetic', 'gentle', 'Softly regretful', 'آسف بصدق'],
        'dreamy' => ['f-dreamy', 'gentle', 'Soft and far away', 'ناعم وسارح'],
        'shy' => ['f-shy', 'gentle', 'Soft-spoken and hesitant', 'صوت خافت ومتردد'],
        'sleepy' => ['f-sleepy', 'gentle', 'Drowsy and slow', 'ناعس وبطيء'],
        'formal' => ['f-formal', 'dramatic', 'Professional and dignified', 'رسمي ووقور'],
        'serious' => ['f-serious', 'dramatic', 'Grave and weighty', 'جاد ورصين'],
        'mysterious' => ['f-mysterious', 'dramatic', 'Hushed, hinting at secrets', 'غامض يلمّح إلى الأسرار'],
        'suspenseful' => ['f-suspense', 'dramatic', 'Keeps the listener on edge', 'يشد السامع حتى النهاية'],
        'sarcastic' => ['f-sarcastic', 'dramatic', 'Dry and ironic', 'ساخر بنبرة جافة'],
        'menacing' => ['f-menacing', 'dramatic', 'Low, slow and threatening', 'منخفض وبطيء ومهدِّد'],
        'bored' => ['f-bored', 'dramatic', 'Flat and unenthusiastic', 'فاتر بلا حماس'],
        'sad' => ['f-sad', 'strong', 'Sorrowful, with deep weight', 'حزين بثقل عاطفي عميق'],
        'heartbroken' => ['f-heartbroken', 'strong', 'Quietly devastated', 'محطّم بصمت'],
        'disappointed' => ['f-disappointed', 'strong', 'Let down and deflated', 'خائب ومحبط'],
        'angry' => ['f-angry', 'strong', 'Intense, controlled fury', 'غضب شديد ومكبوت'],
        'frustrated' => ['f-frustrated', 'strong', 'Exasperated, holding back', 'منزعج يكتم غيظه'],
        'anxious' => ['f-anxious', 'strong', 'Uneasy, nervous tension', 'قلق وتوتر'],
        'fearful' => ['f-fear', 'strong', 'Frightened, trembling', 'خائف بصوت مرتجف'],
        'panicked' => ['f-panic', 'strong', 'Urgent and breathless', 'مذعور ولاهث'],
        'surprised' => ['f-surprised', 'strong', 'Genuinely surprised', 'متفاجئ حقًا'],
        'embarrassed' => ['f-embarrassed', 'strong', 'Awkward and flustered', 'محرج ومرتبك'],
        'disgusted' => ['f-disgusted', 'strong', 'Clear distaste', 'اشمئزاز واضح'],
        'cheerful' => ['f-cheerful', 'bright', 'Sunny, smiling delivery', 'مشرق بابتسامة في كل كلمة'],
        'triumphant' => ['f-triumph', 'bright', 'Celebrating a hard-won win', 'احتفال بنصر مستحق'],
        'innocent' => ['f-innocent', 'gentle', 'Soft, pure and wide-eyed', 'بريء وصافٍ ورقيق'],
        'sincere' => ['f-sincere', 'gentle', 'Heartfelt, every word meant', 'من القلب، كل كلمة صادقة'],
        'lonely' => ['f-lonely', 'strong', 'Quiet, withdrawn and alone', 'هادئ ومنعزل ووحيد'],
        'guilty' => ['f-guilty', 'strong', 'Uneasy, hesitant and low', 'قلق ومتردد بصوت منخفض'],
        'jealous' => ['f-jealous', 'strong', 'Resentful, with a bitter edge', 'حاقد بنبرة مُرّة'],
        'furious' => ['f-furious', 'strong', 'Explosive, barely contained rage', 'غضب عارم بالكاد يُكبح'],
        'defiant' => ['f-defiant', 'dramatic', 'Unyielding, standing firm', 'صامد لا يتراجع'],
        'resigned' => ['f-resigned', 'dramatic', 'Weary acceptance', 'قبول متعب لما لا يتغير'],
    ];

    /**
     * @var array<string, array{en: string, ar: string}>
     */
    public const STYLE_GROUPS = [
        'ads' => ['en' => 'Advertising', 'ar' => 'إعلانات وتسويق'],
        'stories' => ['en' => 'Stories & narration', 'ar' => 'قصص وسرد'],
        'media' => ['en' => 'Media & broadcast', 'ar' => 'إعلام وبث'],
        'business' => ['en' => 'Business & learning', 'ar' => 'أعمال وتعليم'],
        'live' => ['en' => 'Live & entertainment', 'ar' => 'عروض وترفيه'],
        'calm' => ['en' => 'Calm & spiritual', 'ar' => 'هدوء وروحانية'],
    ];

    /**
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const STYLES = [
        'neutral' => ['g-wave', 'calm', 'Natural, balanced reading', 'قراءة طبيعية ومتوازنة'],
        'promo' => ['g-rocket', 'ads', 'Cinematic, builds to a crescendo', 'سينمائي يتصاعد بقوة'],
        'commercial' => ['g-tag', 'ads', 'Flash-sale energy and urgency', 'حماس العروض والتخفيضات'],
        'ad_style' => ['g-tv', 'ads', 'Persuasive TV voiceover', 'صوت إعلان تلفزيوني مقنع'],
        'luxury_brand' => ['g-diamond', 'ads', 'Smooth, elegant and refined', 'ناعم وأنيق وراقٍ'],
        'social_media' => ['g-phone-heart', 'ads', 'Punchy creator-style hook', 'أسلوب صنّاع المحتوى الجذاب'],
        'narrative' => ['g-book', 'stories', 'Epic, vivid narration', 'سرد ملحمي حيّ'],
        'storytelling' => ['g-feather', 'stories', 'A master storyteller', 'راوٍ بارع يأسر المستمع'],
        'audiobook' => ['g-headphones', 'stories', 'Professional audiobook narrator', 'راوي كتب صوتية محترف'],
        'children_story' => ['g-star', 'stories', 'Playful wonder for kids', 'دهشة ومرح للأطفال'],
        'bedtime_story' => ['g-moon', 'stories', 'Soft, slow and soothing', 'ناعم وبطيء ومهدّئ'],
        'poetry' => ['g-quill', 'stories', 'Musical rhythm, deliberate pauses', 'إيقاع موسيقي ووقفات مدروسة'],
        'true_crime' => ['g-search', 'stories', 'Low, measured suspense', 'تشويق هادئ ومدروس'],
        'horror' => ['g-ghost', 'stories', 'Hushed, chilling and tense', 'همس مخيف ومتوتر'],
        'cartoon' => ['f-playful', 'stories', 'Bouncy animated character', 'شخصية كرتونية مرحة'],
        'news' => ['g-news', 'media', 'Breaking-news anchor', 'مذيع أخبار عاجلة'],
        'documentary' => ['g-globe', 'media', 'Deep, authoritative gravitas', 'عمق ووقار وثائقي'],
        'podcast' => ['g-mic', 'media', 'Witty, charismatic host', 'مقدم بودكاست ذكي وجذاب'],
        'radio_host' => ['g-radio', 'media', 'Smooth radio sparkle', 'سلاسة وحيوية الإذاعة'],
        'sports_commentator' => ['g-ball', 'media', 'Electric match commentary', 'تعليق رياضي مشتعل'],
        'movie_trailer' => ['g-clapper', 'media', 'Deep, dramatic trailer voice', 'صوت إعلان أفلام عميق'],
        'cinematic' => ['g-film', 'media', 'Sweeping cinematic grandeur', 'فخامة سينمائية'],
        'interview' => ['g-chat2', 'media', 'Thoughtful, natural answers', 'إجابات طبيعية ومدروسة'],
        'dubbing' => ['g-masks', 'media', 'Expressive drama dubbing', 'دبلجة درامية معبّرة'],
        'corporate' => ['g-briefcase', 'business', 'Polished corporate authority', 'رسمي بأسلوب الشركات'],
        'educational' => ['g-cap', 'business', 'Clear, passionate teaching', 'شرح واضح بشغف'],
        'tutorial' => ['g-bulb', 'business', 'Step-by-step and easy to follow', 'خطوة بخطوة وسهل المتابعة'],
        'assistant' => ['g-bot', 'business', 'Upbeat virtual assistant', 'مساعد افتراضي مبتهج'],
        'customer_service' => ['g-headset', 'business', 'Patient, polite and reassuring', 'صبور ومهذب ومطمئن'],
        'phone_system' => ['g-phone', 'business', 'Clear phone-menu prompts', 'رسائل الرد الآلي الواضحة'],
        'announcer' => ['g-bell', 'business', 'Airport and station announcements', 'نداءات المطارات والمحطات'],
        'tour_guide' => ['g-map', 'business', 'Friendly and vivid guide', 'مرشد ودود ومشوّق'],
        'conversational' => ['g-chat', 'live', 'Like chatting with a friend', 'كأنك تتحدث مع صديق'],
        'comedic' => ['f-laugh', 'live', 'Witty timing and humour', 'توقيت كوميدي ذكي'],
        'stand_up' => ['g-spotlight', 'live', 'Build-ups and punchlines', 'تمهيد ثم قفلة مضحكة'],
        'inspirational' => ['g-flame', 'live', 'Fiery motivational speaker', 'متحدث تحفيزي مشتعل'],
        'oratory' => ['g-podium', 'live', 'Resonant, rousing speech', 'خطابة رنانة ومؤثرة'],
        'event_host' => ['g-party', 'live', 'Energetic MC on stage', 'مقدم حفلات نشيط'],
        'game_narrator' => ['g-gamepad', 'live', 'Epic video-game narrator', 'راوي ألعاب ملحمي'],
        'meditation' => ['g-lotus', 'calm', 'Slow, hypnotic serenity', 'سكينة بطيئة وعميقة'],
        'asmr' => ['g-ear', 'calm', 'Close, gentle whisper', 'همس قريب ولطيف'],
        'reverent' => ['g-lantern', 'calm', 'Calm, devotional reading', 'قراءة هادئة وخاشعة'],
    ];

    /**
     * Icons for the tags written inside the text: sounds (<laugh>) and
     * delivery ([excited]), by their English key.
     *
     * @var array<string, string>
     */
    public const TAG_ICONS = [
        'laugh' => 'f-laugh', 'chuckle' => 'f-chuckle', 'giggle' => 'f-giggle', 'cackle' => 'f-cackle', 'snicker' => 'f-sarcastic',
        'sigh' => 'f-sigh', 'gasp' => 'f-gasp', 'breath' => 'g-breath', 'heavy breath' => 'g-breath-heavy', 'exhales' => 'g-exhale',
        'pant' => 'f-pant', 'phew' => 'f-relieved',
        'short pause' => 'g-pause', 'long pause' => 'g-hourglass',
        'whispers' => 'f-shh', 'shout' => 'f-shout', 'scream' => 'f-scream', 'shriek' => 'f-shriek', 'cheer' => 'f-cheer',
        'cough' => 'f-cough', 'sneeze' => 'f-sneeze', 'throat-clearing' => 'f-throat', 'yawn' => 'f-yawn', 'snort' => 'f-snort',
        'tsk' => 'f-tsk', 'pff' => 'f-pff',
        'cry' => 'f-cry', 'sob' => 'f-sob', 'whimper' => 'f-whimper', 'groan' => 'f-groan', 'moan' => 'f-moan',
        'growl' => 'f-growl', 'grunt' => 'f-grunt', 'grr' => 'f-angry', 'hiss' => 'f-hiss', 'argh' => 'f-frustrated',
        'hum' => 'g-note', 'whistle' => 'f-whistle', 'sniff' => 'f-sniff', 'hiccup' => 'f-hiccup', 'gulp' => 'f-gulp', 'snore' => 'f-snore',
        'burp' => 'f-burp', 'clap' => 'g-clap', 'kiss' => 'f-kiss', 'hmm' => 'f-thinking', 'uh-huh' => 'f-agree', 'um' => 'f-hesitant',

        'happy' => 'f-grin', 'excited' => 'f-starstruck', 'warm' => 'f-smile', 'calm' => 'f-calm', 'serious' => 'f-serious',
        'sad' => 'f-sad', 'angry' => 'f-angry', 'nervous' => 'f-anxious', 'scared' => 'f-fear', 'surprised' => 'f-surprised',
        'curious' => 'f-curious', 'sarcastic' => 'f-sarcastic', 'playful' => 'f-playful', 'confident' => 'f-cool', 'tired' => 'f-sleepy',
        'shy' => 'f-shy', 'proud' => 'f-proud', 'disappointed' => 'f-disappointed', 'relieved' => 'f-relieved', 'disgusted' => 'f-disgusted',
        'bored' => 'f-bored', 'worried' => 'f-worried', 'loving' => 'f-love', 'mocking' => 'f-mocking', 'amazed' => 'f-amazed',
        'panicked' => 'f-panic', 'tearful' => 'f-cry', 'laughing' => 'f-laugh',
        'slowly' => 'g-slow', 'quickly' => 'g-fast', 'softly' => 'g-feather', 'loudly' => 'g-volume', 'whispering' => 'f-shh',
        'emphatic' => 'g-emphasis', 'singing' => 'g-note', 'breathless' => 'g-breath-heavy', 'monotone' => 'g-monotone',
        'cheerful' => 'f-cheerful', 'triumphant' => 'f-triumph', 'innocent' => 'f-innocent', 'lonely' => 'f-lonely', 'guilty' => 'f-guilty',
        'defiant' => 'f-defiant', 'furious' => 'f-furious', 'stuttering' => 'f-hesitant', 'trembling' => 'f-fear', 'robotic' => 'g-bot',
        'deep voice' => 'g-low', 'high voice' => 'g-high', 'child voice' => 'f-innocent', 'elderly voice' => 'f-elder',
        'storytelling' => 'g-feather', 'newsreader' => 'g-news', 'advertising' => 'g-tv', 'conversational' => 'g-chat',
        'dramatic' => 'g-masks', 'motivational' => 'g-flame', 'documentary' => 'g-globe',
    ];

    /**
     * Everything the pickers need, in the page's language.
     *
     * @return array<string, mixed>
     */
    public static function forPicker(bool $arabic): array
    {
        $pack = function (array $rows) use ($arabic): array {
            $out = [];
            foreach ($rows as $value => [$icon, $group, $en, $ar]) {
                $out[$value] = ['i' => $icon, 'g' => $group, 'd' => $arabic ? $ar : $en];
            }

            return $out;
        };
        $labels = fn (array $groups): array => array_map(fn (array $names) => $names[$arabic ? 'ar' : 'en'], $groups);

        $dialects = [];
        foreach (ArabicDialects::ALL as $code => $row) {
            $dialects[$code] = [
                'c' => ArabicDialects::countryOf($code),
                'r' => $row['region'],
                'f' => VoiceFlag::url($code),
                'd' => ArabicDialects::about($code, $arabic),
            ];
        }
        foreach (EnglishAccents::ALL as $code => $row) {
            $dialects[$code] = [
                'c' => $row['country'],
                'r' => VoiceFlag::region($code),
                'f' => VoiceFlag::url($code, $row['country']),
                'd' => EnglishAccents::about($code, $arabic),
            ];
        }

        $countries = [];
        foreach (ArabicDialects::COUNTRIES + EnglishAccents::COUNTRIES as $code => $names) {
            $countries[$code] = $names[$arabic ? 'ar' : 'en'];
        }
        $regions = [];
        foreach (['fusha', 'gulf', 'levant', 'nile', 'iraq_yemen', 'maghreb', 'americas', 'uk_ie', 'africa', 'asia_pacific', 'other'] as $region) {
            $regions[$region] = __('ui.landing.region_'.$region);
        }

        return [
            'dialects' => $dialects,
            'countries' => $countries,
            'regions' => $regions,
            'emotion' => $pack(self::EMOTIONS),
            'emotionGroups' => $labels(self::EMOTION_GROUPS),
            'performance_style' => $pack(self::STYLES),
            'performance_styleGroups' => $labels(self::STYLE_GROUPS),
            'tags' => self::TAG_ICONS,
            'audio_effect' => AudioEffects::forPicker($arabic),
            'audio_effectGroups' => $labels(AudioEffects::GROUPS),
        ];
    }
}
