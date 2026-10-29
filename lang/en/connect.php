<?php

// "Sign in with Listen": the page where a member allows an AI app (resources/views/oauth/authorize.blade.php)
// and the Connected apps list in the dashboard's Developer API section.
return [
    'page_title' => 'Connect an app',
    'title' => 'Allow :app to use your Listen account?',
    'signed_in_as' => 'Signed in as :email',
    'can_title' => ':app will be able to:',
    'can' => [
        'speak' => 'Make speech with Listen voices, using your credits',
        'voices' => 'See the voices and options on your plan',
        'history' => 'See your recordings',
        'account' => 'See your plan and the credits you have left',
    ],
    'cannot' => 'It cannot see your password or payment details, and it cannot delete anything.',
    'manage' => 'You can disconnect it at any time in your dashboard, under Developer API.',
    'returns_to' => 'Allowing takes you back to :host.',
    'allow' => 'Allow',
    'cancel' => 'Cancel',
    'plan_title' => 'Your plan doesn\'t include AI apps yet',
    'plan_body' => ':app wants to use your Listen account. AI apps can connect on paid plans with the premium voices, and you are on :plan.',
    'trial_title' => 'AI apps connect after your first payment',
    'trial_body' => ':app wants to use your Listen account. AI apps can connect once the first payment for your plan has gone through.',
    'see_plans' => 'See plans',

    // Dashboard → Developer API
    'apps_title' => 'Connected AI apps',
    'apps_intro' => 'Apps you allowed with "Sign in with Listen". They use your credits, like the studio.',
    'apps_empty' => 'No apps connected. In an AI app that supports MCP, add the address :url and sign in with Listen.',
    'connected_on' => 'Connected :date',
    'disconnect' => 'Disconnect',
    'disconnect_confirm' => 'Disconnect this app? It loses access at once. You can connect it again later.',
    'flash_disconnected' => 'The app was disconnected.',
];
