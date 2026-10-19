<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Profanity Filtering
    |--------------------------------------------------------------------------
    |
    | Arabic terms below are adapted from:
    | https://github.com/ASammour/bad-words-AR
    |
    | Keep entries lowercase where possible. The filter normalizes Arabic,
    | strips diacritics/tatweel, collapses separators, and can be extended
    | easily with more languages over time.
    |
    | Terms match whole words only, and a phrase only as those words in a
    | row. Digits count as letters only inside words ("sh1t"), and letters
    | spaced out one by one ("f u c k") are read as one word.
    |
    */

    'collapse_repeated_characters' => true,

    /*
    | Ordinary phrases that hold a listed word. They are taken out of the
    | text before it is checked.
    */
    'allowed_phrases' => [
        // Latin "cum" (with): magna cum laude, summa cum laude.
        'cum laude',
    ],

    /*
    |--------------------------------------------------------------------------
    | Word Selection Criteria
    |--------------------------------------------------------------------------
    |
    | Only include words that are SEXUALLY EXPLICIT, VULGAR, or targeted
    | slurs with no legitimate use in normal text/storytelling.
    |
    | DO NOT include:
    | - Common adjectives (dirty, stupid, ugly, trivial, impure)
    | - Animal names (dog, donkey)
    | - Words with dual meanings that appear in normal Arabic text
    | - General insults that appear in stories/dialogue
    |
    | A TTS platform must allow converting stories, scripts, and dialogue
    | that may contain strong language. Only block explicit sexual/vulgar
    | content that has no place in professional voice generation.
    |
    */

    'lists' => [
        'ar' => [
            // Left out on purpose, everyday words with another meaning: يخول and
            // خول (authorizes), مفسوخ (rescinded), زبر (Quran 18:96), زبور (the
            // Psalms), قضيب (rod), لحس (lick), بغي (wrong; "wanted" in the Gulf),
            // ينكح and انكح (marry, in religious texts), خوال (maternal uncles),
            // زامل (a name; worked alongside), الزريبة (animal pen), دعارة (a news
            // word), حلمات, مفشوخ, نايك (a brand name), and "يا ابن ال" (also starts
            // "يا ابن الحلال"; the insults it was there for are listed).

            // Sexual / genital terms
            'كس',
            'كثمك',
            'كسك',
            'كسكم',
            'كسها',
            'كسه',
            'كسي',
            'زبك',
            'زبه',
            'زب',
            'زبكم',
            'زبي',
            'زبها',
            // Not اير on its own: it is also the English "air" in Arabic letters.
            'ايرك',
            'ايره',
            'ايرها',
            'ايري',
            'الزب',
            'الكس',
            'ايور',
            'بزاز',
            'بعبص',
            'زبار',
            'قضيبي',
            'كس اختك',
            'كس امك',
            'كس اخت',
            'كس ام',
            'كس عرضك',
            'كسمك',
            'كسمكم',
            'كسمينك',
            'كسمين',
            'كسم امك',
            'كسم اختك',
            'مكسكس',
            'طيز',
            'طيزك',
            'طيزه',
            'طيزها',
            'طيزي',
            'طيزكم',
            'طيزهم',

            // Sexual acts / explicit
            'نيك',
            'نيكك',
            'نيككك',
            'ناك',
            'انيك',
            'انيكك',
            'ينيك',
            'افسخك',
            'تجليخ',
            'جلخ',
            'جلق',
            'مص زب',
            'متناك',
            'مبعوص',
            'ممحون',
            'منايك',
            'منيك',
            'منيوك',
            'منيوكة',
            'ناكك',
            'نيج',
            'نكت اخته',
            'نكت امه',
            'نياكة',
            'نياكه',
            'يتناك',
            'سكس',
            'سيكس',
            'سكسي',
            'سيكسي',

            // Prostitution-related
            'ابن النيك',
            'ابن عاهر',
            'اخو القحبه',
            'اخو القحبة',
            'القحبة',
            'الكحبة',
            'الكحبه',
            'القحبه',
            'بغاي',
            'داعر',
            'سارموتا',
            'سرموتا',
            'شرموط',
            'شرموطة',
            'شرموطه',
            'الشرموطة',
            'الشرموطه',
            'شراميط',
            'عاهرة',
            'عاهره',
            'العاهرة',
            'العاهره',
            'قحاب',
            'قحبة',
            'قحبه',
            'خانيث',
            'خنيث',
            'كحبه',
            'يعرص',
            'كحبة',
            'لقحاب',
            'ولد القحبة',
            'ولد القحبه',
            'ابن القحبة',
            'ابن القحبه',

            // Sexual orientation slurs
            'خنيث',
            'لوطي',
            'مخانيث',
            'مخنث',

            // Pimping / cuckoldry slurs (genuinely vulgar, no dual meaning)
            'عرص',
            'العرص',
            'معرص',
            'معرصين',
            'المعرصين',
            'عرصه',
            'عرصة',
            'ديوث',
            'الديوث',

            // Explicit compound insults (targeted, no dual meaning)
            'ابن المرة',
            'ابن المرا',
            'ابن كلب',
            'ابن الكلب',
            'كل خرا',
            'كل خرة',
            'كل زق',
            'ياشرموطة',
            'ياشرموطه',
            'ياقحبة',
            'ياقحبه',

            // Arabizi (Arabic profanity written in Latin letters)
            'kosomak',
            'kossomak',
            'kosomk',
            'koss omak',
            'kuss omak',
            'kess omak',
            'ya kosomak',
            'zeb',
            'ayr',
            'nik',
            'tnak',
            'sharmota',
            'sharmouta',
            'ya sharmota',
            'kahba',
            'qahba',
            'khawal',
            'khaneeth',
            'qahbeh',
        ],

        'en' => [
            // Left out on purpose, everyday words with another meaning: ass
            // (donkey; Ass. Prof.), tit (tit for tat; the bird), cock (rooster),
            // prick (prick a finger), bastard and jackass (their literal sense),
            // horny (horny skin), spunk (courage), fag (cigarette) and xxx
            // (kisses; Roman 30).
            'arse',
            'arsehole',
            'asshole',
            'asshat',
            'asswipe',
            'biatch',
            'bitch',
            'bitches',
            'blowjob',
            'bollocks',
            'boner',
            'boob',
            'boobs',
            'bullshit',
            'buttplug',
            'cocksucker',
            'clit',
            'clitoris',
            'cunt',
            'cum',
            'cumshot',
            'dick',
            'dickhead',
            'dildo',
            'doggystyle',
            'douche',
            'douchebag',
            'faggot',
            'fuck',
            'fucked',
            'fucker',
            'fuckers',
            'fuckface',
            'fuckhead',
            'fucking',
            'motherfucking',
            'fuckwit',
            'fuck you',
            'handjob',
            'jerk off',
            'jerking off',
            'masturbate',
            'masturbation',
            'mother fucker',
            'motherfucker',
            'nigga',
            'nigger',
            'nutsack',
            'orgasm',
            'piece of shit',
            'porn',
            'porno',
            'pornographic',
            'pussy',
            'rimjob',
            'shit',
            'shitface',
            'shithead',
            'shitty',
            'suck my dick',
            'slut',
            'sluts',
            'son of a bitch',
            'teabagging',
            'testicles',
            'tits',
            'titjob',
            'twat',
            'vagina',
            'wanker',
            'whore',
            'whores',
        ],
    ],
];
