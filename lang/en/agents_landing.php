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
    'dialects_label' => 'Hear the same call in another accent',
    'dialects_more' => '+:count more accents',
    'dialects_all' => 'Every accent your agent can speak',
    'call_assistant' => ':name, AI voice assistant',
    'scenes' => [
        'american' => ['label' => 'American', 'agent' => 'Emma', 'business' => 'Bright Smile Dental', 'avatar' => 'en-emma',
            'lines' => [
                ['agent', 'Hi, thanks for calling Bright Smile Dental, this is Emma. How can I help you today?'],
                ['caller', 'Hey, how much is teeth whitening?'],
                ['agent', 'Whitening is $250 and takes about an hour. Want me to grab you a spot this Saturday?'],
                ['caller', 'Yeah, Saturday afternoon works.'],
            ],
            'chip' => 'Booked: Saturday, 3 pm',
        ],
        'british' => ['label' => 'British', 'agent' => 'Oliver', 'business' => 'The Kensington Florist', 'avatar' => 'en-oliver',
            'lines' => [
                ['agent', 'Hello, The Kensington Florist, Oliver speaking. How can I help?'],
                ['caller', 'Hi, could I get a bouquet delivered tomorrow?'],
                ['agent', 'Of course. Tomorrow is lovely. Would you like roses, or something seasonal?'],
                ['caller', 'Seasonal, please. Cheers.'],
            ],
            'chip' => 'Order saved: delivery tomorrow',
        ],
        'australian' => ['label' => 'Australian', 'agent' => 'Matilda', 'business' => 'Bondi Surf School', 'avatar' => 'en-matilda',
            'lines' => [
                ['agent', 'G\'day, Bondi Surf School, Matilda here. How can I help?'],
                ['caller', 'Do you run beginner lessons on Sunday?'],
                ['agent', 'We sure do, at 9 and 11, boards included. Want me to pop you in for nine?'],
                ['caller', 'Nine\'s perfect, no worries.'],
            ],
            'chip' => 'Booked: Sunday, 9 am',
        ],
        'irish' => ['label' => 'Irish', 'agent' => 'Finn', 'business' => 'Galway Bay Hotel', 'avatar' => 'en-finn',
            'lines' => [
                ['agent', 'Hiya, Galway Bay Hotel, Finn speaking. What can I do for you?'],
                ['caller', 'Have you a double room free this Friday?'],
                ['agent', 'We have, with a sea view too. It\'s 140 euro a night with breakfast. Will I hold it for you?'],
                ['caller', 'That\'d be grand, thanks.'],
            ],
            'chip' => 'Room held: Friday night',
        ],
        'scottish' => ['label' => 'Scottish', 'agent' => 'Isla', 'business' => 'Highland Car Hire', 'avatar' => 'en-isla',
            'lines' => [
                ['agent', 'Hello, Highland Car Hire, Isla speaking. How can I help?'],
                ['caller', 'I need a wee car for the weekend from Inverness.'],
                ['agent', 'Nae bother. A small car is 45 pounds a day from the station. Shall I book it from Friday?'],
                ['caller', 'Aye, Friday morning.'],
            ],
            'chip' => 'Booked: Friday, Inverness',
        ],
        'indian' => ['label' => 'Indian', 'agent' => 'Anaya', 'business' => 'Spice Route Restaurant', 'avatar' => 'en-anaya',
            'lines' => [
                ['agent', 'Namaste, Spice Route Restaurant, this is Anaya. How may I help you?'],
                ['caller', 'Can I book a table for six tonight?'],
                ['agent', 'Certainly, we have a table by the window at 8 pm. May I have your name and number, please?'],
                ['caller', 'Rahul, 98765 43210.'],
            ],
            'chip' => 'Table for six: tonight, 8 pm',
        ],
    ],
    'scene_order' => ['american', 'british', 'australian', 'irish', 'scottish', 'indian'],

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
    'honest' => 'Callers always know it is an AI assistant, and it never asks for card numbers or passwords.',

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
    'calls_customer' => 'James',
    'calls_avatar' => 'en-james',
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
