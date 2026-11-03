<?php

// The Agents page for visitors: what a voice agent does for a business, shown working.
return [
    'meta_title' => 'Voice agents that answer your customers in their dialect',
    'meta_description' => 'A voice agent for your business: it answers calls on your website, a link or a QR code, in Egyptian, Saudi, Emirati, Levantine, Moroccan or English, day and night. Ready in two minutes.',

    'hero_title' => 'A voice that answers your customers, in their dialect',
    'hero_text' => 'Your agent answers questions from your prices and hours, takes names and numbers, and books visits. On your website, a link or a QR code, day and night.',
    'hero_cta' => 'Create my agent',
    'hero_cta_member' => 'Open my agents',
    'hero_note' => 'Your first two minutes of test calls are free.',
    'hero_watch' => 'Watch a call',

    // The call in the hero.
    'call_live' => 'On a call',
    'call_you' => 'Caller',
    'dialects_label' => 'Hear the same call in another dialect',
    'scenes' => [
        'english' => ['label' => 'English', 'agent' => 'Sara', 'business' => 'Cairo Smiles Clinic', 'avatar' => 'en-olivia',
            'lines' => [
                ['agent', 'Hi, thanks for calling Cairo Smiles Clinic, this is Sara. How can I help?'],
                ['caller', 'How much is teeth whitening?'],
                ['agent', 'Whitening is 1,500 pounds and takes about an hour. Shall I book you in?'],
                ['caller', 'Yes please, Saturday afternoon.'],
            ],
            'chip' => 'Booked: Saturday, 4 pm',
        ],
        'egyptian' => ['label' => 'Egyptian', 'agent' => 'سارة', 'business' => 'عيادة كايرو سمايلز', 'avatar' => 'ar-sumaya',
            'lines' => [
                ['agent', 'أهلًا بيك في عيادة كايرو سمايلز، معاك سارة. أقدر أساعدك إزاي؟', 'Welcome to Cairo Smiles Clinic, this is Sara. How can I help?'],
                ['caller', 'عايز أعرف سعر تبييض الأسنان', 'I want to know the price of teeth whitening.'],
                ['agent', 'التبييض بـ1500 جنيه والجلسة حوالي ساعة. تحب أحجزلك ميعاد؟', 'Whitening is 1,500 pounds, about an hour. Shall I book you in?'],
                ['caller', 'أيوه، السبت بعد الضهر', 'Yes, Saturday afternoon.'],
            ],
            'chip' => 'Booked: Saturday, 4 pm',
        ],
        'saudi' => ['label' => 'Saudi', 'agent' => 'نواف', 'business' => 'عقارات الرياض', 'avatar' => 'ar-nawaf',
            'lines' => [
                ['agent', 'هلا والله، معك نواف من عقارات الرياض. وش تبي تعرف؟', 'Hello, this is Nawaf from Riyadh Homes. What would you like to know?'],
                ['caller', 'أبي شقة ثلاث غرف شمال الرياض', 'I want a three-bedroom flat in north Riyadh.'],
                ['agent', 'عندنا خيارات تبدأ من مليون ومية ألف. أرتب لك زيارة بكرة؟', 'We have options from 1.1 million. Shall I arrange a visit tomorrow?'],
                ['caller', 'إيه، بعد العصر', 'Yes, in the afternoon.'],
            ],
            'chip' => 'Viewing: tomorrow afternoon',
        ],
        'emirati' => ['label' => 'Emirati', 'agent' => 'سلطان', 'business' => 'مطعم البحر', 'avatar' => 'ar-sultan',
            'lines' => [
                ['agent', 'هلا، معاك سلطان من مطعم البحر. شو تبغي تطلب؟', 'Hi, this is Sultan from Al Bahr Restaurant. What would you like to order?'],
                ['caller', 'عندكم توصيل للمرسى الحين؟', 'Do you deliver to the Marina now?'],
                ['agent', 'إي نعم، يوصلك خلال نص ساعة، والتوصيل مجاني فوق مية درهم.', 'Yes, within half an hour, and delivery is free over 100 dirhams.'],
                ['caller', 'زين، أبا سمك مشوي', 'Great, I would like grilled fish.'],
            ],
            'chip' => 'Order: grilled fish, Marina',
        ],
        'levantine' => ['label' => 'Levantine', 'agent' => 'لينا', 'business' => 'صالون الياسمين', 'avatar' => 'ar-lubna',
            'lines' => [
                ['agent', 'أهلين، معك لينا من صالون الياسمين. كيف فيني ساعدك؟', 'Hello, this is Lina from Jasmine Salon. How can I help?'],
                ['caller', 'بدي احجز قص وسشوار بكرا', 'I would like a cut and blow-dry tomorrow.'],
                ['agent', 'أكيد، في عنا موعد الساعة 11 أو الساعة 4. شو بيناسبك؟', 'Sure, we have 11 or 4 o\'clock. Which suits you?'],
                ['caller', 'الساعة أربعة لو سمحتي', 'Four o\'clock, please.'],
            ],
            'chip' => 'Booked: tomorrow, 4 pm',
        ],
        'moroccan' => ['label' => 'Moroccan', 'agent' => 'هند', 'business' => 'متجر الأطلس', 'avatar' => 'ar-maha',
            'lines' => [
                ['agent', 'السلام، معاك هند من متجر الأطلس. كيفاش نقدر نعاونك؟', 'Hello, this is Hind from Atlas Store. How can I help?'],
                ['caller', 'بغيت نعرف واش كاين التوصيل لطنجة', 'I want to know if you deliver to Tangier.'],
                ['agent', 'إيه كاين، كيوصل فجوج أيام، والتوصيل فابور فوق 300 درهم.', 'Yes, it arrives in two days, and delivery is free over 300 dirhams.'],
                ['caller', 'مزيان، غادي نطلب دابا', 'Great, I will order now.'],
            ],
            'chip' => 'New order: Tangier',
        ],
    ],
    'scene_order' => ['english', 'egyptian', 'saudi', 'emirati', 'levantine', 'moroccan'],

    // Four steps.
    'steps_title' => 'Ready in two minutes, without a developer',
    'steps_text' => 'Four short steps. You can change everything later, and every change reaches your customers straight away.',
    'steps' => [
        ['Tell it about your business', 'Paste your website, or write your prices and hours. Your agent reads them and writes itself.'],
        ['Pick its voice', 'Hear real voices from your customers\' region, and choose the one that sounds like your business.'],
        ['Talk to it', 'Ask what your customers ask. If an answer is wrong, correct it once and it remembers.'],
        ['Put it live', 'Share its link, print its QR code, or add one line to your website.'],
    ],

    // Where it answers.
    'flow_builders' => 'Works with any website builder: a ready plugin, or one line your web person pastes once.',
    'widget_label' => 'Talk to us',

    // What it does on calls.
    'does' => [
        ['Answers from your facts', 'Prices, hours, branches, delivery and policies. It never invents an answer.'],
        ['Takes name and number', 'When a caller wants a call back, it asks, reads the number back and saves it for you.'],
        ['Books visits and orders', 'It finds a good time with the caller and hands your team a clear note.'],
        ['Knows when to pass on', 'When someone is upset or needs a person, it says your team will call them.'],
    ],
    'honest' => 'It always says it is an AI assistant, and it never asks for card numbers or passwords.',

    'how_step_of' => 'Step :n of :total',
    'talk_live' => 'Talk to Sara now',
    'talk_live_note' => 'A real call with Listen\'s own agent. Allow the microphone when asked.',
    'link_demo' => 'listenai.studio/talk/cairo-smiles',

    // The bento: a call that ends with something done, and what the owner sees.
    'bento_title' => 'The receptionist who never misses a call',
    'bento_text' => 'It answers every caller in their dialect, saves what matters and hands your team a clear note.',
    'bento_live' => 'Live',
    'bento_lines' => [
        ['caller', 'My order is three days late.'],
        ['agent', 'I\'m sorry about the delay. Could I have your order number?'],
        ['caller', 'It\'s 4812.'],
        ['agent', 'Thank you. I\'ve noted it, and our team will call you within the hour.'],
    ],
    'bento_chip' => 'Callback saved · order 4812',
    'bento_call_caption' => 'Every call ends with something done: a booking, an order, or a callback for your team.',
    'bento_stats_title' => 'This month',
    'bento_stats' => [['128', 'Calls'], ['342', 'Minutes'], ['46', 'Customers to call back']],
    'bento_stats_caption' => 'Every call with a short summary, what was said, and who to call back.',

    // One agent, every channel.
    'flow_title' => 'One agent, wherever your customers find you',
    'flow_text' => 'The same agent answers on your website, its link and its QR code. Change it once and it changes everywhere.',
    'flow_sources' => ['Call button on your website', 'Its own link', 'QR code on paper', 'Plugin for your website builder'],
    'flow_agent' => 'Listen Agent',
    'flow_agent_sub' => 'In your customers\' dialect',
    'flow_lines' => [
        ['caller', 'Do you have a branch in Maadi?'],
        ['agent', 'Yes, our Road 9 branch is open until midnight. Shall I book you a table?'],
    ],

    // The homepage's section.
    'home_title' => 'A voice agent for your business',
    'home_text' => 'It answers your customers in their dialect, on your website, a link or a QR code, day and night. Ready in two minutes.',
    'home_cta' => 'Meet Agents',
    'home_talk' => 'Talk to our agent',

    // Every call written down.
    'calls_title' => 'Every call, written down',
    'calls_text' => 'Read a short summary of each call, open what was said, and call back the customers who left their number in one tap.',
    'calls_demo' => [
        ['Booked a whitening session for Saturday at 4 pm.', 'Website', '2 min'],
        ['Asked about delivery to Tangier, then placed an order.', 'Link', '1 min'],
        ['Wants a call back about braces prices.', 'QR code', '3 min'],
    ],
    'calls_customer' => 'Ahmed',
    'calls_phone' => '0101 234 5678',
    'calls_call' => 'Call',

    // Pricing.
    'price_title' => 'In every plan, no separate bill',
    'price_text' => 'Calls use your plan\'s credits, like your voice-overs. Your first two minutes of test calls are free.',
    'price_cta' => 'See the plans',

    // Questions.
    'faq_title' => 'Questions',
    'faq' => [
        ['Does it sound like a robot?', 'No. It speaks naturally in your customers\' dialect, answers in under a second and lets callers interrupt it, like a person on the phone.'],
        ['Which dialects does it speak?', 'Egyptian, Saudi, Emirati, Kuwaiti, Qatari, Levantine, Iraqi, Yemeni, Moroccan, Algerian, Tunisian, Sudanese and Modern Standard Arabic, and English.'],
        ['How does it learn my prices and hours?', 'From your website and what you write in its knowledge. When you correct an answer in a test call, it learns it.'],
        ['Will it make up answers?', 'It answers only from what you gave it. When it does not know, it takes the caller\'s number for your team.'],
        ['Can I stop it?', 'Any time. Pause it with one button and the call button stops showing on your website.'],
        ['Do I need a developer?', 'No. Share its link or QR code right away. For your website there is a ready plugin, or one line to paste once.'],
    ],

    'final_title' => 'Your agent can answer its first customer today',
    'final_cta' => 'Create my agent',
];
