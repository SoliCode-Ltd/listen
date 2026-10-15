<?php

/*
 * Homepage "sound environments" samples: one line written for each place,
 * spoken by a homepage voice (Gemini 3.8, `php artisan tts:showcase --set=environments
 * --generate`), then placed in that environment by the studio's own engine
 * (`php artisan tts:showcase --set=environments --render`).
 *
 * public/audio/environments/clean/<slug>.mp3  the voice as generated
 * public/audio/environments/<slug>.mp3        the same voice in the place
 *
 * effect: an App\Support\AudioEffects key. No sign-off: the place is the point.
 */

return [
    'samples' => [

        // ── Arabic ──────────────────────────────────────────────────

        ['slug' => 'env-ar-stadium', 'language' => 'ar', 'dialect' => 'ar-SA', 'effect' => 'stadium',
            'voice' => 'Algenib', 'speaker' => 'سلطان',
            'scene' => 'تعليق رياضي',
            'style' => 'A Saudi football commentator at the moment of a stunning goal. Explosive, breathless excitement, stretching the first word, voice rising with the crowd.',
            'text' => 'قووووول! يا سلام يا سلام! كرة صاروخية من خارج المنطقة تسكن الزاوية البعيدة! الجماهير كلها واقفة، والملعب يهتز!'],

        ['slug' => 'env-ar-announcement', 'language' => 'ar', 'dialect' => 'ar-XA', 'effect' => 'announcement',
            'voice' => 'Kore', 'speaker' => 'ريم',
            'scene' => 'نداء في المطار',
            'style' => 'A calm, polished airport announcer. Clear, measured and neutral, with gentle pauses between the details.',
            'text' => 'السادة المسافرون على الرحلة رقم ثلاثمئة وأربعة المتجهة إلى دبي، يُرجى التوجه إلى البوابة رقم اثني عشر. الصعود إلى الطائرة يبدأ الآن.'],

        ['slug' => 'env-ar-phone', 'language' => 'ar', 'dialect' => 'ar-EG', 'effect' => 'phone',
            'voice' => 'Aoede', 'speaker' => 'سلمى',
            'scene' => 'مكالمة هاتفية',
            'style' => 'A young Cairene woman on a phone call with a close friend, walking fast. Warm, casual, slightly rushed.',
            'text' => 'ألو؟ أيوه يا حبيبتي، أنا في الطريق أهو، عشر دقايق وأكون عندك. بس ما تنسيش تجيبي الورق معاكي، ماشي؟'],

        ['slug' => 'env-ar-radio', 'language' => 'ar', 'dialect' => 'ar-XA', 'effect' => 'radio',
            'voice' => 'Charon', 'speaker' => 'وليد',
            'scene' => 'نشرة إذاعية من الماضي',
            'style' => 'A classic 1960s Arab radio news announcer. Formal, resonant and stately, with the grand cadence of the golden age of radio.',
            'text' => 'هنا إذاعة الشرق. سيداتي سادتي، نقدّم لكم نشرة الأخبار. أُعلن اليوم عن افتتاح أول جسر يربط ضفتي النهر، وسط احتفالات شعبية كبيرة.'],

        ['slug' => 'env-ar-cave', 'language' => 'ar', 'dialect' => 'ar-XA', 'effect' => 'cave',
            'voice' => 'Enceladus', 'speaker' => 'فارس',
            'scene' => 'مغامرة في كهف',
            'style' => 'An explorer deep inside a cave, speaking in a hushed, tense half-whisper. Slow, suspenseful, with long breaths between phrases.',
            'text' => 'هل تسمع ذلك؟ قطرات الماء... لم يدخل أحد هذا الكهف منذ مئات السنين. خطوة واحدة أخرى، وسنعرف ما الذي يختبئ في الظلام.'],

        ['slug' => 'env-ar-hall', 'language' => 'ar', 'dialect' => 'ar-XA', 'effect' => 'hall',
            'voice' => 'Iapetus', 'speaker' => 'خالد',
            'scene' => 'حفل في قاعة كبرى',
            'style' => 'A master of ceremonies opening a grand gala in a large hall. Proud, warm and resonant, projecting to a big audience.',
            'text' => 'أيها الحضور الكرام، أهلاً بكم في حفل الختام. الليلة نحتفي بمن صنعوا الفرق، وبكل حلم تحوّل إلى حقيقة.'],

        // ── English ─────────────────────────────────────────────────

        ['slug' => 'env-en-stadium', 'language' => 'en', 'dialect' => 'en-GB', 'effect' => 'stadium',
            'voice' => 'Puck', 'speaker' => 'Harry',
            'scene' => 'Football commentary',
            'style' => 'A British football commentator at the moment of a stunning goal. Explosive, breathless excitement, voice rising with the crowd.',
            'text' => 'And he shoots... it\'s in! What a goal! Forty thousand people on their feet, and this stadium is absolutely shaking!'],

        ['slug' => 'env-en-announcement', 'language' => 'en', 'dialect' => 'en-GB', 'effect' => 'announcement',
            'voice' => 'Kore', 'speaker' => 'Emma',
            'scene' => 'Airport call',
            'style' => 'A calm, polished airport announcer. Clear, measured and neutral, with gentle pauses between the details.',
            'text' => 'Ladies and gentlemen, this is the final boarding call for flight two-one-seven to New York. Please make your way to gate fourteen immediately.'],

        ['slug' => 'env-en-phone', 'language' => 'en', 'dialect' => 'en-US', 'effect' => 'phone',
            'voice' => 'Aoede', 'speaker' => 'Chloe',
            'scene' => 'Phone call',
            'style' => 'A young American woman calling a friend while walking fast. Warm, casual, slightly rushed and smiling.',
            'text' => 'Hey, it\'s me! I\'m just outside, running five minutes late. Can you grab us a table by the window? Thanks, see you soon!'],

        ['slug' => 'env-en-radio', 'language' => 'en', 'dialect' => 'en-US', 'effect' => 'radio',
            'voice' => 'Charon', 'speaker' => 'Walter',
            'scene' => 'Radio bulletin from the past',
            'style' => 'A 1950s American radio newsreader. Formal, resonant and brisk, with the confident mid-century broadcast cadence.',
            'text' => 'Good evening, ladies and gentlemen. This is your evening bulletin. Tonight, crowds gathered downtown to welcome the city\'s very first electric streetcar.'],

        ['slug' => 'env-en-cave', 'language' => 'en', 'dialect' => 'en-GB', 'effect' => 'cave',
            'voice' => 'Enceladus', 'speaker' => 'Oliver',
            'scene' => 'Cave expedition',
            'style' => 'An explorer deep inside a cave, speaking in a hushed, tense half-whisper. Slow, suspenseful, with long breaths between phrases.',
            'text' => 'Can you hear that? Water, dripping somewhere deep below. Nobody has walked these tunnels in centuries. One more step, and we\'ll see what the dark is hiding.'],

        ['slug' => 'env-en-church', 'language' => 'en', 'dialect' => 'en-US', 'effect' => 'church',
            'voice' => 'Iapetus', 'speaker' => 'James',
            'scene' => 'Epic trailer',
            'style' => 'A deep, epic movie-trailer narrator. Slow, grand and awe-struck, letting every phrase land.',
            'text' => 'In a world where every voice can be heard... one story was written in stone. And tonight, it will echo forever.'],
    ],
];
