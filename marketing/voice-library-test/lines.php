<?php

/*
 * A short test of the Gemini Extended Voice Library: library voices reading
 * existing preview lines, to hear whether they keep each dialect or accent;
 * and (vt-child-*) whether a young, light voice directed as a child sounds
 * like one (the library has no child voices).
 */

return [
    "samples" => [
        ["slug" => 'vt-ar-eg-najdi', "voice" => 'ar-eg-assistant-11', "language" => 'ar', "dialect" => 'ar-SA-NB', "speaker" => 'ar-fahad',
            "style" => 'A clear, warm older Bedouin man from central Arabia, unhurried.',
            "text" => 'هلا والله يا ولد. وش علومك؟ هقيت إنك نسيت البر، تعال الليلة نشبّ النار ونسولف تحت النجوم، والقهوة زينة.'],
        ["slug" => 'vt-ar-msa-emirati', "voice" => 'ar-001-advisor-3', "language" => 'ar', "dialect" => 'ar-AE', "speaker" => 'ar-hamed',
            "style" => 'A lively, upbeat Emirati show host.',
            "text" => 'هلا والله وحيّاكم! شحالكم اليوم؟ الحين بنبدا حلقة يديدة، وعندنا لكم أخبار وايد حلوة، فخلّكم ويّانا للآخر!'],
        ["slug" => 'vt-ar-msa-qatari', "voice" => 'ar-001-commercial-5', "language" => 'ar', "dialect" => 'ar-QA-WH', "speaker" => 'ar-noura',
            "style" => 'A bubbly young Qatari lifestyle creator talking to her followers.',
            "text" => 'هلا بنات! شخباركم؟ اليوم بسوي قهوة مثلّجة بالبيت، هيه سهلة وايد، مب محتاجة غير ثلاث أشياء. يلا نبدا!'],
        ["slug" => 'vt-ar-eg-lebanese', "voice" => 'ar-eg-advisor-12', "language" => 'ar', "dialect" => 'ar-LB', "speaker" => 'ar-sawsan',
            "style" => 'A bright, cheerful Beirut lifestyle host.',
            "text" => 'هاي، كيفكن؟ شو الأخبار؟ هلّق بدّي إحكيلكن عن مطعم صغير بالجبل، أكلو طيّب كتير، وبصراحة الجوّ هونيك بيجنّن.'],
        ["slug" => 'vt-ar-eg-cairo', "voice" => 'ar-eg-advisor-5', "language" => 'ar', "dialect" => 'ar-EG', "speaker" => 'ar-sumaya',
            "style" => 'An easy-going Cairene woman, relaxed and smiling.',
            "text" => 'بصراحة؟ أحلى حاجة في يومي فنجان القهوة الصبح على البلكونة. <chuckle> مفيش كلام، ولا موبايل، بس أنا والهدوء.'],
        ["slug" => 'vt-en-glasgow', "voice" => 'en-gb-commercial-4', "language" => 'en', "dialect" => 'en-GB-SC', "speaker" => 'en-isla',
            "style" => 'A warm Scottish woman, clear and gentle, telling a family story by the fire.',
            "text" => 'My granny would sit by the fire every winter and tell us stories about the islands, the storms, the fishing boats, and the folk who never came back to shore.'],
        ["slug" => 'vt-en-capetown', "voice" => 'en-za-advisor-4', "language" => 'en', "dialect" => 'en-ZA', "speaker" => 'en-oliver',
            "style" => 'A friendly Cape Town local giving directions.',
            "text" => 'Turn left at the second robot, carry on past the school, and you\'ll see the house with the big jacaranda tree. You really can\'t miss it.'],
        ["slug" => 'vt-en-dublin', "voice" => 'en-ie-advisor-5', "language" => 'en', "dialect" => 'en-IE', "speaker" => 'en-sienna',
            "style" => 'A youthful, delighted Irish woman telling a friend about her weekend.',
            "text" => 'Ah, it was the best weekend! We drove down to Galway, had great craic in this tiny pub, and then danced till three in the morning. Brilliant, so it was.'],
        // Children: a young, light adult voice directed to speak as a child.
        ["slug" => 'vt-child-ar-girl', "voice" => 'ar-eg-training-7', "language" => 'ar', "dialect" => 'ar-EG', "speaker" => 'child',
            "style" => 'An excited eight-year-old Egyptian girl telling her mother about her school trip. A real child: small, high, bright childish voice, fast and breathless with excitement, a little lisp, never an adult imitating a child.',
            "text" => 'ماما ماما! النهارده رحنا جنينة الحيوانات، وشفت زرافة طويلة أوي أوي، وأكّلتها جزرة بإيدي! هو إحنا ممكن نروح تاني بكرة؟'],
        ["slug" => 'vt-child-ar-boy', "voice" => 'en-ie-assistant-6', "language" => 'ar', "dialect" => 'ar-SA', "speaker" => 'child',
            "style" => 'A proud nine-year-old Saudi boy from Riyadh showing his father a drawing. A real young boy before his voice breaks: high, light childish voice, eager and a bit shy, never an adult imitating a child.',
            "text" => 'يبه يبه، شوف! رسمت لك صقر كبير، وهذا جناحه، وهذي عيونه. المعلمة قالت إنه أحلى رسمة بالفصل كله!'],
        ["slug" => 'vt-child-en-girl', "voice" => 'en-us-training-11', "language" => 'en', "dialect" => 'en-US', "speaker" => 'child',
            "style" => 'A giggly seven-year-old American girl telling her grandma big news. A real child: small, high, bright childish voice, bouncy and breathless, never an adult imitating a child.',
            "text" => 'Grandma, guess what? I lost my very first tooth today, right in the middle of lunch! And now I have a tiny window in my smile. Do you think the tooth fairy will find me?'],
        ["slug" => 'vt-child-en-boy', "voice" => 'en-gb-csagent-8', "language" => 'en', "dialect" => 'en-GB', "speaker" => 'child',
            "style" => 'An eager nine-year-old British boy explaining his Lego rocket. A real young boy before his voice breaks: high, light childish voice, quick and excited, never an adult imitating a child.',
            "text" => 'Okay, so this is my rocket. These blue bits are the boosters, and this little window is where the astronaut sits. It can go all the way to the moon and back, probably twice!'],
    ],
];
