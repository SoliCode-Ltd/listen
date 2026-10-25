<?php

/*
 * TikTok ad «الحلة» (the cooking pot): a mum tells her son off for eating
 * from the pot before lunch. The same line in four emotions, then in other
 * dialects in the same mum's voice (one voice, any dialect: each dialect has
 * its own word for the pot), then a two-line exchange with the son.
 *
 * Main ad and its 12-second cut: Nada (Egyptian mum) and Karim (son).
 * Gulf version for Saudi Arabia and the UAE: Duaa (Saudi mum) and Nawaf (son).
 *
 *   php artisan tts:showcase --set=ad --ad=tiktok-pot --generate
 * into public/audio/tiktok-pot/<slug>.wav. The film is in marketing/tiktok-pot/film.
 */

$eg = ['language' => 'ar', 'voice' => 'ar-eg-podcaster-4', 'speaker' => 'Nada'];
$sa = ['language' => 'ar', 'voice' => 'Gacrux', 'speaker' => 'Duaa'];
$mum = 'An Egyptian mum in her forties in her kitchen, a warm, expressive, very natural voice, like a real family moment filmed on a phone. ';
$umm = 'A Saudi mum in her forties in her kitchen, a warm, expressive, very natural voice, like a real family moment filmed on a phone. ';

return [
    'samples' => [

        // ── Main ad: the Egyptian mum ───────────────────────────────────

        ['slug' => 'eg-angry', 'dialect' => 'ar-EG'] + $eg + [
            'style' => $mum.'She has just caught her son with a spoon in the pot: loud, exasperated, half laughing, the classic Egyptian mum telling off, punchy rhythm, rising on the last words.',
            'text' => 'أنا قايلالك ألف مرة… ما تاكلش من الحلة قبل الغدا!'],

        ['slug' => 'eg-whisper', 'dialect' => 'ar-EG'] + $eg + [
            'style' => $mum.'Whispering closely, conspiratorial and very quiet, as if the guests are in the next room, every word breathy and clear.',
            'text' => 'أنا قايلالك ألف مرة… ما تاكلش من الحلة قبل الغدا!'],

        ['slug' => 'eg-laughcry', 'dialect' => 'ar-EG'] + $eg + [
            'style' => $mum.'Laughing and crying at the same time, overwhelmed and dramatic, sobbing words between bursts of laughter, comic and exaggerated.',
            'text' => '<laugh> أنا قايلالك ألف مرة… ما تاكلش من الحلة قبل الغدا!'],

        ['slug' => 'eg-plead', 'dialect' => 'ar-EG'] + $eg + [
            'style' => $mum.'Pleading sweetly, almost begging, soft and stretched, a tired mum asking nicely for the hundredth time.',
            'text' => 'أنا قايلالك ألف مرة… ما تاكلش من الحلة قبل الغدا!'],

        // The same mum's voice in other dialects (each says the pot its own way).
        ['slug' => 'eg-in-saudi', 'dialect' => 'ar-SA'] + $eg + [
            'style' => 'The same mum, now speaking natural Najdi Saudi Arabic as a woman from Riyadh, exasperated and half laughing.',
            'text' => 'قلت لك ألف مرة… لا تاكل من القدر قبل الغدا!'],

        ['slug' => 'eg-in-emirati', 'dialect' => 'ar-AE'] + $eg + [
            'style' => 'The same mum, now speaking natural Emirati Arabic as a woman from Dubai, exasperated and half laughing.',
            'text' => 'قايلتلك ألف مرة… لا تاكل من الجدر قبل الغدا!'],

        ['slug' => 'eg-in-lebanese', 'dialect' => 'ar-LB'] + $eg + [
            'style' => 'The same mum, now speaking natural Beirut Lebanese Arabic, exasperated and half laughing, sing-song.',
            'text' => 'قلتلك ألف مرة… ما تاكل من الطنجرة قبل الغدا!'],

        ['slug' => 'eg-in-fusha', 'dialect' => 'ar-XA'] + $eg + [
            'style' => 'The same mum, now in grand, formal Modern Standard Arabic like a classical drama narrator, comically solemn.',
            'text' => 'قلتُ لكَ ألفَ مرة… لا تأكلْ من القِدرِ قبلَ الغداء!'],

        // The son answers, the mum has the last word.
        ['slug' => 'eg-son', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'ar-eg-training-8', 'speaker' => 'Karim',
            'style' => 'A young Cairo man caught with a spoon in his mouth, innocent and cheeky, mouth half full, pleading his case.',
            'text' => 'يا ماما دي معلقة واحدة بس!'],

        ['slug' => 'eg-mum-last', 'dialect' => 'ar-EG'] + $eg + [
            'style' => $mum.'Outraged and comic, looking into the empty pot: a sharp disbelieving gasp, then the punchline.',
            'text' => '<gasp> واحدة؟! الحلة فاضية!'],

        // ── Gulf version: the Saudi mum ─────────────────────────────────

        ['slug' => 'sa-angry', 'dialect' => 'ar-SA'] + $sa + [
            'style' => $umm.'She has just caught her son with a spoon in the pot: loud, exasperated, half laughing, natural Najdi delivery, rising on the last words.',
            'text' => 'قلت لك ألف مرة… لا تاكل من القدر قبل الغدا!'],

        ['slug' => 'sa-whisper', 'dialect' => 'ar-SA'] + $sa + [
            'style' => $umm.'Whispering closely, conspiratorial and very quiet, as if the guests are in the majlis next door, breathy and clear.',
            'text' => 'قلت لك ألف مرة… لا تاكل من القدر قبل الغدا!'],

        ['slug' => 'sa-laughcry', 'dialect' => 'ar-SA'] + $sa + [
            'style' => $umm.'Laughing and crying at the same time, overwhelmed and dramatic, comic and exaggerated.',
            'text' => '<laugh> قلت لك ألف مرة… لا تاكل من القدر قبل الغدا!'],

        ['slug' => 'sa-plead', 'dialect' => 'ar-SA'] + $sa + [
            'style' => $umm.'Pleading sweetly, almost begging, soft and stretched, a tired mum asking nicely for the hundredth time.',
            'text' => 'قلت لك ألف مرة… لا تاكل من القدر قبل الغدا!'],

        ['slug' => 'sa-in-egyptian', 'dialect' => 'ar-EG'] + $sa + [
            'style' => 'The same mum, now speaking natural Cairene Egyptian Arabic, exasperated and half laughing.',
            'text' => 'أنا قايلالك ألف مرة… ما تاكلش من الحلة قبل الغدا!'],

        ['slug' => 'sa-in-emirati', 'dialect' => 'ar-AE'] + $sa + [
            'style' => 'The same mum, now speaking natural Emirati Arabic as a woman from Dubai, exasperated and half laughing.',
            'text' => 'قايلتلك ألف مرة… لا تاكل من الجدر قبل الغدا!'],

        ['slug' => 'sa-in-kuwaiti', 'dialect' => 'ar-KW'] + $sa + [
            'style' => 'The same mum, now speaking natural Kuwaiti Arabic, exasperated and half laughing.',
            'text' => 'گتلك ألف مرة… لا تاكل من الجدر گبل الغدا!'],

        ['slug' => 'sa-in-fusha', 'dialect' => 'ar-XA'] + $sa + [
            'style' => 'The same mum, now in grand, formal Modern Standard Arabic like a classical drama narrator, comically solemn.',
            'text' => 'قلتُ لكَ ألفَ مرة… لا تأكلْ من القِدرِ قبلَ الغداء!'],

        ['slug' => 'sa-son', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Achird', 'speaker' => 'Nawaf',
            'style' => 'A young Riyadh man caught with a spoon in his mouth, innocent and cheeky, mouth half full, pleading his case.',
            'text' => 'يمه بس ملعقة وحدة!'],

        ['slug' => 'sa-mum-last', 'dialect' => 'ar-SA'] + $sa + [
            'style' => $umm.'Outraged and comic, looking into the empty pot: a sharp disbelieving gasp, then the punchline.',
            'text' => '<gasp> وحدة؟! القدر فاضي!'],
    ],
];
