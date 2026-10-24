<?php

/*
 * Prompts film: «كل ما ستسمعه صُنع من النصوص المعروضة، دون أي تعديل — فقط إسمع.»
 * ("Everything you're about to hear was generated from the prompts shown,
 * with no edits — just Listen.")
 *
 * Two films, one Arabic and one English, each in three scenes. Every line is
 * shown on screen exactly as written here, with its delivery tags, while it is
 * heard; the speakers are drawn in dots from their site avatars.
 *
 *   1. A scene between two people (tags: nervous, whisper, clap, sob, gasp, laugh…).
 *   2. One voice, many emotions (a radio host telling a story).
 *   3. One voice, many dialects or accents (a customer-service call).
 *
 * Made by the "Launch ad voices" workflow with ad = prompts-film:
 *   php artisan tts:showcase --set=ad --ad=prompts-film --generate
 * into public/audio/prompts-film/<slug>.wav (only the missing ones).
 */

$alaa = ['language' => 'ar', 'voice' => 'Schedar', 'speaker' => 'Alaa'];
$fatima = ['language' => 'ar', 'voice' => 'Achernar', 'speaker' => 'Fatima'];
$sawsan = ['language' => 'ar', 'voice' => 'Zephyr', 'speaker' => 'Sawsan'];
$raed = ['language' => 'ar', 'voice' => 'Umbriel', 'speaker' => 'Raed'];
$alice = ['language' => 'en', 'voice' => 'Achernar', 'speaker' => 'Alice'];
$caleb = ['language' => 'en', 'voice' => 'Sadaltager', 'speaker' => 'Caleb'];
$miles = ['language' => 'en', 'voice' => 'Iapetus', 'speaker' => 'Miles'];
$ruby = ['language' => 'en', 'voice' => 'Aoede', 'speaker' => 'Ruby'];

return [
    'samples' => [

        // ── Arabic film ─────────────────────────────────────────────────

        // Scene 1: on a film set. A Saudi actress, an Egyptian director.
        $fatima + ['slug' => 'pf-ar-1', 'dialect' => 'ar-SA',
            'style' => 'A young Saudi actress on a film set seconds before the take, nervous and flustered, speaking quickly; then a hushed, panicked whisper. Authentic Najdi Saudi.',
            'text' => '<nervous> لحظة لحظة… <whispers> وين الورقة؟ نسيت أول جملة!'],
        $alaa + ['slug' => 'pf-ar-2', 'dialect' => 'ar-EG',
            'style' => 'An Egyptian film director calling the set to order: firm, loud and commanding, a single clap, then a dramatic "action". Authentic Cairene Egyptian.',
            'text' => '<commanding> يلا يا جماعة، هدوء! <clap> كاميرا… أكشن!'],
        $fatima + ['slug' => 'pf-ar-3', 'dialect' => 'ar-SA',
            'style' => 'The same actress in a heartbreaking scene: her voice trembles and breaks, then a real sob, then barely holding back tears. Authentic Najdi Saudi.',
            'text' => '<trembling> قلت لنفسي… ما راح أبكي. <sob> بس ما قدرت… ما قدرت.'],
        $alaa + ['slug' => 'pf-ar-4', 'dialect' => 'ar-EG',
            'style' => 'The same director, moved: a sharp gasp, then a delighted laugh, then shouting "stop" with joy. Authentic Cairene Egyptian.',
            'text' => '<gasp> <laugh> ستوب! ده أحلى مشهد صوّرناه النهارده!'],
        $fatima + ['slug' => 'pf-ar-5', 'dialect' => 'ar-SA',
            'style' => 'The same actress, suddenly playful: a soft chuckle, a teasing question, then a relieved sigh. Authentic Najdi Saudi.',
            'text' => '<chuckle> يعني… صدّقتني؟ <sigh> حتى أنا صدّقت نفسي.'],

        // Scene 2: on air. A Lebanese radio host tells a story.
        $sawsan + ['slug' => 'pf-ar-6', 'dialect' => 'ar-LB',
            'style' => 'A Lebanese morning-radio host, bright, cheerful and fast, full of energy. Authentic Beiruti Lebanese.',
            'text' => '<cheerful> صباح الخير بيروت! معكن سوسن، وعنّا خبر غريب اليوم…'],
        $sawsan + ['slug' => 'pf-ar-7', 'dialect' => 'ar-LB',
            'style' => 'The same host turning suspenseful: slower and lower with tension, then a secretive whisper. Authentic Beiruti Lebanese.',
            'text' => '<tense> في صوت عم يطلع كل ليلة من سطح البناية… <whispers> وما حدا عرف شو هو.'],
        $sawsan + ['slug' => 'pf-ar-8', 'dialect' => 'ar-LB',
            'style' => 'The same host revealing the answer: a gasp, then she bursts out laughing while she says it. Authentic Beiruti Lebanese.',
            'text' => '<gasp> طلعت… قطّة! <laugh> عم تمشي على أزرار الراديو كل ليلة!'],

        // Scene 3: a call. One agent, three ways of speaking.
        $raed + ['slug' => 'pf-ar-9', 'dialect' => 'ar-XA',
            'style' => 'A polished customer-service agent answering a call: calm, professional, clear Modern Standard Arabic.',
            'text' => '<professional> مرحبًا بك في خدمة العملاء، معك رائد. كيف يمكنني مساعدتك اليوم؟'],
        $raed + ['slug' => 'pf-ar-10', 'dialect' => 'ar-EG',
            'style' => 'The same agent realising the caller is Egyptian and switching to warm, friendly Cairene Egyptian Arabic, smiling as he talks.',
            'text' => '<warm> آه، حضرتك من مصر؟ خلاص نتكلم مصري… طلبك هيوصل بكرة الصبح إن شاء الله.'],

        // ── English film ────────────────────────────────────────────────

        // Scene 1: a podcast about to go live. A British host, an American co-host.
        $alice + ['slug' => 'pf-en-1', 'dialect' => 'en-GB',
            'style' => 'A British podcast host seconds before going live, nervous and flustered; then a hushed, panicked whisper. Standard British English.',
            'text' => '<nervous> Wait, are we live? <whispers> Is the little red light on?'],
        $caleb + ['slug' => 'pf-en-2', 'dialect' => 'en-US',
            'style' => 'An American co-host counting in: firm and commanding, a single clap, then upbeat. General American.',
            'text' => '<commanding> Three, two… <clap> and we\'re rolling!'],
        $alice + ['slug' => 'pf-en-3', 'dialect' => 'en-GB',
            'style' => 'The same host telling a tragic story: her voice breaks, then a real sob, then barely holding it together. Standard British English.',
            'text' => '<trembling> Today\'s story is about my dog, Biscuit… <sob> he ate my notes. Again.'],
        $caleb + ['slug' => 'pf-en-4', 'dialect' => 'en-US',
            'style' => 'The same co-host: a sharp gasp, then a big laugh, then teasing. General American.',
            'text' => '<gasp> Again?! <laugh> That dog has read more of this show than our listeners.'],
        $alice + ['slug' => 'pf-en-5', 'dialect' => 'en-GB',
            'style' => 'The same host, dry and sarcastic: a short snicker, then flat and deadpan. Standard British English.',
            'text' => '<snicker> Thank you, Caleb. <sarcastic> Very supportive.'],

        // Scene 2: on air. An Australian radio host tells a story.
        $miles + ['slug' => 'pf-en-6', 'dialect' => 'en-AU',
            'style' => 'An Australian morning-radio host, bright, cheerful and fast, full of energy. General Australian.',
            'text' => '<cheerful> Morning, Sydney! It\'s Miles, and I\'ve got the strangest story for you today…'],
        $miles + ['slug' => 'pf-en-7', 'dialect' => 'en-AU',
            'style' => 'The same host turning suspenseful: slower and lower with tension, then a secretive whisper. General Australian.',
            'text' => '<tense> Every night at three a.m., someone rings the bell at the old lighthouse… <whispers> and nobody\'s ever there.'],
        $miles + ['slug' => 'pf-en-8', 'dialect' => 'en-AU',
            'style' => 'The same host revealing the answer: a gasp, then he bursts out laughing while he says it. General Australian.',
            'text' => '<gasp> Well, last night we caught him… <laugh> it\'s a seagull! He rings for chips!'],

        // Scene 3: a call. One agent, one voice, three English accents.
        $ruby + ['slug' => 'pf-en-14', 'dialect' => 'en-US',
            'style' => 'A polished customer-service agent answering a call: calm, warm, professional. Clear General American English.',
            'text' => '<professional> Thanks for calling, this is Ruby. How can I help you today?'],
        $ruby + ['slug' => 'pf-en-15', 'dialect' => 'en-GB',
            'style' => 'The same agent, now in a warm, natural Southern British accent, smiling as she talks.',
            'text' => '<warm> Of course. Your parcel will be with you first thing tomorrow morning.'],
        $ruby + ['slug' => 'pf-en-16', 'dialect' => 'en-AU',
            'style' => 'The same agent, now in a relaxed, friendly General Australian accent, grinning as she wraps up the call.',
            'text' => '<cheerful> All sorted! Anything else I can help with? Have a lovely day!'],
    ],
];
