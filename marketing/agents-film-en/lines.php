<?php

/*
 * The agents film, English cut (marketing/agents-film-en): four short phone calls, four accents.
 *
 * The same four calls as the Arabic cut (marketing/agents-film/lines.php): a customer asks, the
 * business's agent answers, the way a call to a real front desk sounds. Every voice is a Listen
 * HD voice with the name it has in the product. The businesses are samples.
 *
 * Made by the "Launch ad voices" workflow (ad: agents-film-en) into voices/<slug>.wav, with word
 * timings in voices/words.json. The film reads both (agents-film/build_audio.py agents-film-en).
 */

$phone = 'This is one side of a real phone call, recorded on a good line: natural, conversational, never read from a script. Real breathing, small natural pauses, the rhythm of someone thinking as they speak.';

return [
    'samples' => [

        // ── United States: a shipping company (Maya answers) ───────────

        ['slug' => 'af-us-ask', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Puck', 'speaker' => 'Dylan',
            'style' => $phone.' A polite American man in his thirties calling a shipping company about a late package: a little concerned, not angry, speaking plainly. General American accent.',
            'text' => 'Hi, my package was supposed to arrive today. Do you know where it is?'],

        ['slug' => 'af-us-agent', 'language' => 'en', 'dialect' => 'en-US', 'voice' => 'Callirrhoe', 'speaker' => 'Maya',
            'style' => $phone.' A warm, capable customer-service agent at an American shipping company. She greets him with a smile in her voice, reassures him calmly with the facts, then offers help with a light, friendly lift on the question. Confident and kind, professional but human. General American accent.',
            'text' => 'Hi there, thanks for calling. Your package left with the driver about an hour ago, and it will reach you before six. Want me to text you the tracking link?'],

        // ── United Kingdom: a car showroom (Oliver answers) ────────────

        ['slug' => 'af-gb-ask', 'language' => 'en', 'dialect' => 'en-GB', 'voice' => 'Kore', 'speaker' => 'Daisy',
            'style' => $phone.' A confident English woman calling a car showroom to book a test drive: friendly, direct, a little excited. Natural standard British accent.',
            'text' => 'Hello, I would like to book a test drive for Saturday, please.'],

        ['slug' => 'af-gb-agent', 'language' => 'en', 'dialect' => 'en-GB', 'voice' => 'Achird', 'speaker' => 'Oliver',
            'style' => $phone.' A courteous, welcoming host at an English car showroom. Genuinely pleased in the greeting, then clear and helpful as he offers the time, a real question when he asks if it suits her, and polite ease asking for her details. Natural standard British accent, relaxed pace.',
            'text' => 'Of course, lovely to hear from you. We have a slot on Saturday at five in the afternoon. Would that suit? I will just need your name and a contact number.'],

        // ── Australia: a hotel (Isla answers) ──────────────────────────

        ['slug' => 'af-au-ask', 'language' => 'en', 'dialect' => 'en-AU', 'voice' => 'Fenrir', 'speaker' => 'Finn',
            'style' => $phone.' A relaxed Australian man calling a hotel to book a room for the weekend: easygoing and friendly. Natural Australian accent.',
            'text' => 'Hi, I am after a room for two, from Thursday through Saturday.'],

        ['slug' => 'af-au-agent', 'language' => 'en', 'dialect' => 'en-AU', 'voice' => 'Gacrux', 'speaker' => 'Isla',
            'style' => $phone.' A gracious front-desk host at a seaside hotel in Australia. A bright, genuine welcome, then she describes the room with quiet pride, says the price simply, and asks to confirm with a warm, inviting lift. Natural Australian accent, unhurried.',
            'text' => 'No worries at all. We have a sea view room free on those dates, at one forty a night. Shall I book that in for you?'],

        // ── Ireland: a property office (Vincent answers) ───────────────

        ['slug' => 'af-ie-ask', 'language' => 'en', 'dialect' => 'en-IE', 'voice' => 'Leda', 'speaker' => 'Sienna',
            'style' => $phone.' A friendly Irish woman calling a property office to see a flat: light, polite, a little hopeful. Natural Irish accent with its melody.',
            'text' => 'Hi there, I would love to arrange a viewing of the flat in Ranelagh, tomorrow if possible.'],

        ['slug' => 'af-ie-agent', 'language' => 'en', 'dialect' => 'en-IE', 'voice' => 'Umbriel', 'speaker' => 'Vincent',
            'style' => $phone.' An easygoing, professional agent at an Irish property office. A warm welcome, a quick, helpful "sure", a real question about the time, then relaxed and reassuring as he asks for her details. Natural Irish accent with its melody.',
            'text' => 'You are very welcome. Sure, how does eleven tomorrow morning sound? I will just take your name and number to confirm it.'],
    ],
];
