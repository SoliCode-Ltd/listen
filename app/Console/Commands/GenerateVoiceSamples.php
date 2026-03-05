<?php

namespace App\Console\Commands;

use App\Contracts\TtsProviderInterface;
use App\Models\VoiceSample;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use Illuminate\Console\Command;

class GenerateVoiceSamples extends Command
{
    protected $signature = 'tts:generate-samples
        {--force : Overwrite existing samples}
        {--provider=gemini : gemini | google-cloud | auto}';

    protected $description = 'Generate multilingual showcase samples with distinct voices';

    /**
     * Master sample definitions — 26 Arabic + 12 English = 38 total.
     *
     * Arabic order: 7 MSA (فصحى) first for best quality, then 19 dialects.
     * Homepage shows top-12 per language by sort_order.
     */
    public const SAMPLES = [

        // ═══════════════════════════════════════════════════════════
        // ███  ARABIC — Modern Standard Arabic / فصحى  (7)  ███
        // ═══════════════════════════════════════════════════════════

        [
            'title'          => 'نشرة أخبار رسمية',
            'title_ar'       => 'نشرة أخبار رسمية',
            'description'    => 'Modern Standard Arabic — news bulletin',
            'description_ar' => 'عربية فصحى — نشرة أخبار',
            'icon'           => '📺',
            'language'       => 'ar',
            'text'           => 'خبر عاجل. أعلنت القمة العالمية للتقنية عن أول اتفاقية ملزمة لتنظيم الذكاء الاصطناعي، وقّعتها ثلاثون دولة في جنيف. مراسلنا ينقل لنا التفاصيل. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'خبر عاجل. أعلنت القمة العالمية للتقنية عن أول اتفاقية ملزمة لتنظيم الذكاء الاصطناعي، وقّعتها ثلاثون دولة في جنيف. مراسلنا ينقل لنا التفاصيل. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Orus',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'news',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are an urgent breaking-news anchor on live TV. Deliver with sharp, commanding authority and rapid intensity. Deep voice, zero warmth — pure broadcast professionalism and urgency. Punch each headline hard.',
        ],
        [
            'title'          => 'رواية صوتية عربية',
            'title_ar'       => 'رواية صوتية عربية',
            'description'    => 'Modern Standard Arabic — audiobook narration',
            'description_ar' => 'عربية فصحى — سرد رواية صوتية',
            'icon'           => '📖',
            'language'       => 'ar',
            'text'           => 'فتحت الباب ببطء. الظلام كان يلتهم كل شيء. سمعت خطوات خلفها، فتوقّف قلبها عن النبض للحظة. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'فتحت الباب ببطء. الظلام كان يلتهم كل شيء. سمعت خطوات خلفها، فتوقّف قلبها عن النبض للحظة. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Leda',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are narrating a suspenseful thriller audiobook. Whisper with dark tension and dread. Haunting, eerie atmosphere. Let terrifying silence hang between phrases. Build fear with every word.',
        ],
        [
            'title'          => 'وثائقي الحضارة العربية',
            'title_ar'       => 'وثائقي الحضارة العربية',
            'description'    => 'Modern Standard Arabic — civilization documentary',
            'description_ar' => 'عربية فصحى — وثائقي حضارة عربية',
            'icon'           => '🎬',
            'language'       => 'ar',
            'text'           => 'قبل ألف عام، كان بيت الحكمة في بغداد يُضيء العالم بالعلم. من هنا انطلق الخوارزمي وابن الهيثم ليُغيّروا مسار التاريخ. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'قبل ألف عام، كان بيت الحكمة في بغداد يُضيء العالم بالعلم. من هنا انطلق الخوارزمي وابن الهيثم ليُغيّروا مسار التاريخ. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Fenrir',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'documentary',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are narrating an EPIC cinematic documentary. Speak with deep, thunderous grandeur and sweeping majesty. Extremely slow, dramatic delivery. Let every word resonate like a historical revelation shaking the earth.',
        ],
        [
            'title'          => 'درس تعليمي - الذكاء الاصطناعي',
            'title_ar'       => 'درس تعليمي - الذكاء الاصطناعي',
            'description'    => 'Modern Standard Arabic — AI e-learning',
            'description_ar' => 'عربية فصحى — درس تعليمي عن الذكاء الاصطناعي',
            'icon'           => '🎓',
            'language'       => 'ar',
            'text'           => 'مرحباً بكم! اليوم سنتعلّم كيف تعمل الشبكات العصبية العميقة. الموضوع مذهل ومليء بالمفاجآت! جاهزون؟ لنبدأ! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'مرحباً بكم! اليوم سنتعلّم كيف تعمل الشبكات العصبية العميقة. الموضوع مذهل ومليء بالمفاجآت! جاهزون؟ لنبدأ! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Umbriel',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'educational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are a hyper-enthusiastic young teacher BURSTING with excitement. Speak fast and energetically with infectious passion. Raise your voice on key words. Make learning feel like the most thrilling adventure ever.',
        ],
        [
            'title'          => 'قصة أطفال سحرية',
            'title_ar'       => 'قصة أطفال سحرية',
            'description'    => 'Modern Standard Arabic — magical children\'s story',
            'description_ar' => 'عربية فصحى — قصة أطفال سحرية',
            'icon'           => '🧸',
            'language'       => 'ar',
            'text'           => 'في غابة سحرية، كان أرنوب الصغير يبحث عن كنز مخفي. فجأة ظهرت فراشة ذهبية وقالت: اتبعني يا أرنوب! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'في غابة سحرية، كان أرنوب الصغير يبحث عن كنز مخفي. فجأة ظهرت فراشة ذهبية وقالت: اتبعني يا أرنوب! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Aoede',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are a magical animated children\'s storyteller. Use silly distinct character voices — a squeaky rabbit and a sparkly butterfly. Giggle, gasp, and whisper. Pure wonder and fairy-tale enchantment.',
        ],
        [
            'title'          => 'خطاب تحفيزي',
            'title_ar'       => 'خطاب تحفيزي',
            'description'    => 'Modern Standard Arabic — motivational speech',
            'description_ar' => 'عربية فصحى — خطاب تحفيزي ملهم',
            'icon'           => '💡',
            'language'       => 'ar',
            'text'           => 'لا تستسلم! كل عظيم في التاريخ سقط مرّات قبل أن ينهض. قوّتك الحقيقية تظهر وقت الشدائد. اليوم هو يومك، انهض! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'لا تستسلم! كل عظيم في التاريخ سقط مرّات قبل أن ينهض. قوّتك الحقيقية تظهر وقت الشدائد. اليوم هو يومك، انهض! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Kore',
            'google_voice'   => 'ar-XA-Wavenet-D',
            'emotion'        => 'inspirational',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => true,
            'style_prompt'   => 'You are a FIERY motivational speaker at a packed stadium. Start intense and build to a SHOUTING crescendo. Voice cracking with raw passion. Commanding, relentless, like addressing an army before battle. SCREAM the final line.',
        ],
        [
            'title'          => 'مقدّمة بودكاست عربي',
            'title_ar'       => 'مقدّمة بودكاست عربي',
            'description'    => 'Modern Standard Arabic — podcast intro',
            'description_ar' => 'عربية فصحى — مقدّمة بودكاست',
            'icon'           => '🎙️',
            'language'       => 'ar',
            'text'           => 'أهلاً وسهلاً بحلقة جديدة! اليوم عندي ضيف رهيب وقصة ما رح تصدّقوها. جهّزوا قهوتكم وابقوا معنا! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'أهلاً وسهلاً بحلقة جديدة! اليوم عندي ضيف رهيب وقصة ما رح تصدّقوها. جهّزوا قهوتكم وابقوا معنا! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Charon',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'podcast',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => null,
            'country'        => null,
            'is_featured'    => false,
            'style_prompt'   => 'You are a witty, sarcastic podcast host with a mischievous grin. Natural, conversational, playfully teasing the audience. Quick pace with comedic timing and cheeky pauses. Build intrigue like sharing a juicy secret.',
        ],

        // ═══════════════════════════════════════════════════════════
        // ███  ARABIC — Dialects / لهجات  (19)  ███
        // ═══════════════════════════════════════════════════════════

        // ── Saudi Arabia 🇸🇦 ──
        [
            'title'          => 'إعلان موشن جرافيك سعودي',
            'title_ar'       => 'إعلان موشن جرافيك سعودي',
            'description'    => 'Saudi dialect — motion graphics ad',
            'description_ar' => 'لهجة سعودية — إعلان موشن جرافيك',
            'icon'           => '🇸🇦',
            'language'       => 'ar',
            'text'           => 'الحين الحين! حمّل التطبيق واحصل على خصم خمسين بالمية! العرض ينتهي بكرة! وش تنتظر يا بطل؟ هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'الحين الحين! حمّل التطبيق واحصل على خصم خمسين بالمية! العرض ينتهي بكرة! وش تنتظر يا بطل؟ هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Puck',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'commercial',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-SA',
            'country'        => 'SA',
            'is_featured'    => true,
            'style_prompt'   => 'You are SCREAMING a Saudi flash-sale ad. SHOUT with wild, explosive energy! Rush through words like the deal is about to expire in seconds. Maximum hype, maximum urgency, maximum volume. Pure adrenaline Saudi commercial.',
        ],
        [
            'title'          => 'إعلان ترويجي بصوت طفولي',
            'title_ar'       => 'إعلان ترويجي بصوت طفولي',
            'description'    => 'Saudi dialect — playful childlike ad',
            'description_ar' => 'لهجة سعودية — إعلان بأداء طفولي',
            'icon'           => '🇸🇦',
            'language'       => 'ar',
            'text'           => 'ماما ماما! شوفي الآيسكريم الجديد! فيه شوكولاتة وفراولة! يممم ما أطيبه! تعالوا يا أصحابي كلكم! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'ماما ماما! شوفي الآيسكريم الجديد! فيه شوكولاتة وفراولة! يممم ما أطيبه! تعالوا يا أصحابي كلكم! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Callirrhoe',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'conversational',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-SA',
            'country'        => 'SA',
            'is_featured'    => false,
            'style_prompt'   => 'You are a tiny excited Saudi child SQUEALING about ice cream. Very high-pitched, bubbly, almost breathless with excitement. Giggle and squeal between sentences. Pure childlike joy overflowing with wonder.',
        ],

        // ── Egypt 🇪🇬 ──
        [
            'title'          => 'إعلان ورشة عمل مصري',
            'title_ar'       => 'إعلان ورشة عمل مصري',
            'description'    => 'Egyptian dialect — workshop promo',
            'description_ar' => 'لهجة مصرية — إعلان ورشة عمل',
            'icon'           => '🇪🇬',
            'language'       => 'ar',
            'text'           => 'عايز تبقى محترف عروض تقديمية؟ يبقى لازم تيجي الورشة دي! هتتعلّم تقنع أي حد وتبهر الكل. سجّل دلوقتي! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'عايز تبقى محترف عروض تقديمية؟ يبقى لازم تيجي الورشة دي! هتتعلّم تقنع أي حد وتبهر الكل. سجّل دلوقتي! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Algieba',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'promo',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-EG',
            'country'        => 'EG',
            'is_featured'    => true,
            'style_prompt'   => 'You are a fast-talking Egyptian street-smart salesman. Speak with witty Egyptian humor and punchy Cairo slang. Confidence bordering on cocky. Sharp, rapid-fire rhythm. Utterly persuasive with a smirk.',
        ],
        [
            'title'          => 'برنامج طبخ مصري',
            'title_ar'       => 'برنامج طبخ مصري',
            'description'    => 'Egyptian dialect — cooking show',
            'description_ar' => 'لهجة مصرية — برنامج طبخ',
            'icon'           => '🇪🇬',
            'language'       => 'ar',
            'text'           => 'يا أهلاً بيكم يا حلوين! النهارده هنعمل كشري مصري أصلي! الريحة بس هتجوّعكم! يلّا بينا نبدأ يا حبايبي! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'يا أهلاً بيكم يا حلوين! النهارده هنعمل كشري مصري أصلي! الريحة بس هتجوّعكم! يلّا بينا نبدأ يا حبايبي! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Erinome',
            'google_voice'   => 'ar-XA-Wavenet-D',
            'emotion'        => 'conversational',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-EG',
            'country'        => 'EG',
            'is_featured'    => false,
            'style_prompt'   => 'You are a LOUD, warm Egyptian mama on a cooking show. LAUGH between sentences with big, hearty laughter. Overflowing motherly love and warmth. Natural loud Cairo dialect. Make listeners feel like they are in your kitchen being hugged.',
        ],

        // ── UAE 🇦🇪 ──
        [
            'title'          => 'إعلان تجميل إماراتي',
            'title_ar'       => 'إعلان تجميل إماراتي',
            'description'    => 'Emirati dialect — luxury beauty ad',
            'description_ar' => 'لهجة إماراتية — إعلان تجميل فاخر',
            'icon'           => '🇦🇪',
            'language'       => 'ar',
            'text'           => 'بشرتج تستاهل الأفضل. كريم النضارة الجديد بخلاصة الورد الطائفي. نعومة وإشراقة من أوّل استخدام. لأنّج تستاهلين. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'بشرتج تستاهل الأفضل. كريم النضارة الجديد بخلاصة الورد الطائفي. نعومة وإشراقة من أوّل استخدام. لأنّج تستاهلين. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Autonoe',
            'google_voice'   => 'ar-XA-Wavenet-D',
            'emotion'        => 'commercial',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-AE',
            'country'        => 'AE',
            'is_featured'    => true,
            'style_prompt'   => 'You are recording an ultra-luxurious Emirati beauty ASMR ad. WHISPER seductively with silky, breathy delivery. Every word drips with elegance. Extremely slow, intimate, like sharing a precious beauty secret. Sultry and mesmerizing.',
        ],

        // ── Lebanon 🇱🇧 ──
        [
            'title'          => 'برنامج إذاعي لبناني',
            'title_ar'       => 'برنامج إذاعي لبناني',
            'description'    => 'Lebanese dialect — radio show host',
            'description_ar' => 'لهجة لبنانية — مقدّم برنامج إذاعي',
            'icon'           => '🇱🇧',
            'language'       => 'ar',
            'text'           => 'أهلاً فيكن يا جماعة! حلقة اليوم عن أسرار الطبخ اللبناني حول العالم. معنا شيف رح يكشفلنا الوصفة السرية! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'أهلاً فيكن يا جماعة! حلقة اليوم عن أسرار الطبخ اللبناني حول العالم. معنا شيف رح يكشفلنا الوصفة السرية! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Gacrux',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'podcast',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-LB',
            'country'        => 'LB',
            'is_featured'    => false,
            'style_prompt'   => 'You are a charismatic, flirtatious Lebanese radio host. Melodic Lebanese intonation. Sound like you are constantly smiling. Charming, smooth, with musical rhythm in every phrase. Irresistibly likeable.',
        ],

        // ── Jordan 🇯🇴 ──
        [
            'title'          => 'بودكاست قصة نجاح أردني',
            'title_ar'       => 'بودكاست قصة نجاح أردني',
            'description'    => 'Jordanian dialect — startup podcast',
            'description_ar' => 'لهجة أردنية — بودكاست ريادة أعمال',
            'icon'           => '🇯🇴',
            'language'       => 'ar',
            'text'           => 'اليوم رح نحكيلكم قصة شاب أردني بلّش من غرفته وصار عنده شركة بتصدّر لعشرين دولة. القصة مش عادية يا شباب! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'اليوم رح نحكيلكم قصة شاب أردني بلّش من غرفته وصار عنده شركة بتصدّر لعشرين دولة. القصة مش عادية يا شباب! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Iapetus',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'podcast',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-JO',
            'country'        => 'JO',
            'is_featured'    => false,
            'style_prompt'   => 'You are a genuine, down-to-earth Jordanian storyteller. Authentic Ammani accent. Sound like telling a friend an incredible story over coffee. Earnest, raw, unpolished warmth with wide-eyed excitement.',
        ],

        // ── Palestine 🇵🇸 ──
        [
            'title'          => 'حكاية فلسطينية',
            'title_ar'       => 'حكاية فلسطينية',
            'description'    => 'Palestinian dialect — storytelling',
            'description_ar' => 'لهجة فلسطينية — سرد قصصي',
            'icon'           => '🇵🇸',
            'language'       => 'ar',
            'text'           => 'ستّي كانت تحكيلنا عن الدار القديمة وشجرة الزيتون. كل ورقة فيها بتحكي حكاية. كنّا نسمع والهوا يجيب ريحة الزعتر. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'ستّي كانت تحكيلنا عن الدار القديمة وشجرة الزيتون. كل ورقة فيها بتحكي حكاية. كنّا نسمع والهوا يجيب ريحة الزعتر. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Rasalgethi',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-PS',
            'country'        => 'PS',
            'is_featured'    => false,
            'style_prompt'   => 'You are a Palestinian grandmother with tears in her voice. Deeply emotional, raw nostalgia. Voice trembling with memory and longing. Each word carries the weight of homeland. Heartbreaking tenderness that makes listeners cry.',
        ],

        // ── Iraq 🇮🇶 ──
        [
            'title'          => 'قصيدة عراقية',
            'title_ar'       => 'قصيدة عراقية',
            'description'    => 'Iraqi dialect — poetry recitation',
            'description_ar' => 'لهجة عراقية — إلقاء شعري',
            'icon'           => '🇮🇶',
            'language'       => 'ar',
            'text'           => 'يا بغداد يا أمّ الحضارات! شمسج ما تغيب وناسج طيبين. مهما بعدنا عنّج، قلوبنا عندج أبد. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'يا بغداد يا أمّ الحضارات! شمسج ما تغيب وناسج طيبين. مهما بعدنا عنّج، قلوبنا عندج أبد. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Enceladus',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'narrative',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-IQ',
            'country'        => 'IQ',
            'is_featured'    => false,
            'style_prompt'   => 'You are an Iraqi man passionately DECLAIMING poetry about Baghdad. Deep voice trembling with fierce pride and aching nostalgia. Dramatic pauses. Almost SINGING the words. Raw, powerful emotion erupting from the gut.',
        ],

        // ── Syria 🇸🇾 ──
        [
            'title'          => 'دراما تاريخية سورية',
            'title_ar'       => 'دراما تاريخية سورية',
            'description'    => 'Syrian dialect — historical drama narration',
            'description_ar' => 'لهجة سورية — سرد دراما تاريخية',
            'icon'           => '🇸🇾',
            'language'       => 'ar',
            'text'           => 'الشام يا حبيبتي، شوارعها بتحكي تاريخ آلاف السنين. كل زاوية فيها عبق الياسمين. يا شام، يا أجمل ذكرى بالقلب. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'الشام يا حبيبتي، شوارعها بتحكي تاريخ آلاف السنين. كل زاوية فيها عبق الياسمين. يا شام، يا أجمل ذكرى بالقلب. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Schedar',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'cinematic',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-SY',
            'country'        => 'SY',
            'is_featured'    => false,
            'style_prompt'   => 'You are narrating the final scene of a deeply emotional Syrian drama. Damascene dialect dripping with sorrow and beauty. Sweeping, poetic delivery with aching longing. Let your voice nearly break with emotion.',
        ],

        // ── Morocco 🇲🇦 ──
        [
            'title'          => 'وثائقي الحضارة المغربية',
            'title_ar'       => 'وثائقي الحضارة المغربية',
            'description'    => 'Moroccan — documentary narration',
            'description_ar' => 'لهجة مغربية — سرد وثائقي',
            'icon'           => '🇲🇦',
            'language'       => 'ar',
            'text'           => 'من أزقة فاس لجبال الأطلس، المغرب أرض التنوع والجمال. الصنعة التقليدية والموسيقى الأندلسية وعبق التوابل في كل سوق. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'من أزقة فاس لجبال الأطلس، المغرب أرض التنوع والجمال. الصنعة التقليدية والموسيقى الأندلسية وعبق التوابل في كل سوق. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Despina',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'documentary',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-MA',
            'country'        => 'MA',
            'is_featured'    => false,
            'style_prompt'   => 'You are a vibrant, colorful Moroccan documentary narrator. Rich voice BURSTING with cultural pride. Paint vivid pictures with every word. Rhythmic, warm, with North African musical cadence and infectious energy.',
        ],

        // ── Kuwait 🇰🇼 ──
        [
            'title'          => 'إعلان برنامج مكافآت كويتي',
            'title_ar'       => 'إعلان برنامج مكافآت كويتي',
            'description'    => 'Kuwaiti dialect — loyalty rewards ad',
            'description_ar' => 'لهجة كويتية — إعلان برنامج مكافآت',
            'icon'           => '🇰🇼',
            'language'       => 'ar',
            'text'           => 'مع برنامج نجوم المكافآت، كل عملية شراء تقرّبك من جوائز حصرية. سجّل الحين واحصل على ألف نقطة هدية! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'مع برنامج نجوم المكافآت، كل عملية شراء تقرّبك من جوائز حصرية. سجّل الحين واحصل على ألف نقطة هدية! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Achird',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'promo',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-KW',
            'country'        => 'KW',
            'is_featured'    => false,
            'style_prompt'   => 'You are a smooth-talking Kuwaiti luxury salesman. Silky Gulf Arabic with confident, honey-toned delivery. Effortlessly persuasive like selling a luxury car. Refined charm oozing from every syllable.',
        ],

        // ── Bahrain 🇧🇭 ──
        [
            'title'          => 'إعلان مصرفي بحريني',
            'title_ar'       => 'إعلان مصرفي بحريني',
            'description'    => 'Bahraini dialect — banking ad',
            'description_ar' => 'لهجة بحرينية — إعلان خدمات بنكية',
            'icon'           => '🇧🇭',
            'language'       => 'ar',
            'text'           => 'مع بنك الخليج المتحد، حساباتك بأمان وأرباحك بتنمو. خدمات رقمية متطوّرة تناسب طموحاتك. افتح حسابك اليوم! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'مع بنك الخليج المتحد، حساباتك بأمان وأرباحك بتنمو. خدمات رقمية متطوّرة تناسب طموحاتك. افتح حسابك اليوم! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Algenib',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'corporate',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-BH',
            'country'        => 'BH',
            'is_featured'    => false,
            'style_prompt'   => 'You are a stern, authoritative Gulf banking executive. Formal, no-nonsense delivery. Deep, commanding tone that inspires absolute trust. Corporate gravitas with zero warmth. Each word lands like a gavel.',
        ],

        // ── Oman 🇴🇲 ──
        [
            'title'          => 'وثائقي طبيعة عمانية',
            'title_ar'       => 'وثائقي طبيعة عمانية',
            'description'    => 'Omani — nature documentary',
            'description_ar' => 'لهجة عمانية — وثائقي طبيعة',
            'icon'           => '🇴🇲',
            'language'       => 'ar',
            'text'           => 'من رمال الشرقية الذهبية إلى الجبل الأخضر، عُمان أرض تجمع بين البحر والصحراء. هنا حيث الهدوء يصبح لغة. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'من رمال الشرقية الذهبية إلى الجبل الأخضر، عُمان أرض تجمع بين البحر والصحراء. هنا حيث الهدوء يصبح لغة. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Pulcherrima',
            'google_voice'   => 'ar-XA-Wavenet-D',
            'emotion'        => 'documentary',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-OM',
            'country'        => 'OM',
            'is_featured'    => false,
            'style_prompt'   => 'You are whispering a sacred nature meditation about Oman. Ultra-slow, barely audible, as if afraid to disturb the breathtaking beauty around you. Zen-like stillness. Let long peaceful silences fill the gaps between phrases.',
        ],

        // ── Qatar 🇶🇦 ──
        [
            'title'          => 'دليل سياحي قطري',
            'title_ar'       => 'دليل سياحي قطري',
            'description'    => 'Qatari — tourism guide',
            'description_ar' => 'لهجة قطرية — دليل سياحي',
            'icon'           => '🇶🇦',
            'language'       => 'ar',
            'text'           => 'قطر تجمع بين أصالة الماضي وروعة الحاضر. من سوق واقف لناطحات السحاب، تجربة لا تُنسى. تعال وعيش التميّز. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'قطر تجمع بين أصالة الماضي وروعة الحاضر. من سوق واقف لناطحات السحاب، تجربة لا تُنسى. تعال وعيش التميّز. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Sadachbia',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'inspirational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-QA',
            'country'        => 'QA',
            'is_featured'    => false,
            'style_prompt'   => 'You are narrating a grand, premium Qatar tourism film. Speak with regal authority and immense pride. Every word polished like marble. Luxurious, slow pace, as if unveiling a priceless masterpiece to the world.',
        ],

        // ── Sudan 🇸🇩 ──
        [
            'title'          => 'حكاية سودانية',
            'title_ar'       => 'حكاية سودانية',
            'description'    => 'Sudanese — cultural storytelling',
            'description_ar' => 'لهجة سودانية — حكاية شعبية',
            'icon'           => '🇸🇩',
            'language'       => 'ar',
            'text'           => 'في قلب السودان عند ملتقى النيلين، العائلة تجتمع تحت النجوم تحكي حكاوي. ريحة البخور والقهوة تملا المكان. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'في قلب السودان عند ملتقى النيلين، العائلة تجتمع تحت النجوم تحكي حكاوي. ريحة البخور والقهوة تملا المكان. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Laomedeia',
            'google_voice'   => 'ar-XA-Wavenet-A',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-SD',
            'country'        => 'SD',
            'is_featured'    => false,
            'style_prompt'   => 'You are a warm Sudanese storyteller gathering family around a fire. Gentle LAUGHTER between phrases. Rich, communal warmth that wraps around the listener. Sharing a precious family memory with a contagious smile.',
        ],

        // ── Tunisia 🇹🇳 ──
        [
            'title'          => 'درس تعليمي تونسي',
            'title_ar'       => 'درس تعليمي تونسي',
            'description'    => 'Tunisian — educational lesson',
            'description_ar' => 'لهجة تونسية — درس تعليمي',
            'icon'           => '🇹🇳',
            'language'       => 'ar',
            'text'           => 'تونس بلاد التاريخ العريق. من قرطاج الخالدة لجامع الزيتونة، كل حجر يحكي قصة حضارة عظيمة امتدّت لآلاف السنين. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'تونس بلاد التاريخ العريق. من قرطاج الخالدة لجامع الزيتونة، كل حجر يحكي قصة حضارة عظيمة امتدّت لآلاف السنين. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Alnilam',
            'google_voice'   => 'ar-XA-Wavenet-C',
            'emotion'        => 'educational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-TN',
            'country'        => 'TN',
            'is_featured'    => false,
            'style_prompt'   => 'You are a sharp, intellectual North African professor. Precise articulation with academic authority. Passionate about history. Fast-paced and driven with zero wasted words. Scholarly fire and intensity.',
        ],

        // ── Yemen 🇾🇪 ──
        [
            'title'          => 'مساعد ذكي يمني',
            'title_ar'       => 'مساعد ذكي يمني',
            'description'    => 'Yemeni — AI assistant voice',
            'description_ar' => 'لهجة يمنية — صوت مساعد ذكي',
            'icon'           => '🇾🇪',
            'language'       => 'ar',
            'text'           => 'أهلاً وسهلاً فيك! أنا مساعدتك الذكية. تبي تحجز رحلة أو تدوّر على مطعم؟ خلّني أسهّل حياتك! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'أهلاً وسهلاً فيك! أنا مساعدتك الذكية. تبي تحجز رحلة أو تدوّر على مطعم؟ خلّني أسهّل حياتك! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Vindemiatrix',
            'google_voice'   => 'ar-XA-Wavenet-D',
            'emotion'        => 'assistant',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'ar-YE',
            'country'        => 'YE',
            'is_featured'    => false,
            'style_prompt'   => 'You are an upbeat, cheerful AI assistant overflowing with eagerness. Bubbly and eager to help. Sound like you genuinely LOVE your job more than anything. Quick, friendly, with a permanent beaming smile in your voice.',
        ],

        // ── Libya 🇱🇾 ──
        [
            'title'          => 'إعلان سياحي ليبي',
            'title_ar'       => 'إعلان سياحي ليبي',
            'description'    => 'Libyan — tourism promotion',
            'description_ar' => 'لهجة ليبية — إعلان سياحي',
            'icon'           => '🇱🇾',
            'language'       => 'ar',
            'text'           => 'ليبيا أرض الكنوز المخفية. من لبدة الكبرى لصحراء أكاكوس، مناظر تسلب العقل وتاريخ يفوق الخيال! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'text_ar'        => 'ليبيا أرض الكنوز المخفية. من لبدة الكبرى لصحراء أكاكوس، مناظر تسلب العقل وتاريخ يفوق الخيال! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Sadaltager',
            'google_voice'   => 'ar-XA-Wavenet-B',
            'emotion'        => 'inspirational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'ar-LY',
            'country'        => 'LY',
            'is_featured'    => false,
            'style_prompt'   => 'You are an AMAZED, breathless Libyan narrator discovering treasures. Speak with explosive pride and wide-eyed wonder. Each landmark makes your voice SOAR higher with excitement. Like seeing these wonders for the very first time.',
        ],

        // ═══════════════════════════════════════════════════════════
        // ███  ENGLISH  (12)  ███
        // ═══════════════════════════════════════════════════════════

        [
            'title'          => 'SaaS Product Launch',
            'title_ar'       => 'إطلاق منتج تقني',
            'description'    => 'American English — tech startup ad',
            'description_ar' => 'إنجليزية أمريكية — إعلان شركة تقنية',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => "Say goodbye to scattered workflows. FlowSync brings your team's tasks and deadlines into one beautiful space. Start your free trial today. This voice was generated by AI through the Listen platform.",
            'text_ar'        => 'ودّع الفوضى. فلوسينك يجمع مهام فريقك ومواعيدهم في مكان أنيق. ابدأ تجربتك المجانية اليوم. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Achird',
            'google_voice'   => 'en-US-Wavenet-D',
            'emotion'        => 'commercial',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => true,
            'style_prompt'   => 'You are a slick Silicon Valley tech commercial voice. Supremely confident, modern, crisp. Punchy delivery with strategic pauses. Think Apple ad — cool, clean, effortlessly persuasive. No warmth, just pure polished cool.',
        ],
        [
            'title'          => 'Luxury Brand Story',
            'title_ar'       => 'قصة علامة فاخرة',
            'description'    => 'British English — high-end fashion',
            'description_ar' => 'إنجليزية بريطانية — أزياء راقية',
            'icon'           => '🇬🇧',
            'language'       => 'en',
            'text'           => 'For over a century, Ellsworth has curated timeless elegance. Each piece tells a story of heritage and quiet confidence. This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'لأكثر من قرن، صاغت إلسورث أناقة خالدة. كل قطعة تروي قصة تراث وثقة هادئة. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Callirrhoe',
            'google_voice'   => 'en-US-Wavenet-C',
            'emotion'        => 'corporate',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'en-GB',
            'country'        => 'GB',
            'is_featured'    => true,
            'style_prompt'   => 'You are whispering a breathless luxury fashion film narration. British RP accent barely above a whisper. Every word is precious. Ultra-slow, intimate, like revealing a hidden masterpiece. Dripping with sophistication and mystique.',
        ],
        [
            'title'          => 'E-Learning Course',
            'title_ar'       => 'دورة تعليمية',
            'description'    => 'American English — data science class',
            'description_ar' => 'إنجليزية أمريكية — درس علم البيانات',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => "Welcome to Module Three. Today we explore neural networks — how they learn and make decisions. By the end, you'll build your first model. This voice was generated by AI through the Listen platform.",
            'text_ar'        => 'أهلاً بالوحدة الثالثة. اليوم نستكشف الشبكات العصبية — كيف تتعلم وتتخذ القرارات. بنهاية الدرس ستبني نموذجك الأول. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Umbriel',
            'google_voice'   => 'en-US-Wavenet-B',
            'emotion'        => 'educational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => true,
            'style_prompt'   => 'You are a clear, engaging American professor. Steady, measured delivery with genuine intellectual curiosity. Professional yet approachable. Emphasize technical terms. Make complex topics feel exciting and accessible.',
        ],
        [
            'title'          => 'True Crime Podcast',
            'title_ar'       => 'بودكاست جرائم حقيقية',
            'description'    => 'American English — mystery episode',
            'description_ar' => 'إنجليزية أمريكية — حلقة غامضة',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => 'The house had been empty for years. But on October twelfth, a neighbor saw a light in the attic. What happened next changed everything. This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'المنزل كان فارغاً لسنوات. لكن في ليلة أكتوبر، رأى جارٌ ضوءاً في العلية. ما حدث بعدها غيّر كل شيء. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Despina',
            'google_voice'   => 'en-US-Wavenet-F',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are delivering a SPINE-CHILLING true crime whisper. Ultra-low, barely audible female voice. Build unbearable tension word by word. Long, creepy pauses. Your whisper should make listeners look over their shoulder in fear.',
        ],
        [
            'title'          => 'Corporate Training',
            'title_ar'       => 'تدريب مؤسسي',
            'description'    => 'British English — leadership module',
            'description_ar' => 'إنجليزية بريطانية — تدريب قيادة',
            'icon'           => '🇬🇧',
            'language'       => 'en',
            'text'           => "Effective leaders don't just manage tasks — they inspire people. In this module, we examine the five pillars of transformational leadership. This voice was generated by AI through the Listen platform.",
            'text_ar'        => 'القادة الفعالون لا يديرون المهام فقط — بل يُلهمون. في هذه الوحدة نتناول الركائز الخمس للقيادة التحويلية. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Iapetus',
            'google_voice'   => 'en-US-Wavenet-D',
            'emotion'        => 'corporate',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-GB',
            'country'        => 'GB',
            'is_featured'    => false,
            'style_prompt'   => 'You are a stern, no-nonsense British executive addressing the board. Commanding RP accent with zero humor or warmth. Pure gravitas and authority. Each word lands like a gavel. Uncompromising professionalism.',
        ],
        [
            'title'          => 'Travel Documentary',
            'title_ar'       => 'وثائقي سفر',
            'description'    => 'Australian English — outback adventure',
            'description_ar' => 'إنجليزية أسترالية — مغامرة في البرية',
            'icon'           => '🇦🇺',
            'language'       => 'en',
            'text'           => 'Beyond the red dunes and ancient gorges, the Australian outback holds secrets older than time. Tonight, we journey into the heart of this extraordinary land. This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'خلف الكثبان الحمراء والأخاديد، يحتفظ قلب أستراليا بأسرار أقدم من الزمن. الليلة نسافر لعمق هذه الأرض. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Sadachbia',
            'google_voice'   => 'en-US-Wavenet-B',
            'emotion'        => 'documentary',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-AU',
            'country'        => 'AU',
            'is_featured'    => false,
            'style_prompt'   => 'You are an adventurous, wide-eyed Australian explorer. Natural Aussie accent BURSTING with wonder. Sound as if standing in the outback at a breathtaking sunset. Breathless excitement mixed with deep reverence for ancient nature.',
        ],
        [
            'title'          => 'Meditation Guide',
            'title_ar'       => 'دليل تأمل',
            'description'    => 'American English — calm wellness app',
            'description_ar' => 'إنجليزية أمريكية — تطبيق تأمل وعافية',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => 'Close your eyes. Breathe in deeply. Let it go. With each breath, feel the weight lifting. You are safe. You are enough. This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'أغمض عينيك. تنفّس بعمق. أطلق الزفير. مع كل نفس اشعر بالثقل يرتفع. أنت بأمان. أنت كافٍ. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Autonoe',
            'google_voice'   => 'en-US-Wavenet-C',
            'emotion'        => 'conversational',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are a calm, soothing meditation guide. Speak softly and gently with a peaceful, reassuring tone. Slow pace with natural pauses between sentences.',
        ],
        [
            'title'          => 'Breaking News Bulletin',
            'title_ar'       => 'نشرة أخبار عاجلة',
            'description'    => 'American English — news anchor',
            'description_ar' => 'إنجليزية أمريكية — مذيع أخبار',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => "Breaking news tonight. Global tech leaders reach a landmark AI safety agreement in Geneva — binding rules for the first time in the industry's history. This voice was generated by AI through the Listen platform.",
            'text_ar'        => 'خبر عاجل. توصّل قادة التقنية لاتفاق تاريخي حول سلامة الذكاء الاصطناعي في جنيف — قواعد ملزمة للمرة الأولى. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Gacrux',
            'google_voice'   => 'en-US-Wavenet-D',
            'emotion'        => 'corporate',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are a rapid-fire American TV news anchor on LIVE breaking news. Sharp, urgent, punchy. Zero softness. Crisp broadcast authority delivering critical information with maximum urgency. Snappy transitions with deep gravitas.',
        ],
        [
            'title'          => 'Children\'s Audiobook',
            'title_ar'       => 'كتاب صوتي للأطفال',
            'description'    => 'American English — magical adventure',
            'description_ar' => 'إنجليزية أمريكية — مغامرة سحرية',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => 'Lila the little fox tiptoed through the glowing forest. "Hello?" she whispered. A tiny door appeared in the oldest oak. The adventure was just beginning! This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'تسللت ليلى الثعلبة عبر الغابة المتوهجة. "مرحباً؟" همست. ظهر باب صغير في أقدم بلوطة. المغامرة بدأت للتو! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Pulcherrima',
            'google_voice'   => 'en-US-Wavenet-E',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are a magical, enchanting children\'s narrator. WHISPER for Lila\'s tiny voice, GASP with wonder at the door. Use different animated character voices. Wide-eyed giggles and pure fairy-tale magic. Make children lean in with excitement.',
        ],
        [
            'title'          => 'Fitness Coaching',
            'title_ar'       => 'تدريب لياقة',
            'description'    => 'American English — workout motivation',
            'description_ar' => 'إنجليزية أمريكية — تحفيز رياضي',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => "Three more reps! Push through the burn! That's where the magic happens! Every rep brings you closer to greatness. Come on, let's GO! This voice was generated by AI through the Listen platform.",
            'text_ar'        => 'ثلاث تكرارات! تحمّل الحرقة! هنا يحصل السحر! كل تكرار يقرّبك من العظمة. يلّا! هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Schedar',
            'google_voice'   => 'en-US-Wavenet-B',
            'emotion'        => 'inspirational',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are SCREAMING as a gym trainer in someone\'s ear during an intense workout. Full-blast motivational SHOUTING. Raw, aggressive, primal energy. Sound like you are physically doing the reps alongside the listener. Explosive, grunting intensity.',
        ],
        [
            'title'          => 'Science Documentary',
            'title_ar'       => 'وثائقي علمي',
            'description'    => 'British English — space documentary',
            'description_ar' => 'إنجليزية بريطانية — وثائقي فضاء',
            'icon'           => '🇬🇧',
            'language'       => 'en',
            'text'           => 'Billions of years ago, the first stars ignited in the darkness. Their light carried the elements that would one day form planets, oceans, and you. This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'منذ مليارات السنين، اشتعلت النجوم الأولى في الظلام. حمل ضوؤها العناصر التي ستشكّل الكواكب والمحيطات وأنت. هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Sadaltager',
            'google_voice'   => 'en-US-Wavenet-D',
            'emotion'        => 'cinematic',
            'gender'         => 'male',
            'category'       => 'hero',
            'dialect'        => 'en-GB',
            'country'        => 'GB',
            'is_featured'    => false,
            'style_prompt'   => 'You are Sir David Attenborough narrating an epic space documentary. Deep British accent with cosmic awe and wonder. Every sentence is a revelation. Extremely slow, majestic, with the weight of the entire universe in your voice. Goosebump-inducing gravitas.',
        ],
        [
            'title'          => 'Romantic Audiobook',
            'title_ar'       => 'رواية رومانسية صوتية',
            'description'    => 'American English — romance novel',
            'description_ar' => 'إنجليزية أمريكية — رواية رومانسية',
            'icon'           => '🇺🇸',
            'language'       => 'en',
            'text'           => 'She found the letter tucked in an old book. His handwriting filled the page. "I never stopped thinking about you," it began. "Not for a single day." This voice was generated by AI through the Listen platform.',
            'text_ar'        => 'وجدت الرسالة في كتاب قديم. ملأ خطّه الصفحة. "لم أتوقف عن التفكير فيكِ"، هكذا بدأت. "ولا ليومٍ واحد." هذا الصوت تم توليده بالذكاء الاصطناعى عن طريق منصة اسمع.',
            'gemini_voice'   => 'Leda',
            'google_voice'   => 'en-US-Wavenet-C',
            'emotion'        => 'narrative',
            'gender'         => 'female',
            'category'       => 'hero',
            'dialect'        => 'en-US',
            'country'        => 'US',
            'is_featured'    => false,
            'style_prompt'   => 'You are a deeply intimate romance audiobook narrator. Breathless, tender, with a catch in your voice. WHISPER the letter contents as if they are sacred. Let raw emotion crack through your composure. Heart-melting vulnerability.',
        ],
    ];

    public function handle(): int
    {
        $providerOption = strtolower(trim((string) $this->option('provider')));
        if (! in_array($providerOption, ['gemini', 'google-cloud', 'auto'], true)) {
            $this->error("Invalid --provider value '{$providerOption}'. Use: gemini | google-cloud | auto");
            return self::FAILURE;
        }

        $providers = [
            'gemini'       => app(GeminiTtsProvider::class),
            'google_cloud' => app(GoogleCloudTtsProvider::class),
        ];

        $force = (bool) $this->option('force');
        $bar   = $this->output->createProgressBar(count(self::SAMPLES));
        $bar->start();

        $created = 0;
        $skipped = 0;
        $failed  = 0;

        foreach (self::SAMPLES as $index => $sample) {
            $existing = VoiceSample::query()
                ->where('title', $sample['title'])
                ->where('category', $sample['category'])
                ->where('language', $sample['language'])
                ->first();

            if ($existing && ! $force) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $text          = $sample['language'] === 'ar' ? $sample['text_ar'] : $sample['text'];
            $providerOrder = $this->providerOrder($providerOption);

            $result       = null;
            $providerUsed = null;
            $lastError    = null;

            foreach ($providerOrder as $providerKey) {
                /** @var TtsProviderInterface|null $provider */
                $provider = $providers[$providerKey] ?? null;
                if (! $provider || ! $provider->isAvailable()) {
                    continue;
                }

                $voiceConfig = $this->buildVoiceConfig($sample, $providerKey);

                // Retry with exponential backoff for rate-limit (429) errors
                $maxRetries   = 5;
                $retryDelay   = 15; // seconds
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    try {
                        $result       = $provider->synthesize($text, $sample['language'], $voiceConfig);
                        $providerUsed = $provider->name();
                        break 2; // break both for loops
                    } catch (\Throwable $error) {
                        $lastError = $error;
                        // Retry only on 429 rate limit errors
                        if ($attempt < $maxRetries && str_contains($error->getMessage(), '429')) {
                            $this->newLine();
                            $this->warn("Rate limited on {$sample['title']}, waiting {$retryDelay}s (attempt {$attempt}/{$maxRetries})...");
                            sleep($retryDelay);
                            $retryDelay = min($retryDelay * 2, 120); // exponential backoff, max 2 min
                        } else {
                            break; // non-429 error or max retries reached
                        }
                    }
                }
            }

            if (! is_array($result) || ! is_string($providerUsed)) {
                $failed++;
                $message = $lastError ? $lastError->getMessage() : 'No available provider could synthesize this sample.';
                $this->newLine();
                $this->error("FAILED {$sample['title']} ({$sample['language']}): {$message}");
                $bar->advance();
                continue;
            }

            // Keep sample metadata on Google voice IDs for stable labels/UI grouping.
            $voiceName = $sample['google_voice'];

            VoiceSample::updateOrCreate(
                [
                    'title'    => $sample['title'],
                    'category' => $sample['category'],
                    'language' => $sample['language'],
                ],
                [
                    'title_ar'         => $sample['title_ar'],
                    'description'      => $sample['description'] ?? null,
                    'description_ar'   => $sample['description_ar'] ?? null,
                    'icon'             => $sample['icon'] ?? null,
                    'sample_text'      => $text,
                    'sample_text_ar'   => $sample['text_ar'],
                    'audio_path'       => $result['audio_path'],
                    'language'         => $sample['language'],
                    'dialect'          => $sample['dialect'],
                    'country'          => $sample['country'],
                    'emotion'          => $sample['emotion'],
                    'gender'           => $sample['gender'],
                    'voice_name'       => $voiceName,
                    'provider'         => $providerUsed,
                    'category'         => $sample['category'],
                    'sort_order'       => $index + 1,
                    'is_featured'      => (bool) $sample['is_featured'],
                    'is_active'        => true,
                    'duration_seconds' => (int) ($result['duration_seconds'] ?? 0),
                    'metadata'         => [
                        'provider_used'     => $providerUsed,
                        'model'             => $result['model'] ?? null,
                        'latency_ms'        => $result['latency_ms'] ?? null,
                        'char_count'        => $result['char_count'] ?? null,
                        'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
                    ],
                ],
            );

            $created++;
            usleep(6_000_000);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Created/updated: {$created}, skipped: {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    private function providerOrder(string $providerOption): array
    {
        return match ($providerOption) {
            'google-cloud' => ['google_cloud'],
            'gemini'       => ['gemini'],
            'auto'         => ['gemini', 'google_cloud'],
            default        => ['gemini', 'google_cloud'],
        };
    }

    /** @return array<string, mixed> */
    private function buildVoiceConfig(array $sample, string $provider): array
    {
        if ($provider === 'google_cloud') {
            return [
                'voice_name' => (string) $sample['google_voice'],
                'gender'     => strtoupper((string) $sample['gender']),
                'tier'       => 'hd',
                'style'      => null,
                'speed'      => 1.0,
                'pitch'      => 0.0,
            ];
        }

        return [
            'voice_name'   => (string) $sample['gemini_voice'],
            'style'        => (string) $sample['emotion'],
            'style_prompt' => (string) ($sample['style_prompt'] ?? ''),
            'tier'         => 'hd',
            'speed'        => 1.0,
            'pitch'        => 0.0,
        ];
    }
}
