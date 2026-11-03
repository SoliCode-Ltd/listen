<?php

// The Agents page for visitors: what a voice agent does for a business, shown working.
return [
    'meta_title' => 'Voice agents that answer your customers in their dialect',
    'meta_description' => 'A voice agent for your business: it answers calls on your website, a link or a QR code, in Egyptian, Saudi, Emirati, Levantine, Moroccan or English, day and night. Ready in two minutes.',

    'hero_title' => 'A voice that answers your customers, in their dialect',
    'hero_text' => 'Your agent answers questions from your prices and hours, takes names and numbers, and books visits. On your website, a link or a QR code, day and night.',
    'hero_cta' => 'Create my agent',
    'hero_cta_member' => 'Open my agents',
    'hero_note' => '{1} Your first minute of test calls is free.|[2,*] Your first :count minutes of test calls are free.',
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
    'home_title' => 'Every call answered, in your customers\' dialect',
    'home_text' => 'A voice agent for your business, on your website, a link or a QR code. It answers from your prices and hours, books visits and tells your team who to call back. Ready in two minutes.',
    'home_facts' => [
        'Answers in under a second, day and night',
        'Reads every phone number back before saving it',
        'Never asks for card numbers or passwords',
    ],
    'home_cta' => 'Meet Agents',
    'home_talk' => 'Talk to our agent',

    // The accents it speaks, by region (the studio's own regions), and every one of them in a dialog.
    'speaks_title' => 'It speaks the way your customers do',
    'speaks_text' => ':count English accents, from American and British to Irish, Scottish and Indian.',
    'speaks_all' => 'Every accent',
    'speaks_regions' => [
        'americas' => ['Americas', 'General American, Southern, New York, Canadian, Jamaican'],
        'uk_ie' => ['UK & Ireland', 'London, Liverpool, Scottish, Welsh, Irish, Cork'],
        'africa' => ['Africa', 'South African, Nigerian, Ghanaian, Kenyan, Ugandan'],
        'asia_pacific' => ['Asia-Pacific', 'Australian, New Zealand, Indian, Singaporean, Filipino'],
        'other' => ['Second-language English', 'Arabic, French, Spanish, German, Japanese'],
    ],
    'speaks_unit' => '{1} 1 accent|[2,*] :count accents',
    'speaks_unit_word' => '{1} accent|[0,*] accents',
    'speaks_open' => 'See :region',
    'speaks_fusha_badge' => '',
    'speaks_dialog_text' => 'Search for your customers\' accent, or browse by region.',
    'speaks_search' => 'Search an accent, country or city',
    'speaks_any' => 'All',
    'speaks_regions_label' => 'Region',
    'speaks_none' => 'No accent by that name. Try a country or a city.',
    'speaks_close' => 'Close',
    'speaks_note' => 'You choose your agent\'s accent when you create it, and can change it any time.',

    // How it works: one studio window, its screen changing with each step.
    'how_app' => [
        'crumb' => 'My agents',
        'agent' => 'Caleb',
        'business' => 'Sunset Auto Care',
        'draft' => 'Draft',
        'live' => 'Answering now',
        'menu' => ['Knowledge', 'Voice', 'Try it', 'Go live', 'Calls'],
    ],
    'steps_ui' => [
        'screens' => [
            ['Knowledge', 'What your agent knows about your business'],
            ['Voice', 'Every voice speaks your customers\' accent'],
            ['Try it', 'Ask what your customers ask'],
            ['Go live', 'Your agent answers everywhere'],
        ],
        'read' => 'Read website',
        'found' => 'Read 12 pages and found 24 facts',
        'facts' => [
            ['Services & prices', '14 services with prices'],
            ['Hours', 'Mon to Sat, 8 am to 6 pm'],
            ['Locations', 'Two garages in Phoenix'],
            ['Offers', 'Free tire check with every service'],
        ],
        'add' => 'Add a fact yourself',
        'voice_filters' => ['All voices', 'Women', 'Men'],
        'voice_tones' => ['Friendly', 'Warm', 'Bright', 'Calm', 'Upbeat', 'Clear'],
        'voice_line' => 'Hi, thanks for calling Sunset Auto Care, this is Caleb.',
        'voice_length' => '0:05',
        'test_with' => 'Test call with Caleb',
        'test_time' => '01:24',
        'test_end' => 'End',
        'you' => 'You',
        'talk_q' => 'How much is an oil change?',
        'talk_a' => 'An oil change is $49 and takes about 30 minutes.',
        'talk_fix' => 'Correct this answer',
        'fix_text' => 'It is $39 this month.',
        'fix_saved' => 'Saved. It answers this way on every call.',
        'live' => ['Its link', 'QR code', 'A button on your site'],
        'copy' => 'Copy',
        'download' => 'Download',
        'site_seen' => 'Seen on your website',
    ],

    // Every call written down: the owner's inbox, one call open.
    'calls_title' => 'Every call, written down',
    'calls_text' => 'Read a short summary of each call, open what was said, and call back the customers who left their number in one tap.',
    'inbox' => [
        'label' => 'Sunset Auto Care\'s calls',
        'title' => 'Calls',
        'month' => '128 calls this month',
        'callbacks' => '46 to call back',
        'summary' => 'Summary',
        'said' => 'What was said',
        'left' => 'Left his number',
        'left_f' => 'Left her number',
        'call' => 'Call',
        'agent' => 'Caleb',
        'calls' => [
            [
                'name' => 'Jake', 'female' => false, 'phone' => '555 210 4876', 'when' => '5 min ago',
                'channel' => 0, 'channel_name' => 'Website', 'length' => '2 min', 'tag' => 'Booking', 'tone' => 'ok',
                'summary' => 'Booked an oil change for today at 3 pm.',
                'outcome' => 'Booked: today, 3 pm',
                'lines' => [
                    ['caller', 'Hi, do you guys have time for an oil change today, maybe around three?'],
                    ['agent', 'Yes, we have an opening today at three pm. May I have your name and number, please?'],
                    ['caller', 'Perfect. I\'m Jake, and my number is 555 210 4876.'],
                ],
            ],
            [
                'name' => 'Maria', 'female' => true, 'phone' => '555 014 7720', 'when' => '1 hour ago',
                'channel' => 1, 'channel_name' => 'Link', 'length' => '1 min', 'tag' => 'Question', 'tone' => 'info',
                'summary' => 'Asked about brake checks and Saturday hours.',
                'outcome' => 'Answered from your facts',
                'lines' => [
                    ['caller', 'How much is a brake check, and are you open on Saturday?'],
                    ['agent', 'A brake check is $29, and on Saturdays we\'re open from 8 am to 2 pm.'],
                    ['caller', 'Great, thank you!'],
                ],
            ],
            [
                'name' => 'Daniel', 'female' => false, 'phone' => '555 019 3384', 'when' => '3 hours ago',
                'channel' => 2, 'channel_name' => 'QR code', 'length' => '3 min', 'tag' => 'Call back', 'tone' => 'act',
                'summary' => 'Wants a call back with a quote for four new tires.',
                'outcome' => 'Call him with a tire quote',
                'lines' => [
                    ['caller', 'I need a price for four new tires for my pickup.'],
                    ['agent', 'Tire prices depend on the size, so our team will put a quote together. Can I take your number for a call back?'],
                    ['caller', 'Sure, it\'s 555 019 3384.'],
                ],
            ],
            [
                'name' => 'Emily', 'female' => true, 'phone' => '555 012 8841', 'when' => 'Yesterday',
                'channel' => 0, 'channel_name' => 'Website', 'length' => '2 min', 'tag' => 'Rescheduled', 'tone' => 'ok',
                'summary' => 'Moved her service from Monday to Wednesday at 10 am.',
                'outcome' => 'New time: Wednesday, 10 am',
                'lines' => [
                    ['caller', 'I have a service on Monday. Could I move it to Wednesday?'],
                    ['agent', 'Of course. We have Wednesday at 10 am. Shall I move it there?'],
                    ['caller', 'Yes please, thanks so much.'],
                ],
            ],
        ],
    ],

    // Pricing.
    'price_title' => 'In every plan, no separate bill',
    'price_text' => 'Calls use your plan\'s credits, like your voice-overs. Your first two minutes of test calls are free.',
    'price_cta' => 'See the plans',

    // Questions.
    'faq_title' => 'Common questions',
    'faq_text' => 'What business owners ask before their agent answers its first customer.',
    'faq_more_title' => 'Still have a question?',
    'faq_more_text' => 'Write to our team.',
    'faq_more_live' => 'Ask Sara, Listen\'s own agent, out loud right now, or write to our team.',
    'faq_contact' => 'Write to us',
    'faq' => [
        ['Does it sound like a robot?', 'No. It speaks naturally in your customers\' dialect, answers in under a second and lets callers interrupt it, like a person on the phone.'],
        ['Which dialects does it speak?', 'Egyptian, Saudi, Emirati, Kuwaiti, Qatari, Levantine, Iraqi, Yemeni, Moroccan, Algerian, Tunisian, Sudanese and Modern Standard Arabic, and English.'],
        ['How does it learn my prices and hours?', 'From your website and what you write in its knowledge. When you correct an answer in a test call, it learns it.'],
        ['Will it make up answers?', 'It answers only from what you gave it. When it does not know, it takes the caller\'s number for your team.'],
        ['Can I stop it?', 'Any time. Pause it with one button and the call button stops showing on your website.'],
        ['Do I need a developer?', 'No. Share its link or QR code right away. For your website there is a ready plugin, or one line to paste once.'],
        ['What does a call cost?', 'Calls use your plan\'s credits: one call minute uses :rate credits, the same credits as your voice-overs. Your agents\' page always shows the minutes left, and you can set the most minutes a day for each agent.'],
        ['Is it safe for my customers?', 'Yes. Listen\'s own rules lead every call: it never asks for card numbers, passwords or money, never claims to be anyone else, and your call button works only on your own website.'],
    ],

    'final_title' => 'Your agent can answer its first customer today',
    'final_cta' => 'Create my agent',

    // A real call with Listen's own agent, recorded live (components/agents/real-call): the caller's
    // words, the agent's own voice with its words as it says them, and what the business gets.
    'real_title' => 'Hear a real call',
    'real_text' => 'Three calls in three accents: Caleb in Phoenix, Alice in Norwich and Sienna in Galway. The voice, the pauses and the number read back digit by digit, exactly as your callers hear them.',
    'scene_play' => 'Watch a call',
    'scene_again' => 'Watch it again',
    'scene_note' => 'Every call ends with something done for your team.',
    'scene_order_home' => ['american', 'british'],
    'real_play' => 'Hear a real call',
    'real_pause' => 'Pause',
    'real_again' => 'Hear it again',
    'real_switch' => 'Accent',
    'real_note' => 'The agent\'s voice is recorded live from calls with sample businesses, exactly as it spoke. The caller\'s lines are written.',
    'real_caller' => 'Caller',
    'real_calls' => [
        'us' => [
            'label' => 'American', 'agent' => 'Caleb', 'avatar' => 'en-caleb', 'business' => 'Sunset Auto Care', 'dialect' => 'American (General)',
            'turns' => [
                ['caller', 'Hi, do you guys have time for an oil change today, maybe around three?'],
                ['agent', 'Yes, we do have an opening for an oil change today at three pm. To secure that time, may I have your name and phone number, please?', '', 'en-us-1'],
                ['caller', 'Perfect. My name is Jake, and my number is 555 210 4876.'],
                ['agent', 'Thank you, Jake. Let me confirm your number: five five five, two one zero, four eight seven six. Everything is set, Jake. We\'ll see you today at three pm.', '', 'en-us-2'],
                ['caller', 'Yep, that\'s right. Thanks!'],
                ['agent', 'You\'re very welcome! Have a great day. Goodbye.', '', 'en-us-3'],
            ],
            'result' => ['Booking request saved', 'Jake', '555 210 4876', 'Oil change, today at 3 pm'],
        ],
        'gb' => [
            'label' => 'British', 'agent' => 'Alice', 'avatar' => 'en-alice', 'business' => 'Bloom and Willow', 'dialect' => 'British (East Anglian)',
            'turns' => [
                ['caller', 'Hello, could I order some flowers for delivery tomorrow?'],
                ['agent', 'Hello! Yes, we can certainly arrange a delivery for tomorrow. What flowers do you have in mind?', '', 'en-gb-1'],
                ['caller', 'Something bright, around forty pounds. I\'m Sophie, and my number is 07700 900123.'],
                ['agent', 'Thank you, Sophie. Just to confirm your number is oh seven seven double oh, nine double oh, one two three?', '', 'en-gb-2'],
                ['caller', 'Lovely, thank you so much.'],
                ['agent', 'You\'re very welcome, Sophie. I\'ve saved your order details, and the team will be in touch to confirm everything soon. Goodbye, and have a lovely day!', '', 'en-gb-3'],
            ],
            'result' => ['Order saved', 'Sophie', '07700 900123', 'A bright bouquet, about £40, tomorrow'],
        ],
        'ie' => [
            'label' => 'Irish', 'agent' => 'Sienna', 'avatar' => 'en-sienna', 'business' => 'Harbour Lane Guesthouse', 'dialect' => 'Irish',
            'turns' => [
                ['caller', 'Hi there, have you a double room free this Friday night?'],
                ['agent', 'We do indeed! We have two double rooms free this Friday night, and they\'re one hundred and forty euro a night including breakfast.', '', 'en-ie-1'],
                ['caller', 'Brilliant. It\'s Ciara, and my number is 087 123 4567.'],
                ['agent', 'Thanks, Ciara. Just to confirm your number is zero eight seven, one two three, four five six seven?', '', 'en-ie-2'],
                ['caller', 'That\'s it, thanks a million.'],
                ['agent', 'Perfect. I\'ve noted that down for you, and the team will be in touch soon to confirm your booking by text. You\'re very welcome. Enjoy your stay in Galway and have a lovely evening!', '', 'en-ie-3'],
            ],
            'result' => ['Booking request saved', 'Ciara', '087 123 4567', 'Double room, Friday night'],
        ],
    ],
    'real_order' => ['us', 'gb', 'ie'],

    // Safe by design.
    'safe_title' => 'Safe for your customers, and for your bill',
    'safe_text' => 'Listen\'s own rules lead every call, whatever your agent is told, and every minute stays in your hands.',
    'safe' => [
        ['Never asks for card numbers', 'Card numbers, passwords and codes are never asked for on a call, and it never asks anyone to send money.'],
        ['Speaks only for you', 'It never claims to be anyone else, and it tells callers it is an AI assistant when they ask.'],
        ['Works only on your website', 'Your call button is locked to your own website, so a copy on another site does not work.'],
        ['Limits you choose', 'The longest call, the minutes a day, and calls at the same time. It hangs up kindly when a line goes quiet.'],
        ['Checked before it goes live', 'Every agent is checked before it answers anyone, and callers can report one in a tap.'],
        ['Every call written down', 'A summary of each call, what was said, and who to call back, kept for you.'],
    ],

    // Pricing, from the plans (AgentAccess).
    'plans_title' => 'Calls come out of your plan',
    'plans_text' => 'One call minute uses :rate credits from your plan, the same credits as your voice-overs. You always see the minutes left.',
    'plans_line' => '{1} 1 agent, up to :minutes call minutes a month|[2,*] :count agents, up to :minutes call minutes a month',
    'plans_free' => 'Every account gets :seconds seconds of free test calls.',
];
