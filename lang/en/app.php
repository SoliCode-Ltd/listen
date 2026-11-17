<?php

// The Listen Android app (mobile/): what the site says only inside it.
// Nothing here may name a price, a way to pay, or a place to pay.
return [
    'not_sold_here' => 'Plans can\'t be bought or changed in this app.',
    'not_in_plan' => 'This feature isn\'t part of your current plan.',
    'no_credits' => 'You have used all your credits for now.',
    'no_credits_until' => 'You have used all your credits. They are back on :date.',
    'signin_failed' => 'We couldn\'t finish signing you in. Please try again.',

    'tabs' => [
        'voices' => 'Voices',
        'account' => 'Account',
    ],

    'plan' => [
        'upgrade' => 'Upgrade plan',
        'title' => 'My account',
        'plan_heading' => 'Your plan',
        'hello' => 'Hello, :name',
        'used' => ':used of :total credits used',
        'renews' => 'Credits renew on',
        'current' => 'Current plan',
        'credits_left' => 'Credits left',
        'ends' => 'Ends on',
        'free' => 'Free',
        'back' => 'Back to the studio',
        'account' => 'Account',
        'signed_in_as' => 'Signed in as',
        'help' => 'Help and contact',
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'delete_account' => 'Delete my account',
        'sign_out' => 'Sign out',
    ],

    'web' => [
        'title' => 'Continue in your browser',
        'text' => 'Plans and payments open in your browser, so your payment stays with your bank and our secure checkout. You are already signed in there.',
        'text_guest' => 'Plans and payments open in your browser. Sign in there and you will go straight to what you chose.',
        'button' => 'Open in browser',
        'back' => 'Back to the app',
    ],

    'signin' => [
        'done_title' => 'You\'re signed in',
        'done_text' => 'Signed in as :email. Go back to the app to continue.',
        'done_button' => 'Back to the app',
        'done_hint' => 'If nothing happens, tap the button.',
    ],

    // The iPhone app's question before a member's first voice (App\Support\InApp\AiConsent).
    // This is the one place where the speech provider is named: Apple asks that the person is
    // told who receives their data, and the owner approved it for this page of the iPhone app only.
    'consent' => [
        'title' => 'Before you make your first voice',
        'lead' => 'Listen makes your audio with AI from another company. Here is what is sent, and to whom.',
        'what_heading' => 'What is sent',
        'what' => [
            'The text you write to turn into speech',
            'The recordings you make for voice cloning, and what you say when you talk to a voice agent',
            'A video you upload to be dubbed, or a recording you upload to be written out as text',
        ],
        'who_heading' => 'Who receives it',
        'who' => 'Google, through its AI services (Gemini and Google Cloud). They process it to make your audio and send the result back to Listen.',
        'why_heading' => 'What it is used for',
        'why' => 'To make what you asked for, and nothing else. What you make stays in your history until you delete it.',
        'more' => 'Read the privacy policy',
        'agree' => 'Agree and continue',
        'not_now' => 'Not now',
        'needed' => 'Please agree to how your text and voice are processed before using this.',
        'agreed_on' => 'You agreed on :date.',
        'withdraw' => 'Withdraw my agreement',
        'withdraw_note' => 'You can withdraw at any time. The studio, voices and agents then stay closed in this app until you agree again.',
        'row' => 'How your text and voice are processed',
        'back' => 'Back to my account',
    ],

    'push' => [
        'voice_ready_title' => 'Your voice is ready',
        'voice_ready_body' => 'Tap to listen to it or download it.',
        'dub_ready_title' => 'Your dubbed video is ready',
        'dub_ready_body' => 'Tap to watch it.',
        'dub_failed_title' => 'Your video could not be dubbed',
        'dub_failed_body' => 'Tap to see why and try again.',
        'clone_ready_title' => 'Your cloned voice is ready',
        'clone_ready_body' => 'Tap to use it in the studio.',
    ],

    // The app's own parts of the page (public/js/listen-app.js).
    'shell' => [
        'web_failed' => 'Your browser could not be opened. Please try again.',
        'save' => 'Save to phone',
        'share' => 'Share',
        'cancel' => 'Cancel',
        'saved' => 'Saved to Downloads, in the Listen folder.',
        'saved_plain' => 'Saved.',
        'save_failed' => 'The file could not be saved. Please try again.',
        'preparing' => 'Getting the file ready…',
        'notify_title' => 'Tell you when it\'s ready?',
        'notify_text' => 'Long texts take a while. We can send a notification when your voice is ready, so you don\'t have to wait here.',
        'notify_yes' => 'Yes, notify me',
        'notify_no' => 'Not now',
        'mic_denied' => 'The microphone is off for Listen. Turn it on in your phone\'s settings to record.',
        'exit_hint' => 'Press back again to leave',
        'now_playing' => 'Listen',
    ],
];
