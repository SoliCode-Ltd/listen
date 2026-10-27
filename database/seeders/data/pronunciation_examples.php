<?php

/*
 * "Hear the difference" in the studio's pronunciation panel: the same letters
 * read different ways, recorded once with a studio voice by
 * `php artisan tts:showcase --set=pronunciation --generate` into
 * public/audio/pronunciation/{slug}.mp3. Members play them for free.
 *
 * Each clip is one word, said slowly and clearly the way a teacher says it,
 * so the one sound that changes is easy to hear. The words and their
 * meanings on screen are in lang/{ar,en}/ui.php (dashboard.pron.example_*).
 */

$teacherAr = 'A warm, clear Arabic teacher saying one single word slowly and distinctly, like a dictionary recording. '
    .'Pronounce every vowel mark exactly as written. Say only this one word, once, with nothing before or after it.';
$teacherEn = 'A warm, clear English teacher saying one single word slowly and distinctly, like a dictionary recording. '
    .'Say only this one word, once, with nothing before or after it.';

return [
    'samples' => [
        ['slug' => 'ar-ilm', 'voice' => 'ar-001-tutor-3', 'language' => 'ar', 'dialect' => 'ar-XA',
            'style' => $teacherAr.' One syllable: ʿilm (knowledge). A short i, then l and m with no vowel after them: stop on the m, with no -un ending.',
            // A sukun on the last letter too, so the reading stops on the m (no -un ending).
            'text' => 'عِلْمْ'],
        ['slug' => 'ar-alam', 'voice' => 'ar-001-tutor-3', 'language' => 'ar', 'dialect' => 'ar-XA',
            'style' => $teacherAr.' Two short syllables: ʿa-lam (a flag). Both vowels are short a; do not lengthen the first one. Stop on the m, with no ending after it.',
            'text' => 'عَلَم'],
        ['slug' => 'ar-alima', 'voice' => 'ar-001-tutor-3', 'language' => 'ar', 'dialect' => 'ar-XA',
            'style' => $teacherAr.' Three short syllables: ʿa-li-ma (he knew). Start directly on the ʿayn with no sound before it; short a, short i, short a.',
            'text' => 'عَلِمَ'],
        ['slug' => 'en-reed', 'voice' => 'Sadaltager', 'language' => 'en', 'dialect' => 'en-US',
            'style' => $teacherEn.' It rhymes with "need".',
            'text' => 'reed'],
        ['slug' => 'en-red', 'voice' => 'Sadaltager', 'language' => 'en', 'dialect' => 'en-US',
            'style' => $teacherEn.' It is the colour red.',
            'text' => 'red'],
    ],
];
