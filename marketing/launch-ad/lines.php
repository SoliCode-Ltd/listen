<?php

/*
 * Launch ad: "Which of these voices is real?"
 *
 * Every speaker is a Listen HD voice with the same name, voice and avatar as
 * in the product (GeminiTtsProvider's name table, public/images/speakers).
 * Each one insists, in their own dialect or accent, that they are the real
 * one; the narrator reveals that none of them is.
 *
 * Made by the "Launch ad voices" workflow:
 *   php artisan tts:showcase --set=ad --generate
 * into marketing/launch-ad/voices/<slug>.mp3 (only the missing ones).
 *
 * The Saudi stadium commentator reuses the homepage clip
 * public/audio/environments/env-ar-stadium.mp3 (Sultan, stadium environment).
 */

return [
    'samples' => [

        // ── Arabic narrator (Othman, Egyptian) ─────────────────────────

        ['slug' => 'ad-ar-hook', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => 'A charismatic Egyptian presenter talking straight into the camera on a social video. Playful and intriguing: a teasing, slightly lowered voice on the first words, a tiny pause, then a confident, curious question that dares the viewer. Fast, punchy, smiling.',
            'text' => 'خمّن كده… مين فيهم صوته حقيقي؟'],

        ['slug' => 'ad-ar-english', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => 'The same playful Egyptian presenter, a quick curious aside, rising intonation, smiling.',
            'text' => 'طب وبالإنجليزي؟'],

        ['slug' => 'ad-ar-reveal', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => 'The playful Egyptian presenter delivers the big reveal of a social ad. A short suspenseful beat on the first word, then a delighted, almost laughing reveal, then proud and clear on the last sentence.',
            'text' => 'الحقيقة؟ ولا واحد فيهم! كل الأصوات دي اتعملت بإسمع.'],

        ['slug' => 'ad-ar-env', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => 'The playful Egyptian presenter teases one more surprise: a short, knowing beat on the first words, then warm, confident and a little amazed.',
            'text' => 'ومش بس كده… تقدر تحط أي صوت، في أي مكان.'],

        ['slug' => 'ad-ar-cta', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => 'The Egyptian presenter closes the ad: warm, confident and inviting, like the last line of a premium commercial. Clear rhythm, a short pause before the brand name, said with pride.',
            'text' => 'اكتب أي نص، واسمعه بأي لهجة… منصة إسمع.'],

        // ── Arabic speakers ─────────────────────────────────────────────

        ['slug' => 'ad-ar-fahad', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Iapetus', 'speaker' => 'Fahad',
            'style' => 'A proud, charismatic young man from Riyadh, amused that anyone could doubt him. He laughs once, then speaks with big confident energy, like a popular Saudi creator talking to his followers. Natural Najdi delivery with real breathing.',
            'text' => 'أنا؟ <laugh> أكيد حقيقي! وش تبي أكثر من كذا؟'],

        ['slug' => 'ad-ar-sumaya', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Callirrhoe', 'speaker' => 'Sumaya',
            'style' => 'A witty, warm woman from Cairo teasing her friends. Quick and playful, then she bursts into a genuine, contagious laugh at the end.',
            'text' => 'لا يا حبيبي، أنا اللي حقيقية! حد بيضحك كده؟ <laugh>'],

        ['slug' => 'ad-ar-sawsan', 'language' => 'ar', 'dialect' => 'ar-LB', 'voice' => 'Zephyr', 'speaker' => 'Sawsan',
            'style' => 'A stylish woman from Beirut, calm and knowing, a little mischievous, as if sharing a secret. Melodic Lebanese intonation, soft smile in the voice.',
            'text' => 'بصراحة؟ ما حدا فيكن رح يعرف يفرّق بيناتنا.'],

        ['slug' => 'ad-ar-nawaf', 'language' => 'ar', 'dialect' => 'ar-AE', 'voice' => 'Achird', 'speaker' => 'Nawaf',
            'style' => 'A relaxed, confident Emirati man, unhurried and proud, with a friendly grin. Natural Emirati rhythm.',
            'text' => 'أنا إماراتي مية بالمية… شو تبون بعد؟'],

        ['slug' => 'ad-ar-aisha', 'language' => 'ar', 'dialect' => 'ar-MA', 'voice' => 'Erinome', 'speaker' => 'Aisha',
            'style' => 'A joyful, lively young woman from Casablanca, bright and quick, proud of her Darija. Authentic Moroccan pronunciation and rhythm.',
            'text' => 'واش كتسمعني مزيان؟ هادي دارجة مغربية ديال بصح!'],

        // ── English speakers ────────────────────────────────────────────

        ['slug' => 'ad-en-daisy', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Kore', 'speaker' => 'Daisy',
            'style' => 'A bubbly American woman, amused and a little sassy, smiling the whole time.',
            'text' => 'Real? Honey, I am as American as apple pie!'],

        ['slug' => 'ad-en-theodore', 'language' => 'en', 'dialect' => 'en-GB', 'voice' => 'Charon', 'speaker' => 'Theodore',
            'style' => 'A distinguished British gentleman with dry wit. A soft chuckle first, then calm, polite and very sure of himself.',
            'text' => '<chuckle> I can assure you, I am terribly British.'],

        ['slug' => 'ad-en-finn', 'language' => 'en', 'dialect' => 'en-AU', 'voice' => 'Fenrir', 'speaker' => 'Finn',
            'style' => 'A laid-back, cheeky Australian bloke talking to his mates, broad and friendly, grinning.',
            'text' => 'Fair dinkum, mate. Real as they come!'],

        ['slug' => 'ad-en-luna', 'language' => 'en', 'dialect' => 'en-IN', 'voice' => 'Despina', 'speaker' => 'Luna',
            'style' => 'A cheerful content creator from Mumbai with a strong, unmistakable Indian English accent, rhythm and intonation (not American or British). Bright, playful, mock-offended.',
            'text' => 'Arre yaar, obviously I am real! Just listen!'],

        ['slug' => 'ad-en-charlie', 'language' => 'en', 'dialect' => 'en-NG', 'voice' => 'Alnilam', 'speaker' => 'Charlie',
            'style' => 'A big-hearted Nigerian man from Lagos, loud, proud and laughing with his friends. Strong Nigerian English accent.',
            'text' => 'Abeg, na me be the realest one for here o!'],

        // ── English narrator (Nathan, American), for the English cut ────

        ['slug' => 'ad-en-hook', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Orus', 'speaker' => 'Nathan',
            'style' => 'A charismatic presenter talking straight into the camera on a social video. Playful and intriguing: lowered, teasing voice at first, a tiny pause, then a confident question that dares the viewer.',
            'text' => 'Okay… which one of these voices is real?'],

        ['slug' => 'ad-en-arabic', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Orus', 'speaker' => 'Nathan',
            'style' => 'The same presenter, a quick curious aside, rising intonation, smiling.',
            'text' => 'And in Arabic?'],

        ['slug' => 'ad-en-reveal', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Orus', 'speaker' => 'Nathan',
            'style' => 'The presenter delivers the big reveal of a social ad: a short suspenseful beat on the first words, then a delighted reveal, then proud and clear.',
            'text' => 'The truth? None of them. Every voice here was made with Listen.'],

        ['slug' => 'ad-en-env', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Orus', 'speaker' => 'Nathan',
            'style' => 'The presenter teases one more surprise: a short, knowing beat on the first words, then warm, confident and a little amazed.',
            'text' => 'And that\'s not all… put any voice, anywhere.'],

        ['slug' => 'ad-en-cta', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Orus', 'speaker' => 'Nathan',
            'style' => 'The presenter closes the ad: warm, confident and inviting, like the last line of a premium commercial, a short pause before the brand name.',
            'text' => 'Type anything, and hear it in any accent… Listen.'],
    ],
];
