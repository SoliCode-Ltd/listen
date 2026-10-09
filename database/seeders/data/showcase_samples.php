<?php

/*
 * Showcase samples for the homepage, generated with Gemini 3.8 Flash TTS by
 * `php artisan tts:showcase`. Every clip ends with the Listen sign-off so the
 * audio carries its source when it is shared.
 *
 * kind "single": one voice. kind "dialogue": two voices, lines as "Name: text".
 * voice: a Gemini prebuilt voice. speaker / speaker_ar: the name shown on the site.
 * dialect: an ArabicDialects or EnglishAccents code (it picks the flag and label).
 * style: delivery direction sent as speech metadata, never spoken.
 */

return [
    'sign_off' => [
        'en' => 'This voice was created with Listen.',
        'ar' => 'هذا الصوت من إنتاج منصة إسمع.',
        'en_dialogue' => 'These voices were created with Listen.',
        'ar_dialogue' => 'هذه الأصوات من إنتاج منصة إسمع.',
    ],

    'samples' => [

        // ── Arabic dialects ─────────────────────────────────────────

        ['slug' => 'ar-xa-documentary', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-XA', 'gender' => 'male',
            'voice' => 'Charon', 'speaker' => 'Waleed', 'speaker_ar' => 'وليد',
            'title' => 'Documentary narration', 'title_ar' => 'تعليق وثائقي', 'emotion' => 'documentary',
            'style' => 'A deep, cinematic documentary narrator. Unhurried, warm authority, with meaningful pauses between sentences.',
            'text' => 'في قلب الصحراء، حيث تمتدّ الكثبان بلا نهاية، تبدأ الحياة مع أول خيوط الفجر. هنا، تعلّمت الكائنات أن الصبر ليس ضعفاً، بل هو سرّ البقاء.'],

        ['slug' => 'ar-xa-audiobook', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-XA', 'gender' => 'female',
            'voice' => 'Leda', 'speaker' => 'Noura', 'speaker_ar' => 'نورة',
            'title' => 'Audiobook', 'title_ar' => 'كتاب صوتي', 'emotion' => 'storytelling',
            'style' => 'An intimate audiobook narrator. Soft, emotionally present, slightly hushed, letting the suspense build.',
            'text' => 'أغلقت الكتاب على مهل، ونظرت من النافذة إلى المطر. كانت تعرف أن الرسالة ستصل اليوم، لكنها لم تكن تعرف أنها ستغيّر كل شيء.'],

        ['slug' => 'ar-sa-najdi-ad', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-SA', 'gender' => 'male',
            'voice' => 'Algenib', 'speaker' => 'Sultan', 'speaker_ar' => 'سلطان',
            'title' => 'Radio ad', 'title_ar' => 'إعلان إذاعي', 'emotion' => 'commercial',
            'style' => 'An energetic Riyadh radio ad voice. Confident, upbeat, smiling, punchy on the call to action.',
            'text' => 'يا هلا والله! تبي صوت احترافي لإعلانك؟ الحين تقدر، بضغطة زر وبلهجتك. لا تنتظر، جرّب اليوم، والنتيجة بتعجبك مرّه!'],

        ['slug' => 'ar-sa-hejazi-vlog', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-SA-HJ', 'gender' => 'female',
            'voice' => 'Despina', 'speaker' => 'Maha', 'speaker_ar' => 'مها',
            'title' => 'Weekend plans', 'title_ar' => 'طلعة الويكند', 'emotion' => 'friendly',
            'style' => 'A cheerful young woman from Jeddah talking to her friends. Bright, playful, quick sing-song rhythm.',
            'text' => 'يا جماعة، إيش رأيكم نطلع البلد الليلة؟ الجو دحين حلو مرّة، ونتعشى فول وتميس ونتمشى على البحر. أبغى أشوفكم كلكم!'],

        ['slug' => 'ar-ae-podcast', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-AE', 'gender' => 'male',
            'voice' => 'Achird', 'speaker' => 'Nawaf', 'speaker_ar' => 'نواف',
            'title' => 'Podcast intro', 'title_ar' => 'مقدمة بودكاست', 'emotion' => 'conversational',
            'style' => 'A relaxed Emirati podcast host opening an episode. Warm, curious, conversational.',
            'text' => 'مرحبا الساع! اليوم بنتكلم عن ريّال من دبي بدا مشروعه من الصفر. وايد ناس قالوا له مستحيل، بس هو ما وقف. شو تتوقعون صار؟'],

        ['slug' => 'ar-kw-cooking', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-KW', 'gender' => 'female',
            'voice' => 'Kore', 'speaker' => 'Lamya', 'speaker_ar' => 'لمياء',
            'title' => 'Cooking show', 'title_ar' => 'برنامج طبخ', 'emotion' => 'happy',
            'style' => 'A lively Kuwaiti cooking-show host. Generous, excited, homely warmth.',
            'text' => 'هلا والله! شلونكم؟ اليوم يايبة لكم وصفة مجبوس دياي، وايد سهلة وطعمها يجنن. يلا خلونا نبدي!'],

        ['slug' => 'ar-qa-corniche', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-QA', 'gender' => 'male',
            'voice' => 'Fenrir', 'speaker' => 'Saud', 'speaker_ar' => 'سعود',
            'title' => 'Evening out', 'title_ar' => 'طلعة الكورنيش', 'emotion' => 'friendly',
            'style' => 'A young man from Doha calling his friends. Easy-going, light, quick Gulf rhythm.',
            'text' => 'هلا يا الربع! شحالكم؟ الحين بنروح نشوف البحر في الكورنيش، والجو وايد زين. ترى الطريق مب زحمة اليوم، يلا عيل.'],

        ['slug' => 'ar-bh-souq', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-BH', 'gender' => 'female',
            'voice' => 'Aoede', 'speaker' => 'Khadija', 'speaker_ar' => 'خديجة',
            'title' => 'Travel guide', 'title_ar' => 'دليل سياحي', 'emotion' => 'friendly',
            'style' => 'A friendly Bahraini travel vlogger walking through a market. Soft, inviting, smiling.',
            'text' => 'شلونكم؟ اليوم بآخذكم جولة في سوق المنامة القديم، ريحة البهارات والحلوى البحرينية وايد تفتح النفس. شنو تبون نذوق أول؟'],

        ['slug' => 'ar-om-travel', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-OM', 'gender' => 'male',
            'voice' => 'Iapetus', 'speaker' => 'Fahad', 'speaker_ar' => 'فهد',
            'title' => 'Mountain trip', 'title_ar' => 'رحلة الجبل', 'emotion' => 'calm',
            'style' => 'A calm Omani man describing a trip. Gentle, measured, content.',
            'text' => 'هلا وغلا! بغيت أحكي لكم عن رحلتي إلى الجبل الأخضر، المناظر واجد حلوة والهوا بارد. شو رايكم نروح سوا الأسبوع الجاي؟'],

        ['slug' => 'ar-eg-cairo-promo', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-EG', 'gender' => 'female',
            'voice' => 'Callirrhoe', 'speaker' => 'Sumaya', 'speaker_ar' => 'سمية',
            'title' => 'Social media promo', 'title_ar' => 'إعلان سوشيال', 'emotion' => 'excited',
            'style' => 'A charismatic Cairene content creator talking straight to camera. Bubbly, persuasive, fast and natural.',
            'text' => 'بص بقى، أنا عايزة أقولك حاجة مهمة أوي. البرنامج ده هيوفّر عليك وقت كتير، ودلوقتي تقدر تعمل صوت احترافي في ثانية. جرّبه وقولّي رأيك!'],

        ['slug' => 'ar-eg-saidi-wisdom', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-EG-SA', 'gender' => 'male',
            'voice' => 'Schedar', 'speaker' => 'Hazem', 'speaker_ar' => 'حازم',
            'title' => 'Words of wisdom', 'title_ar' => 'حكمة الجدود', 'emotion' => 'wise',
            'style' => 'An older man from Upper Egypt giving advice to his son. Deep, slow, proud and tender.',
            'text' => 'يا ولدي، اسمع الكلام ده زين. الأرض دي ما بتديش غير اللي يتعب فيها. أبوي كان يگول: اللي يزرع الخير، يحصد الخير. ودلوك جه دورك.'],

        ['slug' => 'ar-lb-food', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-LB', 'gender' => 'female',
            'voice' => 'Zephyr', 'speaker' => 'Sawsan', 'speaker_ar' => 'سوسن',
            'title' => 'Food vlog', 'title_ar' => 'فلوغ أكل', 'emotion' => 'happy',
            'style' => 'A charming Beiruti food blogger. Melodic, affectionate, playful.',
            'text' => 'كيفكن يا حلوين؟ هلّق رح خبّركن عن أطيب منقوشة بتاكلوها بحياتكن. بدّي ياكن تجرّبوها الصبح مع كاسة شاي، رح تحبّوها كتير!'],

        ['slug' => 'ar-sy-story', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-SY', 'gender' => 'male',
            'voice' => 'Umbriel', 'speaker' => 'Raed', 'speaker_ar' => 'رائد',
            'title' => 'Old Damascus story', 'title_ar' => 'حكاية شامية', 'emotion' => 'nostalgic',
            'style' => 'A Damascene storyteller remembering his childhood. Soft, nostalgic, melodic.',
            'text' => 'شلونكن؟ اليوم بدّي احكيلكن حكاية من حارات الشام العتيقة، عن جدّي وفرنه الصغير، وريحة الخبز اللي كانت تملّي الحارة كلها.'],

        ['slug' => 'ar-jo-mansaf', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-JO', 'gender' => 'male',
            'voice' => 'Sadaltager', 'speaker' => 'Tamim', 'speaker_ar' => 'تميم',
            'title' => 'Restaurant tip', 'title_ar' => 'نصيحة مطعم', 'emotion' => 'friendly',
            'style' => 'A confident young man from Amman recommending a place to his friend. Firm, hearty, generous.',
            'text' => 'يا زلمة، هسّا بدّي أحكيلك عن أطيب منسف بعمّان. الجميد أصلي، واللحمة بتذوب ذوبان. تعال معي وبتشكرني بعدين.'],

        ['slug' => 'ar-ps-olives', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-PS', 'gender' => 'female',
            'voice' => 'Laomedeia', 'speaker' => 'Lubna', 'speaker_ar' => 'لبنى',
            'title' => 'Family memory', 'title_ar' => 'ذكرى عائلية', 'emotion' => 'nostalgic',
            'style' => 'A Palestinian woman remembering her grandmother. Warm, tender, smiling through the memory.',
            'text' => 'هلقيت بدّي أحكيلكم عن ستّي وقطف الزيتون. كل سنة كنّا نطلع ع الأرض الصبح بكّير، ونغنّي ونضحك. إشي ما بينتسى.'],

        ['slug' => 'ar-iq-mutanabbi', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-IQ', 'gender' => 'male',
            'voice' => 'Alnilam', 'speaker' => 'Faisal', 'speaker_ar' => 'فيصل',
            'title' => 'Friday in Baghdad', 'title_ar' => 'جمعة بغدادية', 'emotion' => 'proud',
            'style' => 'A proud Baghdadi man talking about his city. Warm, rich, unhurried.',
            'text' => 'شلونكم؟ هسّه راح أحچيلكم عن شارع المتنبي يوم الجمعة. الكتب هواية، والناس تشرب چاي وتتونّس. والله ماكو مثله بالدنيا.'],

        ['slug' => 'ar-ye-sanaa', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-YE', 'gender' => 'male',
            'voice' => 'Rasalgethi', 'speaker' => 'Mishary', 'speaker_ar' => 'مشاري',
            'title' => 'Old city walk', 'title_ar' => 'جولة في صنعاء', 'emotion' => 'friendly',
            'style' => 'A friendly guide from Sanaa. Welcoming, proud, gentle highland melody.',
            'text' => 'أهلين! إيش رأيكم نتمشّى في صنعاء القديمة؟ البيوت مزخرفة والسوق مليان بن وزبيب. قد شفتوا شي أحلى من كذا؟'],

        ['slug' => 'ar-sd-nile', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-SD', 'gender' => 'male',
            'voice' => 'Zubenelgenubi', 'speaker' => 'Yasser', 'speaker_ar' => 'ياسر',
            'title' => 'Sunset on the Nile', 'title_ar' => 'مغرب على النيل', 'emotion' => 'calm',
            'style' => 'A Sudanese man talking to a close friend. Calm, deep, measured and kind.',
            'text' => 'يا زول، كيفنك؟ هسّي داير أحكي ليك عن الخرطوم ساعة المغرب، والنيل لونه دهبي. حاجة جميلة شديد ياخي.'],

        ['slug' => 'ar-ma-atay', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-MA', 'gender' => 'female',
            'voice' => 'Erinome', 'speaker' => 'Aisha', 'speaker_ar' => 'عائشة',
            'title' => 'Moroccan tea', 'title_ar' => 'أتاي مغربي', 'emotion' => 'happy',
            'style' => 'A cheerful woman from Casablanca on a cooking channel. Quick, lively Darija rhythm.',
            'text' => 'السلام عليكم! دابا غادي نوريكم كيفاش كنوجدو أتاي مغربي مزيان. بزاف ديال النعناع، شوية ديال السكر، وصافي!'],

        ['slug' => 'ar-dz-morning', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-DZ', 'gender' => 'male',
            'voice' => 'Puck', 'speaker' => 'Othman', 'speaker_ar' => 'عثمان',
            'title' => 'Morning in Algiers', 'title_ar' => 'صباح دزيري', 'emotion' => 'friendly',
            'style' => 'A young man from Algiers chatting with friends. Lively, warm, quick Algerian rhythm.',
            'text' => 'واش راكم؟ درك نحكيلكم على قهوة الصباح في دزاير، مع الخبز والزيت. حاجة بسيطة برك، بصح تفرّح القلب بزاف.'],

        ['slug' => 'ar-tn-sidi-bou', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-TN', 'gender' => 'female',
            'voice' => 'Autonoe', 'speaker' => 'Ruqaya', 'speaker_ar' => 'رقية',
            'title' => 'City guide', 'title_ar' => 'دليل المدينة', 'emotion' => 'happy',
            'style' => 'A Tunisian travel host. Bright, musical, enthusiastic.',
            'text' => 'عسلامة! اليوم باش نحكيلكم على سيدي بوسعيد، الديار البيض والبيبان الزرق. برشا ناس تجي تشرب تاي بالبوفريوة. يعيشكم جرّبوها!'],

        ['slug' => 'ar-ly-medina', 'kind' => 'single', 'language' => 'ar', 'dialect' => 'ar-LY', 'gender' => 'male',
            'voice' => 'Enceladus', 'speaker' => 'Mansour', 'speaker_ar' => 'منصور',
            'title' => 'Old Tripoli', 'title_ar' => 'طرابلس القديمة', 'emotion' => 'proud',
            'style' => 'A man from Tripoli, Libya, proudly describing his city. Deep, warm, steady.',
            'text' => 'أهلين! توا بنحكيلكم على المدينة القديمة في طرابلس، الأسواق والريحة والناس الطيبين. والله حاجة باهية هلبا.'],

        // ── English accents ─────────────────────────────────────────

        ['slug' => 'en-us-brand', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-US', 'gender' => 'female',
            'voice' => 'Kore', 'speaker' => 'Daisy',
            'title' => 'Brand voice', 'title_ar' => 'صوت العلامة التجارية', 'emotion' => 'confident',
            'style' => 'A polished, modern brand voice. Confident, warm, clear, smiling slightly.',
            'text' => 'Your story deserves a voice that carries. Write a line, pick a voice, and hear it come alive in seconds.'],

        ['slug' => 'en-us-trailer', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-US', 'gender' => 'male',
            'voice' => 'Sadaltager', 'speaker' => 'Caleb',
            'title' => 'Movie trailer', 'title_ar' => 'إعلان فيلم', 'emotion' => 'dramatic',
            'style' => 'An epic movie-trailer voice. Deep, slow, dramatic, with heavy pauses between lines.',
            'text' => 'In a world where every voice can be heard... one city... one secret... and one choice that changes everything. This summer.'],

        ['slug' => 'en-us-southern', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-US-SO', 'gender' => 'male',
            'voice' => 'Algieba', 'speaker' => 'Oscar',
            'title' => 'Porch story', 'title_ar' => 'حكاية الشرفة', 'emotion' => 'nostalgic',
            'style' => 'An older Southern storyteller on a porch. Slow, warm drawl, amused.',
            'text' => "Well now, pull up a chair. Back home we'd sit on the porch till the fireflies came out, and Grandpa'd tell the same story every single time. <chuckle> We never once got tired of it."],

        ['slug' => 'en-us-newyork', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-US-NY', 'gender' => 'male',
            'voice' => 'Puck', 'speaker' => 'Dylan',
            'title' => 'Street talk', 'title_ar' => 'حديث الشارع', 'emotion' => 'playful',
            'style' => 'A fast-talking, good-humoured New Yorker. Punchy, animated, confident.',
            'text' => "Listen, I'm telling ya, the best slice in the city is two blocks from my place. Folded, a little grease, no fork. Don't let anybody tell you different."],

        ['slug' => 'en-ca-lake', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-CA', 'gender' => 'female',
            'voice' => 'Aoede', 'speaker' => 'Ruby',
            'title' => 'Morning radio', 'title_ar' => 'إذاعة الصباح', 'emotion' => 'friendly',
            'style' => 'A cheerful Canadian morning-radio host. Bright, friendly, easy.',
            'text' => 'Morning, everyone! Heading up to the lake this weekend? Pack a warm sweater, it gets pretty chilly once the sun goes down.'],

        ['slug' => 'en-gb-documentary', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-GB', 'gender' => 'male',
            'voice' => 'Charon', 'speaker' => 'Theodore',
            'title' => 'Nature documentary', 'title_ar' => 'وثائقي طبيعة', 'emotion' => 'documentary',
            'style' => 'A distinguished British wildlife documentary narrator. Hushed wonder, measured, rich.',
            'text' => 'Beneath the surface of the North Sea lies a world few have ever seen. Here, in near darkness, life has found extraordinary ways to endure.'],

        ['slug' => 'en-gb-london', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-GB-LD', 'gender' => 'male',
            'voice' => 'Fenrir', 'speaker' => 'Finn',
            'title' => 'Commute rant', 'title_ar' => 'حكاية المترو', 'emotion' => 'playful',
            'style' => 'A Londoner telling a funny story to his mates. Cheeky, animated, laughing at himself.',
            'text' => "Right, so I'm on the Tube, yeah, absolutely rammed, and this bloke next to me pulls out a full Sunday roast. <laugh> Proper mental."],

        ['slug' => 'en-gb-northern', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-GB-NO', 'gender' => 'male',
            'voice' => 'Schedar', 'speaker' => 'Marcus',
            'title' => 'Tea on the moors', 'title_ar' => 'شاي في المروج', 'emotion' => 'friendly',
            'style' => 'A down-to-earth man from Yorkshire. Dry humour, warm, relaxed.',
            'text' => "Now then, kettle's on. Nothing beats a brew after a long walk on the moors, especially when the rain's coming in sideways."],

        ['slug' => 'en-gb-scottish', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-GB-SC', 'gender' => 'male',
            'voice' => 'Enceladus', 'speaker' => 'Arthur',
            'title' => 'Highlands', 'title_ar' => 'المرتفعات الاسكتلندية', 'emotion' => 'calm',
            'style' => 'A Scottish man describing the Highlands. Gentle, proud, a little wistful.',
            'text' => "Aye, the Highlands are something else in autumn. Mist on the loch, hills turning gold. You've no idea till you've stood there yourself."],

        ['slug' => 'en-gb-welsh', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-GB-WL', 'gender' => 'female',
            'voice' => 'Laomedeia', 'speaker' => 'Poppy',
            'title' => 'Valley walk', 'title_ar' => 'نزهة في الوادي', 'emotion' => 'happy',
            'style' => 'A cheerful Welsh hiking guide. Musical, upbeat, encouraging.',
            'text' => "Welcome to the valleys! Grab your boots, the view from the top is lush, I promise you. We'll stop for a cuppa halfway."],

        ['slug' => 'en-ie-tea', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-IE', 'gender' => 'female',
            'voice' => 'Leda', 'speaker' => 'Sienna',
            'title' => 'Kitchen chat', 'title_ar' => 'دردشة المطبخ', 'emotion' => 'friendly',
            'style' => 'A warm Dublin woman talking to a guest. Lilting, kind, teasing.',
            'text' => "Ah, you'll have a cup of tea before you go, sure you will. The rain's only after starting, and there's no rush on anyone."],

        ['slug' => 'en-au-beach', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-AU', 'gender' => 'male',
            'voice' => 'Iapetus', 'speaker' => 'Miles',
            'title' => 'Beach day', 'title_ar' => 'يوم على الشاطئ', 'emotion' => 'excited',
            'style' => 'A laid-back Australian surfer. Sunny, upbeat, relaxed.',
            'text' => "G'day! Heading down to the beach this arvo. Grab your sunnies and a cold drink, the surf's looking absolutely perfect today."],

        ['slug' => 'en-nz-mountains', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-NZ', 'gender' => 'female',
            'voice' => 'Gacrux', 'speaker' => 'Isla',
            'title' => 'Road trip', 'title_ar' => 'رحلة برية', 'emotion' => 'friendly',
            'style' => 'A friendly New Zealander planning a trip. Easy-going, bright.',
            'text' => "Kia ora! We're off to the mountains this weekend. Pack your jandals and a decent jacket, the weather turns quick down here."],

        ['slug' => 'en-za-braai', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-ZA', 'gender' => 'male',
            'voice' => 'Achird', 'speaker' => 'Oliver',
            'title' => 'Weekend braai', 'title_ar' => 'شواء نهاية الأسبوع', 'emotion' => 'happy',
            'style' => 'A friendly South African host inviting friends over. Warm, hearty, upbeat.',
            'text' => "Howzit! Tonight we're having a proper braai. Bring your friends, it's going to be lekker, I promise."],

        ['slug' => 'en-in-travel', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-IN', 'gender' => 'female',
            'voice' => 'Despina', 'speaker' => 'Luna',
            'title' => 'Travel vlog', 'title_ar' => 'فلوغ سفر', 'emotion' => 'excited',
            'style' => 'An enthusiastic Indian travel vlogger. Energetic, clear, welcoming.',
            'text' => "Hello and welcome! Today I'll show you how to plan the perfect weekend in Jaipur, from the best street food to the most beautiful forts."],

        ['slug' => 'en-ng-business', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-NG', 'gender' => 'male',
            'voice' => 'Alnilam', 'speaker' => 'Charlie',
            'title' => 'Business podcast', 'title_ar' => 'بودكاست أعمال', 'emotion' => 'confident',
            'style' => 'A charismatic Nigerian business podcaster. Confident, rhythmic, engaging.',
            'text' => "My people, how far? Today we're talking about how a small idea in Lagos became one of the biggest businesses in the country. Stay with me."],

        ['slug' => 'en-jm-island', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-JM', 'gender' => 'male',
            'voice' => 'Umbriel', 'speaker' => 'Vincent',
            'title' => 'Island welcome', 'title_ar' => 'ترحيب الجزيرة', 'emotion' => 'relaxed',
            'style' => 'A relaxed Jamaican host welcoming visitors. Smooth, sunny, unhurried.',
            'text' => 'Welcome to the island, my friend! The sun is shining, the music is sweet, and the whole day is yours. Just relax and enjoy.'],

        ['slug' => 'en-sg-lunch', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-SG', 'gender' => 'female',
            'voice' => 'Callirrhoe', 'speaker' => 'Maya',
            'title' => 'Lunch plans', 'title_ar' => 'خطة الغداء', 'emotion' => 'playful',
            'style' => 'A bubbly young woman from Singapore talking to colleagues. Quick, cheerful.',
            'text' => "Hi everyone! Lunch today we go to the hawker centre, okay? Chicken rice, laksa, then kopi after. Confirm very good one."],

        ['slug' => 'en-ar-welcome', 'kind' => 'single', 'language' => 'en', 'dialect' => 'en-AR', 'gender' => 'male',
            'voice' => 'Sadachbia', 'speaker' => 'Adrian',
            'title' => 'Desert tour', 'title_ar' => 'جولة صحراوية', 'emotion' => 'friendly',
            'style' => 'A hospitable tour guide from the Gulf speaking English. Warm, generous, calm.',
            'text' => 'Hello, my friend, and welcome to Dubai. Tonight I will take you to the desert. We will drink Arabic coffee and watch the stars together.'],

        // ── Dialogues (two voices) ──────────────────────────────────

        ['slug' => 'dlg-en-podcast', 'kind' => 'dialogue', 'language' => 'en', 'dialect' => 'en-US',
            'title' => 'Tech podcast', 'title_ar' => 'بودكاست تقني', 'emotion' => 'conversational',
            'style' => 'Two podcast co-hosts, an American woman and a British man, who are good friends. Natural, overlapping energy, genuine laughter.',
            'speakers' => [
                ['name' => 'Daisy', 'voice' => 'Kore', 'gender' => 'female', 'dialect' => 'en-US'],
                ['name' => 'Theodore', 'voice' => 'Charon', 'gender' => 'male', 'dialect' => 'en-GB'],
            ],
            'lines' => [
                'Daisy: Okay, confession time. I made my morning playlist read me the news in a pirate voice.',
                'Theodore: <laugh> You did not.',
                'Daisy: I absolutely did. And honestly? Best traffic report of my life.',
                'Theodore: Right, well, I just use it to read my emails back so I can hear how rude I sound.',
                'Daisy: And?',
                'Theodore: <sigh> Very rude. Apparently.',
            ]],

        ['slug' => 'dlg-en-drama', 'kind' => 'dialogue', 'language' => 'en', 'dialect' => 'en-GB',
            'title' => 'Audio drama', 'title_ar' => 'دراما صوتية', 'emotion' => 'suspense',
            'style' => 'A tense scene from a British radio thriller. Two characters hiding in the dark, whispering urgently, breathing fast.',
            'speakers' => [
                ['name' => 'Clara', 'voice' => 'Zephyr', 'gender' => 'female', 'dialect' => 'en-GB'],
                ['name' => 'Marcus', 'voice' => 'Schedar', 'gender' => 'male', 'dialect' => 'en-GB'],
            ],
            'lines' => [
                'Clara: <whispers> Did you hear that?',
                'Marcus: <whispers> Stay down. Someone is on the stairs.',
                'Clara: We have to move. Now, before the lights come back.',
                'Marcus: <gasp> Too late. They know we are here.',
            ]],

        ['slug' => 'dlg-ar-sa-podcast', 'kind' => 'dialogue', 'language' => 'ar', 'dialect' => 'ar-SA',
            'title' => 'Saudi podcast', 'title_ar' => 'بودكاست سعودي', 'emotion' => 'conversational',
            'style' => 'Two young Saudi men from Riyadh hosting a relaxed podcast. Najdi dialect, friendly banter, real laughter.',
            'speakers' => [
                ['name' => 'Sultan', 'name_ar' => 'سلطان', 'voice' => 'Algenib', 'gender' => 'male'],
                ['name' => 'Fahad', 'name_ar' => 'فهد', 'voice' => 'Iapetus', 'gender' => 'male'],
            ],
            'lines' => [
                'Sultan: يا هلا فيكم في حلقة جديدة! فهد، وش سالفة إنك صرت تسجّل صوتك كل يوم؟',
                'Fahad: <laugh> لا تفضحني يا رجّال! بس صدق، صرت أكتب النص وهو يقراه بلهجتي.',
                'Sultan: بلهجتك؟ يعني نجدي ومرّه؟',
                'Fahad: إيه والله، حتى أمي ما عرفت إنه مو أنا!',
            ]],

        ['slug' => 'dlg-ar-eg-family', 'kind' => 'dialogue', 'language' => 'ar', 'dialect' => 'ar-EG',
            'title' => 'Egyptian family comedy', 'title_ar' => 'كوميديا مصرية', 'emotion' => 'comedy',
            'style' => 'A comic scene in a Cairo home: a sharp, loving Egyptian mother and her lazy son. Cairene dialect, big expressive comedy timing.',
            'speakers' => [
                ['name' => 'Sumaya', 'name_ar' => 'سمية', 'voice' => 'Callirrhoe', 'gender' => 'female'],
                ['name' => 'Othman', 'name_ar' => 'عثمان', 'voice' => 'Puck', 'gender' => 'male'],
            ],
            'lines' => [
                'Sumaya: يا ولد! الساعة بقت اتناشر وانت لسه نايم؟',
                'Othman: يا ماما، أنا صاحي من بدري، بس عينيّا مقفولة.',
                'Sumaya: <laugh> عينيك مقفولة؟ طب قوم يا حبيبي، الفطار برد خالص.',
                'Othman: <sigh> حاضر، حاضر، خمس دقايق بس.',
            ]],

        ['slug' => 'dlg-ar-lb-trip', 'kind' => 'dialogue', 'language' => 'ar', 'dialect' => 'ar-LB',
            'title' => 'Lebanese friends', 'title_ar' => 'أصحاب من بيروت', 'emotion' => 'friendly',
            'style' => 'Two friends from Beirut, a woman and a man, planning a weekend. Lebanese dialect, warm, playful, teasing.',
            'speakers' => [
                ['name' => 'Sawsan', 'name_ar' => 'سوسن', 'voice' => 'Zephyr', 'gender' => 'female'],
                ['name' => 'Raed', 'name_ar' => 'رائد', 'voice' => 'Umbriel', 'gender' => 'male'],
            ],
            'lines' => [
                'Sawsan: رائد، شو رأيك نطلع ع الجبل هالويكند؟ الطقس كتير حلو.',
                'Raed: والله فكرة! بس بشرط، إنتِ بتعملي المناقيش.',
                'Sawsan: <laugh> ماشي، وإنت بتسوق وما بدّي اسمع ولا كلمة عن الزحمة.',
                'Raed: اتفقنا يا ستّي، هلّق بحجزلنا.',
            ]],

        ['slug' => 'dlg-ar-xa-interview', 'kind' => 'dialogue', 'language' => 'ar', 'dialect' => 'ar-XA',
            'title' => 'News interview', 'title_ar' => 'مقابلة إخبارية', 'emotion' => 'news',
            'style' => 'A professional Arabic news interview in Modern Standard Arabic: a poised female anchor and a calm male expert. Clear, formal, broadcast quality.',
            'speakers' => [
                ['name' => 'Noura', 'name_ar' => 'نورة', 'voice' => 'Leda', 'gender' => 'female'],
                ['name' => 'Waleed', 'name_ar' => 'وليد', 'voice' => 'Charon', 'gender' => 'male'],
            ],
            'lines' => [
                'Noura: أهلاً بكم من جديد. معنا اليوم الخبير وليد لنتحدث عن مستقبل الصوت الرقمي. دكتور وليد، إلى أين نتّجه؟',
                'Waleed: شكراً لكِ. نحن نعيش لحظة فارقة، فالآلة باتت تتكلّم بلهجاتنا وتفهم مشاعرنا.',
                'Noura: وهل يمكن أن يميّز المستمع بين الصوت البشري والصوت المولَّد؟',
                'Waleed: في كثير من الأحيان، لا. ولهذا يجب أن نكون واضحين دائماً بشأن مصدر الصوت.',
            ]],
    ],
];
