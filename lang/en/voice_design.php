<?php

// Voice Design: the dashboard's section, the demo's tab, the upgrade card and the voice picker.
return [
    'nav' => 'Voice Design',
    'nav_short' => 'Design',
    'title' => 'Voice Design',
    'subtitle' => 'Describe any voice, a person or a character, and hear it come to life. Compare the takes, keep the one you love and use it anywhere in the studio.',

    // The card with the orb.
    'stage_hint' => 'Press play to hear voices designed from a single sentence.',
    'stage_idle' => 'Your voice will appear here',
    'stage_listen' => 'Play the voices',
    'stage_stop' => 'Stop',
    'now_speaking' => 'Now speaking: :name',

    // The form.
    'prompt_label' => 'Describe the voice',
    'prompt_placeholder' => 'A warm grandmother from old Cairo in her seventies, slow and affectionate, with a soft rasp and a smile you can hear…',
    'write_for_me' => 'Write it for me',
    'writing' => 'Writing…',
    'tips_link' => 'Writing tips',
    'tips_title' => 'What makes a great description',
    'tips' => [
        'Say who they are: an adult\'s age, gender and character.',
        'Describe the sound: deep or bright, smooth or gravelly, breathy or crisp.',
        'Name the accent or dialect: Egyptian, Gulf, Levantine, Modern Standard Arabic, British, Southern American…',
        'Add the attitude and pace: calm, cheeky, menacing, quick, unhurried.',
        'One or two sentences is plenty. Describe an adult character, never a child or a real person.',
    ],
    'shuffle' => 'Surprise me',
    'examples_label' => 'Start from a character',
    'chars_count' => ':count / :max',
    'language_label' => 'Speaks',
    'language_ar' => 'Arabic',
    'language_en' => 'English',
    'gender_label' => 'Voice',
    'gender_any' => 'Any',
    'gender_female' => 'Female',
    'gender_male' => 'Male',
    'gender_neutral' => 'Neutral',
    'line_toggle' => 'Write the line for me',
    'line_toggle_hint' => 'Listen writes a line that suits the character, in the language you chose.',
    'line_label' => 'What the voice says',
    'line_placeholder' => 'Type the line every take will speak…',
    'settings' => 'Shape the voice',
    'settings_hint' => 'Fine-tune how the takes come out. The middle leaves your description as it is.',
    'projection' => 'Projection',
    'projection_low' => 'Soft',
    'projection_high' => 'Powerful',
    'pace' => 'Pace',
    'pace_low' => 'Slow',
    'pace_high' => 'Fast',
    'variety' => 'Take variety',
    'variety_low' => 'Close to my words',
    'variety_high' => 'Bold interpretations',
    'design_button' => 'Design voice',
    'design_again' => 'Design again',
    'designing' => 'Designing…',
    'cost' => ':credits credits',
    'takes_count' => ':count take|:count takes',
    'safety_note' => 'Describe an adult character in your own words. Voices of children and of real people can\'t be designed.',
    'child_hint' => 'Voices of children can\'t be designed. Describe an adult, or just the sound: "a tiny, high, bright voice".',

    // What a design costs, above its button.
    'summary_cost' => 'From your credits',
    'summary_cost_value' => ':credits credits',
    'summary_demo_cost' => 'From your demo characters',
    'summary_demo_cost_value' => ':count characters',
    'summary_takes' => 'You get',
    'summary_takes_value' => ':takes to compare',
    'summary_refund' => 'Only takes that arrive are charged.',

    // While it works.
    'step_reading' => 'Reading your description',
    'step_shaping' => 'Shaping the voice',
    'step_recording' => 'Recording the takes',

    // The takes.
    'takes_title' => 'Pick your favourite take',
    'takes_subtitle' => 'Each take is a voice of its own, speaking the same line.',
    'take_label' => 'Take :n',
    'take_sample' => 'Its own sample',
    'play' => 'Play',
    'pause' => 'Pause',
    'keep' => 'Keep this voice',
    'name_label' => 'Name your voice',
    'name_placeholder' => 'e.g. Grandma Zeinab',
    'save' => 'Save voice',
    'saving' => 'Saving…',
    'cancel' => 'Cancel',
    'kept' => ':name is saved. You will find it at the top of the studio’s voice list.',
    'discard_note' => 'Takes you do not keep are deleted within two hours.',

    // The library.
    'library_title' => 'Your designed voices',
    'library_count' => ':count of :max',
    'month_count' => ':count of :max new voice this month|:count of :max new voices this month',
    'library_empty' => 'The voices you keep appear here, ready in the studio’s voice list.',
    'use_in_studio' => 'Use in studio',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete this voice? It cannot be brought back, but the audio you already made with it stays in your history.',
    'deleted' => 'The voice was deleted.',
    'created_on' => 'Created :date',
    'voice_meta' => ':speaks, made :date',

    // The voice picker in the studio.
    'group_label' => 'Your designed voices',
    'badge' => 'Designed',

    // A plan without it.
    'locked_title' => 'Designing voices comes with :plan',
    'locked_title_any' => 'Designing voices comes with selected plans',
    'locked_text' => 'Try everything here: write a description, pick a character and hear the voices on the stage. Upgrade to design your own.',
    'upgrade_text' => 'Voice Design comes with selected plans. Upgrade to describe any voice, keep the ones you love and use them in every project.',
    'upgrade_points' => [
        'Describe a voice in a sentence, in Arabic or English',
        'Compare up to three takes and keep your favourite',
        'Use your designed voices across the studio, with every style and emotion',
    ],
    'upgrade_cta' => 'Upgrade to :plan',
    'upgrade_cta_any' => 'See plans with Voice Design',

    // The demo.
    'demo_tab' => 'Voice Design',
    'demo_tab_hint' => 'Describe a voice and hear it',
    'demo_voice_label' => 'Designed voice',
    'demo_one_take' => 'The demo makes one take. Plans with Voice Design make up to three and keep them.',
    'demo_cost_note' => 'The line comes out of your demo characters: :count characters.',
    'demo_keep_title' => 'Love this voice?',
    'demo_keep_text' => 'Keep it and use it in the studio with a plan that includes Voice Design.',
    'demo_keep_cta' => 'Keep this voice',
    'demo_open_dashboard' => 'Design it on the dashboard',

    // A line for the takes when none could be written.
    'fallback_line' => 'Hello there! This is my voice. Listen closely, because once you have heard it, you will not forget it.',

    'errors' => [
        'busy' => 'Voice Design is busy right now. Nothing was charged; please try again in a minute.',
        'our_side' => 'We could not design this voice because of a problem on our side. Nothing was charged; please try again.',
        'refused' => 'This voice can\'t be designed: voices of children and of real people aren\'t allowed. Describe an adult character, or just the sound ("a tiny, high, bright voice"), and try again. Nothing was charged.',
        'invalid' => 'This description could not be used. Try describing the voice a little differently.',
        'too_many' => 'You have designed a lot of voices in the last hour. Please try again in :minutes minutes.',
        'library_full' => 'You have kept :max designed voices, the most your plan holds. Delete one to keep another.',
        'month_used' => 'You have kept :count new designed voice this month, the most your plan allows. More come with your next credit month.|You have kept :count new designed voices this month, the most your plan allows. More come with your next credit month.',
        'line_needed' => 'Type the line the voice should say, or let Listen write it for you.',
        'line_too_long' => 'The line can be up to :max characters.',
        'enhance_failed' => 'We could not write a description right now. Please try again, or write your own.',
        'network' => 'The connection dropped. Please try again.',
    ],

    // Characters to start from: shown in both languages; an Arabic page puts the Arabic ones first.
    'examples' => [
        'cairo_grandma' => [
            'name' => 'Cairo Grandmother',
            'description' => 'A warm Egyptian grandmother in her seventies from old Cairo. She speaks Egyptian Arabic slowly and affectionately, with a soft rasp and a smile you can hear.',
            'language' => 'ar',
            'orb' => 'arabic',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/cairo_grandma.mp3).
            'line' => 'يا حبيبي تعالى اقعد جنبي، أنا عاملالك أحلى محشي النهارده، وعندي ليك حكاية هتعجبك أوي.',
        ],
        'gulf_commentator' => [
            'name' => 'Gulf Commentator',
            'description' => 'An electric Gulf Arabic football commentator in his thirties. Fast and breathless, his voice rising to a roar at every chance on goal.',
            'language' => 'ar',
            'orb' => 'dialogue',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/gulf_commentator.mp3).
            'line' => 'يا سلام! تسديدة قوية من خارج المنطقة... جوووول! الله الله على هالهدف يا جماعة، شي ما يتصدّق!',
        ],
        'levant_storyteller' => [
            'name' => 'Levantine Storyteller',
            'description' => 'A gentle Levantine storyteller in his sixties, a hakawati from old Damascus. Warm, low and unhurried, savouring every word of the tale.',
            'language' => 'ar',
            'orb' => 'creator',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/levant_storyteller.mp3).
            'line' => 'كان يا ما كان، بقديم الزمان، كان في تاجر بالشام قلبه أبيض متل التلج... اسمعوا شو صار معه.',
        ],
        'fusha_narrator' => [
            'name' => 'Documentary Narrator',
            'description' => 'A calm, authoritative narrator in his fifties speaking Modern Standard Arabic. Deep, clear and measured, like the voice of a nature documentary.',
            'language' => 'ar',
            'orb' => 'studio_max',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/fusha_narrator.mp3).
            'line' => 'في قلب الصحراء الشاسعة، حيث تشتدّ حرارة النهار وتبرد الليالي، تبدأ رحلة البقاء من جديد.',
        ],
        'evil_ogre' => [
            'name' => 'Evil Ogre',
            'description' => 'A deep, booming voice of a massive ogre in his middle years. Thick, gravelly and theatrical: menacing and absurdly silly at once, with erratic bursts of maniacal energy.',
            'language' => 'en',
            'orb' => 'emotions',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/evil_ogre.mp3).
            'line' => 'Who dares to cross MY bridge? Ha! I\'ll grind your bones for my breakfast... with a little jam on top!',
        ],
        'little_mouse' => [
            'name' => 'Little Mouse',
            'description' => 'A tiny, squeaky voice of a nervous little mouse. Quick, breathy and high-pitched, the words tumbling out in excited little bursts.',
            'language' => 'en',
            'orb' => 'starter',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/little_mouse.mp3).
            'line' => 'Oh! Oh my whiskers, is that cheese? Real cheese? Quick, quick, before the cat wakes up!',
        ],
        'british_ceo' => [
            'name' => 'British CEO',
            'description' => 'A composed British woman in her fifties who runs a large company. Crisp, polished English with warm authority, a measured pace and not a word wasted.',
            'language' => 'en',
            'orb' => 'english',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/british_ceo.mp3).
            'line' => 'Right. We have three priorities this quarter, and I intend to deliver every single one of them. Shall we begin?',
        ],
        'trailer_voice' => [
            'name' => 'Movie Trailer',
            'description' => 'A deep, resonant male narrator in his forties with a smooth, gravelly bass. Dramatic, slow and full of gravitas, every pause loaded with suspense.',
            'language' => 'en',
            'orb' => 'longform',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/trailer_voice.mp3).
            'line' => 'In a world where silence was law... one voice dared to speak. This winter, everything changes.',
        ],
        'southern_woman' => [
            'name' => 'Southern Woman',
            'description' => 'A friendly woman in her sixties from the American South. A warm, unhurried drawl with a sweet, motherly lilt and a hint of mischief.',
            'language' => 'en',
            'orb' => 'places',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/southern_woman.mp3).
            'line' => 'Well, bless your heart, sugar, come on in out of that heat. I just pulled a peach pie out of the oven.',
        ],
        'angry_pirate' => [
            'name' => 'Angry Pirate',
            'description' => 'A gruff old sea captain in his sixties, his voice worn rough by salt and shouting. Growling, impatient and loud, rolling every r.',
            'language' => 'en',
            'orb' => 'pro',
            // What the example says on the Voice Design card (public/audio/voice-design/examples/angry_pirate.mp3).
            'line' => 'Arrr! Who\'s been drinkin\' me rum? Swab that deck, ye scurvy dogs, or ye\'ll walk the plank by sundown!',
        ],
    ],
];
