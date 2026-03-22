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
    */

    'collapse_repeated_characters' => true,

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
            // Sexual / genital terms
            'كثمك',
            'زبك',
            'زبه',
            'اير',
            'الزب',
            'الكس',
            'ايري',
            'ايور',
            'بزاز',
            'بعبص',
            'حلمات',
            'زبار',
            'زبر',
            'زبي',
            'قضيبي',
            'كس اختك',
            'كس امك',
            'كس عرضك',
            'كسمك',
            'كسمكم',
            'كسها',
            'مكسكس',
            'طيز',

            // Sexual acts / explicit
            'انكح',
            'انيك',
            'انيكك',
            'افسخك',
            'تجليخ',
            'جلخ',
            'جلق',
            'لحس',
            'متناك',
            'مبعوص',
            'مفسوخ',
            'ممحون',
            'منايك',
            'منيك',
            'منيوك',
            'ناكك',
            'نكت اخته',
            'نكت امه',
            'نياكة',
            'نياكه',
            'يتناك',

            // Prostitution-related
            'ابن النيك',
            'ابن عاهر',
            'اخو القحبه',
            'القحبة',
            'الكحبة',
            'الكحبه',
            'بغاي',
            'داعر',
            'دعارة',
            'سارموتا',
            'سرموتا',
            'شرموط',
            'شرموطة',
            'شرموطه',
            'عاهرة',
            'قحاب',
            'قحبة',
            'كحبة',
            'لقحاب',
            'ولد القحبة',
            'ولد القحبه',

            // Sexual orientation slurs
            'خنيث',
            'لوطي',
            'مخانيث',
            'مخنث',
            'زامل',

            // Explicit compound insults (targeted, no dual meaning)
            'ابن المرة',
            'ابن المرا',
            'ابن كلب',
            'كل خرا',
            'كل خرة',
            'كل زق',
            'يا ابن ال',
            'سكس',
            'سكسي',
            'سيكس',
            'سيكسي',
        ],

        'en' => [
            'asshole',
            'bastard',
            'bitch',
            'bullshit',
            'cunt',
            'dickhead',
            'fucker',
            'fucking',
            'jackass',
            'motherfucker',
            'piece of shit',
            'prick',
            'shithead',
            'slut',
            'son of a bitch',
            'whore',
        ],
    ],
];
