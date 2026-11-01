<?php

/*
 * Saudi campaign: one line, said by Listen's Saudi speakers in their own dialects.
 *
 * The line is written once and each voice reads it with a direction for its dialect
 * (the generator does not rewrite the text). Note: sa-qatifi-abeer was made with
 * Pulcherrima, not Abeer's product voice, so it is a spare and is never labelled Abeer.
 * sa-cta-fatima is the bright campaign's closing line, in Fatima's own product voice
 * (Achernar, سعودية نجدية). Owner-approved (Saudi campaign, 2026-11-01; CTA line 2026-11-01).
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
        ['slug' => 'sa-cta-fatima', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Achernar', 'speaker' => 'Fatima',
            'style' => 'A Saudi woman from Riyadh, natural Najdi delivery, signing off a premium brand commercial: warm, confident and inviting, a small smile in the voice, unhurried, every word clear. Real breathing, no announcer exaggeration.',
            'text' => 'خلّ محتواك يتكلم بالصوت الصح.'],
    ],
];
