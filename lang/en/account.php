<?php

// "Delete my account": the page where a member deletes their own account
// (App\Http\Controllers\AccountDeletionController), on the site and in the apps.
return [
    'delete' => [
        'title' => 'Delete my account',
        'lead' => 'This deletes your Listen account and everything in it, for good. It cannot be undone.',
        'signed_in_as' => 'Signed in as',

        'gone_heading' => 'What is deleted',
        'gone' => [
            'Your profile and your sign-in',
            'Every voice-over you made, with its text and its audio',
            'Your cloned and designed voices, and the recordings you made for them',
            'Your voice agents, their files and their call records',
            'Your plan and any credits you have left',
        ],
        'kept_heading' => 'What we keep',
        'kept' => 'Records of payments, because the law requires them, and a note that the free trial was used, so it cannot be claimed twice. Nothing else.',

        'billing_subscription' => 'Your subscription ends now. You will not be charged again, and the time left on it is not refunded.',
        'billing_trial' => 'Your free trial ends now. Your card will not be charged.',

        'confirm' => 'I understand that my account and everything in it will be deleted and cannot be brought back.',
        'confirm_required' => 'Tick the box to confirm.',
        'button' => 'Delete my account for good',
        'cancel' => 'Keep my account',

        'refused_admin' => 'An admin account cannot be deleted here.',
        'refused_here' => 'An account with a running subscription cannot be deleted on this test server.',
        'failed_billing' => 'We could not end your subscription just now, so nothing was deleted. Please try again in a few minutes, or contact us.',
        'failed' => 'Your account could not be deleted just now. Please contact us and we will finish it for you.',

        'self_service' => 'You can delete your account yourself, right now, without waiting for us.',
    ],

    'deleted' => [
        'title' => 'Your account has been deleted',
        'text' => 'Your account and everything in it are gone. You have been signed out everywhere.',
        'next' => 'Done',
    ],
];
