<?php

/*
 * The agents film (marketing/agents-film): four short phone calls, four dialects.
 *
 * Every voice is a Listen HD voice with the name it has in the product (the voice library's
 * speakers). In each call a customer asks and the business's agent answers, the way a call to
 * a real front desk sounds: unhurried, warm, a breath before the answer, never read out.
 * The businesses are samples: a shipping company, a car showroom, a hotel, a property office.
 *
 * Made by the "Launch ad voices" workflow (ad: agents-film):
 *   php artisan tts:showcase --set=ad --ad=agents-film --generate
 * into marketing/agents-film/voices/<slug>.wav (only the missing ones: delete a WAV to remake
 * it), with word timings in voices/words.json. The film reads both (build_audio.py).
 */

$phone = 'This is one side of a real phone call, recorded on a good line: natural, conversational, never read from a script. Real breathing, small natural pauses, the rhythm of someone thinking as they speak.';

return [
    'samples' => [

        // ── Egypt: a shipping company (Sumaya answers) ─────────────────

        ['slug' => 'af-eg-ask', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Puck', 'speaker' => 'Othman',
            'style' => $phone.' A polite Cairo man in his thirties calling a shipping company about a late parcel: a little concerned, not angry, speaking plainly. Everyday Cairene Arabic.',
            'text' => 'مساء الخير، شحنتي كان المفروض توصل النهارده. هي فين دلوقتي؟'],

        ['slug' => 'af-eg-agent', 'language' => 'ar', 'dialect' => 'ar-EG', 'voice' => 'Callirrhoe', 'speaker' => 'Sumaya',
            'style' => $phone.' A warm, capable customer-service agent at a Cairo shipping company. She greets him with a smile in her voice, reassures him calmly with the facts, then offers help with a light, friendly lift on the question. Confident and kind, professional but human. Everyday Cairene Arabic.',
            'text' => 'مساء النور يا فندم. شحنتك خرجت مع المندوب من ساعة، وهتوصل لحضرتك قبل الساعة ستة. تحب أبعتلك رابط التتبع؟'],

        // ── Saudi Arabia: a car showroom (Fahad answers) ───────────────

        ['slug' => 'af-sa-ask', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Kore', 'speaker' => 'Rawaa',
            'style' => $phone.' A confident Saudi woman from Riyadh calling a car showroom to book a test drive: friendly, direct, a little excited. Natural Najdi Arabic.',
            'text' => 'السلام عليكم، أبي أحجز تجربة قيادة يوم السبت.'],

        ['slug' => 'af-sa-agent', 'language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Iapetus', 'speaker' => 'Fahad',
            'style' => $phone.' A courteous, welcoming sales host at a Riyadh car showroom. Generous Saudi hospitality in the greeting, then clear and helpful as he offers the time, a real question when he asks if it suits her, and polite ease asking for her details. Natural Najdi Arabic, relaxed pace.',
            'text' => 'وعليكم السلام، حياك الله. عندنا موعد السبت الساعة خمس العصر، يناسبك؟ بس أحتاج اسمك الكريم ورقم جوالك.'],

        // ── Kuwait: a hotel (Fatima answers) ───────────────────────────

        ['slug' => 'af-kw-ask', 'language' => 'ar', 'dialect' => 'ar-KW', 'voice' => 'Achird', 'speaker' => 'Nawaf',
            'style' => $phone.' A relaxed Kuwaiti man calling a hotel to book a room for the weekend: easygoing and friendly. Natural Kuwaiti Arabic.',
            'text' => 'هلا، أبي أحجز غرفة حق شخصين، من الخميس لين السبت.'],

        ['slug' => 'af-kw-agent', 'language' => 'ar', 'dialect' => 'ar-KW', 'voice' => 'Achernar', 'speaker' => 'Fatima',
            'style' => $phone.' A gracious front-desk host at a seaside hotel in Kuwait. A bright, genuine welcome, then she describes the room with quiet pride, says the price simply, and asks to confirm with a warm, inviting lift. Natural Kuwaiti Arabic, unhurried.',
            'text' => 'هلا والله، حياك. عندنا غرفة تطل على البحر بهالتاريخ، بخمسة وأربعين دينار بالليلة. أأكد لك الحجز؟'],

        // ── Lebanon: a property office (Raed answers) ──────────────────

        ['slug' => 'af-lb-ask', 'language' => 'ar', 'dialect' => 'ar-LB', 'voice' => 'Zephyr', 'speaker' => 'Sawsan',
            'style' => $phone.' A friendly woman from Beirut calling a property office to see a flat: light, polite, a little hopeful. Melodic Lebanese Arabic.',
            'text' => 'مرحبا، بدّي آخد موعد شوف الشقة يلّي بالأشرفية، بكرا إذا بيصير.'],

        ['slug' => 'af-lb-agent', 'language' => 'ar', 'dialect' => 'ar-LB', 'voice' => 'Umbriel', 'speaker' => 'Raed',
            'style' => $phone.' An easygoing, professional agent at a Beirut property office. A warm welcome, a quick, helpful "sure", a real question about the time, then relaxed and reassuring as he asks for her details. Natural Lebanese Arabic with its melody.',
            'text' => 'أهلا وسهلا فيكي. أكيد، بكرا الساعة حدعش الصبح منيح؟ وبس بدّي اسمك ورقمك لأكّد الموعد.'],
    ],
];
