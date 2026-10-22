<?php

/*
 * Emotions ad: "One line. Every emotion. Every dialect."
 * («سطر واحد. كل المشاعر. كل اللهجات.»)
 *
 * One speaker says one line again and again. A list beside them scrolls
 * through the studio's real options (emotions, vocal tags, styles), then
 * through dialects or accents, and each take follows the highlighted option.
 * The same voice speaks every dialect: any voice can speak any dialect.
 *
 * Arabic cut: a young Arab man, voice Iapetus (the product's "Fahad"); the
 * emotion takes are in Fusha, then the line switches dialect. English cut: a
 * young woman in a hijab, voice Kore (the product's "Daisy"); every take
 * switches emotion AND accent. On screen each
 * is a realistic AI person (no real person), lip-synced to these takes, with
 * the Listen logo.
 *
 * Made by the "Launch ad voices" workflow with ad = emotions-ad:
 *   php artisan tts:showcase --set=ad --ad=emotions-ad --generate
 * into public/audio/emotions-ad/<slug>.wav (only the missing ones).
 */

$fahad = ['language' => 'ar', 'voice' => 'Iapetus', 'speaker' => 'Fahad'];
$daisy = ['language' => 'en', 'voice' => 'Kore', 'speaker' => 'Daisy'];

return [
    'samples' => [

        // ── Arabic cut: every take a new emotion AND a new dialect ──────

        $fahad + ['slug' => 'emo-ar-sa', 'dialect' => 'ar-SA',
            'style' => 'A young Arab man saying the line plainly and evenly, like reading it off a screen. No emotion at all. Authentic Najdi Saudi pronunciation.',
            'text' => 'ما تتخيّل وش صار!'],

        $fahad + ['slug' => 'emo-ar-lb', 'dialect' => 'ar-LB',
            'style' => 'The same young man sharing a big secret: a soft, breathy, intimate whisper the whole way, close to the microphone. Authentic Lebanese.',
            'text' => '<whispers> ما رح تصدّق شو صار!'],

        $fahad + ['slug' => 'emo-ar-eg', 'dialect' => 'ar-EG',
            'style' => 'The same young man who cannot stop laughing: a real burst of laughter first, then he says the line while still laughing. Authentic Cairene Egyptian.',
            'text' => '<laugh> مش هتصدّق اللي حصل!'],

        $fahad + ['slug' => 'emo-ar-iq', 'dialect' => 'ar-IQ',
            'style' => 'The same young man, heartbroken: a real, shaky sob first, then a trembling, tearful voice, slow and broken. Authentic Baghdadi Iraqi.',
            'text' => '<sob> ما تصدّگ شصار…'],

        $fahad + ['slug' => 'emo-ar-ma', 'dialect' => 'ar-MA',
            'style' => 'The same young man, furious: he shouts the line with real anger, loud and sharp, but still clear. Authentic Moroccan Darija.',
            'text' => '<shout> ما غاديش تصدّق شنو وقع!'],

        $fahad + ['slug' => 'emo-ar-ae', 'dialect' => 'ar-AE',
            'style' => 'The same young man, dripping with sarcasm: a short mocking snicker first, then slow, dry and unimpressed. Authentic Emirati.',
            'text' => '<snicker> ما بتصدّق شو صار…'],

        $fahad + ['slug' => 'emo-ar-tn', 'dialect' => 'ar-TN',
            'style' => 'The voice of a big cinema trailer: very deep, slow and dramatic, a long suspenseful pause in the middle. Authentic Tunisian.',
            'text' => 'ما تصدّقش… شنوّة صار.'],

        $fahad + ['slug' => 'emo-ar-kw', 'dialect' => 'ar-KW',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Kuwaiti.',
            'text' => 'ما راح تصدّق شصار!'],

        ['slug' => 'emo-ar-en', 'language' => 'en', 'dialect' => 'en-US',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. General American English.',
            'text' => 'You won\'t believe what happened!'] + $fahad,

        $fahad + ['slug' => 'emo-ar-cta', 'dialect' => 'ar-SA',
            'style' => 'Calm, warm and proud, like the last line of a premium commercial: a short beat after each sentence, a pause before the brand name.',
            'text' => 'سطر واحد. كل المشاعر. كل اللهجات… منصة إسمع.'],

        // ── English cut: every take a new emotion AND a new accent ──────

        $daisy + ['slug' => 'emo-en-us', 'dialect' => 'en-US',
            'style' => 'A young woman saying the line plainly and evenly, like reading it off a screen. No emotion at all. General American.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-gb', 'dialect' => 'en-GB',
            'style' => 'The same woman sharing a big secret: a soft, breathy, intimate whisper the whole way. Standard British English.',
            'text' => '<whispers> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-au', 'dialect' => 'en-AU',
            'style' => 'The same woman who cannot stop laughing: a real burst of laughter first, then the line while still laughing. Broad Australian English.',
            'text' => '<laugh> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-ie', 'dialect' => 'en-IE',
            'style' => 'The same woman, heartbroken: a real, shaky sob first, then a trembling, tearful voice, slow and broken. Irish English.',
            'text' => '<sob> You have no idea what happened…'],

        $daisy + ['slug' => 'emo-en-ny', 'dialect' => 'en-US-NY',
            'style' => 'The same woman, furious: she shouts the line with real anger, loud and sharp, but still clear. New York City English.',
            'text' => '<shout> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-za', 'dialect' => 'en-ZA',
            'style' => 'The same woman, dripping with sarcasm: a short mocking snicker first, then slow, dry and unimpressed. South African English.',
            'text' => '<snicker> You have no idea what happened…'],

        $daisy + ['slug' => 'emo-en-ca', 'dialect' => 'en-CA',
            'style' => 'The voice of a big cinema trailer: deep, slow and dramatic, a long suspenseful pause in the middle. Canadian English.',
            'text' => 'You have no idea… what happened.'],

        $daisy + ['slug' => 'emo-en-in', 'dialect' => 'en-IN',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. A strong, unmistakable Indian English accent and rhythm.',
            'text' => 'You have no idea what happened!'],

        ['slug' => 'emo-en-eg', 'language' => 'ar', 'dialect' => 'ar-EG',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Cairene Egyptian Arabic.',
            'text' => 'مش هتصدّق اللي حصل!'] + $daisy,

        $daisy + ['slug' => 'emo-en-cta', 'dialect' => 'en-US',
            'style' => 'Calm, warm and confident, like the last line of a premium commercial: a short beat after each sentence, a pause before the brand name.',
            'text' => 'One line. Every emotion. Every accent… Listen.'],

    ],
];
