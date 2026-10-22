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
 * young woman in a hijab, voice Kore (the product's "Daisy"). On screen each
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

        // ── Arabic cut: one line in Fusha, every emotion ────────────────

        $fahad + ['slug' => 'emo-ar-neutral', 'dialect' => 'ar-XA',
            'style' => 'A young Arab man saying the line plainly and evenly in clear Modern Standard Arabic, like reading it off a screen. No emotion at all.',
            'text' => 'لن تصدّق ما حدث!'],

        $fahad + ['slug' => 'emo-ar-whisper', 'dialect' => 'ar-XA',
            'style' => 'The same young Arab man sharing a big secret: a soft, breathy, intimate whisper the whole way, close to the microphone.',
            'text' => '<whispers> لن تصدّق ما حدث!'],

        $fahad + ['slug' => 'emo-ar-laugh', 'dialect' => 'ar-XA',
            'style' => 'The same young Arab man who cannot stop laughing: a real burst of laughter first, then he says the line while still laughing.',
            'text' => '<laugh> لن تصدّق ما حدث!'],

        $fahad + ['slug' => 'emo-ar-sad', 'dialect' => 'ar-XA',
            'style' => 'The same young Arab man, heartbroken: a real, shaky sob first, then a trembling, tearful voice, slow and broken.',
            'text' => '<sob> لن تصدّق ما حدث…'],

        $fahad + ['slug' => 'emo-ar-angry', 'dialect' => 'ar-XA',
            'style' => 'The same young Arab man, furious: he shouts the line with real anger, loud and sharp, but still clear.',
            'text' => '<shout> لن تصدّق ما حدث!'],

        $fahad + ['slug' => 'emo-ar-sarcastic', 'dialect' => 'ar-XA',
            'style' => 'The same young Arab man, dripping with sarcasm: a short mocking snicker first, then slow, dry and unimpressed, rolling his eyes.',
            'text' => '<snicker> لن تصدّق ما حدث…'],

        $fahad + ['slug' => 'emo-ar-trailer', 'dialect' => 'ar-XA',
            'style' => 'The voice of a big cinema trailer: very deep, slow and dramatic, a long suspenseful pause in the middle, every word heavy with meaning.',
            'text' => 'لن تصدّق… ما حدث.'],

        // ── Arabic cut: the same line, excited, in every dialect ───────

        $fahad + ['slug' => 'emo-ar-sa', 'dialect' => 'ar-SA',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Najdi Saudi.',
            'text' => 'ما تتخيّل وش صار!'],

        $fahad + ['slug' => 'emo-ar-eg', 'dialect' => 'ar-EG',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Cairene Egyptian.',
            'text' => 'مش هتصدّق اللي حصل!'],

        $fahad + ['slug' => 'emo-ar-lb', 'dialect' => 'ar-LB',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Lebanese.',
            'text' => 'ما رح تصدّق شو صار!'],

        $fahad + ['slug' => 'emo-ar-ma', 'dialect' => 'ar-MA',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Moroccan Darija.',
            'text' => 'ما غاديش تصدّق شنو وقع!'],

        $fahad + ['slug' => 'emo-ar-iq', 'dialect' => 'ar-IQ',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Baghdadi Iraqi.',
            'text' => 'ما تصدّگ شصار!'],

        $fahad + ['slug' => 'emo-ar-en', 'language' => 'en', 'dialect' => 'en-US',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. General American English.',
            'text' => 'You won\'t believe what happened!'],

        $fahad + ['slug' => 'emo-ar-cta', 'dialect' => 'ar-XA',
            'style' => 'Calm, warm and proud, like the last line of a premium commercial: a short beat after each sentence, a pause before the brand name.',
            'text' => 'سطر واحد. كل المشاعر. كل اللهجات… منصة إسمع.'],

        // ── English cut: one line, every emotion ────────────────────────

        $daisy + ['slug' => 'emo-en-neutral', 'dialect' => 'en-US',
            'style' => 'A young American woman saying the line plainly and evenly, like reading it off a screen. No emotion at all.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-whisper', 'dialect' => 'en-US',
            'style' => 'The same woman sharing a big secret: a soft, breathy, intimate whisper the whole way, close to the microphone.',
            'text' => '<whispers> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-laugh', 'dialect' => 'en-US',
            'style' => 'The same woman who cannot stop laughing: a real burst of laughter first, then she says the line while still laughing.',
            'text' => '<laugh> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-sad', 'dialect' => 'en-US',
            'style' => 'The same woman, heartbroken: a real, shaky sob first, then a trembling, tearful voice, slow and broken.',
            'text' => '<sob> You have no idea what happened…'],

        $daisy + ['slug' => 'emo-en-angry', 'dialect' => 'en-US',
            'style' => 'The same woman, furious: she shouts the line with real anger, loud and sharp, but still clear.',
            'text' => '<shout> You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-sarcastic', 'dialect' => 'en-US',
            'style' => 'The same woman, dripping with sarcasm: a short mocking snicker first, then slow, dry and unimpressed.',
            'text' => '<snicker> You have no idea what happened…'],

        $daisy + ['slug' => 'emo-en-trailer', 'dialect' => 'en-US',
            'style' => 'The voice of a big cinema trailer: deep, slow and dramatic, a long suspenseful pause in the middle.',
            'text' => 'You have no idea… what happened.'],

        // ── English cut: the same line, excited, in every accent ───────

        $daisy + ['slug' => 'emo-en-us', 'dialect' => 'en-US',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. General American.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-gb', 'dialect' => 'en-GB',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Standard British English.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-au', 'dialect' => 'en-AU',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Broad Australian English.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-in', 'dialect' => 'en-IN',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. A strong, unmistakable Indian English accent and rhythm.',
            'text' => 'You have no idea what happened!'],

        $daisy + ['slug' => 'emo-en-sa', 'language' => 'ar', 'dialect' => 'ar-SA',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Najdi Saudi.',
            'text' => 'ما تتخيّل وش صار!'],

        $daisy + ['slug' => 'emo-en-eg', 'language' => 'ar', 'dialect' => 'ar-EG',
            'style' => 'Bursting with excitement, fast and loud, like telling a friend the best news of the year. Authentic Cairene Egyptian.',
            'text' => 'مش هتصدّق اللي حصل!'],

        $daisy + ['slug' => 'emo-en-cta', 'dialect' => 'en-US',
            'style' => 'Calm, warm and confident, like the last line of a premium commercial: a short beat after each sentence, a pause before the brand name.',
            'text' => 'One line. Every emotion. Every accent… Listen.'],
    ],
];
