<?php

/*
 * Saudi campaign: one line, said by Listen's Saudi speakers in their own dialects.
 *
 * Each speaker is a Listen HD voice with the same name, voice and avatar as in the
 * product. The line is written once; Listen's dialect rewrite puts it into each
 * dialect, exactly as it does for members. Owner-approved (Saudi campaign, 2026-11-01).
 *
 * Made by the "Launch ad voices" workflow (ad: saudi-ad):
 *   php artisan tts:showcase --set=ad --ad=saudi-ad --generate
 * into marketing/saudi-ad/voices/<slug>.wav, with word timings in words.json.
 */

$line = 'خلّ رسالتك توصل بصوت قريب من جمهورك.';
$style = fn (string $who): string => "{$who} recording the line of a premium brand commercial: warm, confident and natural, unhurried, every word clear, a small smile in the voice. Real breathing, no announcer exaggeration.";

return [
    'samples' => [
        ['slug' => 'sa-najdi-sultan', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Algenib', 'speaker' => 'Sultan',
            'style' => $style('A Saudi man from Riyadh, natural Najdi delivery,'), 'text' => $line],
        ['slug' => 'sa-hejazi-maha', 'language' => 'ar', 'dialect' => 'ar-SA-HJ', 'voice' => 'Despina', 'speaker' => 'Maha',
            'style' => $style('A Saudi woman from Jeddah, natural Hejazi delivery,'), 'text' => 'خلّي رسالتك توصل بصوت قريب من جمهورك.'],
        ['slug' => 'sa-southern-lamya', 'language' => 'ar', 'dialect' => 'ar-SA-SO', 'voice' => 'Kore', 'speaker' => 'Lamya',
            'style' => $style('A Saudi woman from Abha, natural southern delivery,'), 'text' => $line],
        ['slug' => 'sa-northern-hamed', 'language' => 'ar', 'dialect' => 'ar-SA-NO', 'voice' => 'Sadachbia', 'speaker' => 'Hamed',
            'style' => $style('A Saudi man from Hail, natural northern Bedouin delivery,'), 'text' => $line],
        ['slug' => 'sa-eastern-nawaf', 'language' => 'ar', 'dialect' => 'ar-SA-EA', 'voice' => 'Achird', 'speaker' => 'Nawaf',
            'style' => $style('A Saudi man from Dammam, natural Eastern Province delivery,'), 'text' => $line],
        ['slug' => 'sa-qatifi-abeer', 'language' => 'ar', 'dialect' => 'ar-SA-QT', 'voice' => 'Pulcherrima', 'speaker' => 'Abeer',
            'style' => $style('A Saudi woman from Qatif, natural Qatifi delivery,'), 'text' => $line],
    ],
];
