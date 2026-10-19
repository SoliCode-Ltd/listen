<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Throwaway email addresses
    |--------------------------------------------------------------------------
    |
    | Sign-up with email and password refuses addresses at the services below:
    | inboxes anyone can open without an account, made for one use and thrown
    | away. They are the usual way to open account after account for the free
    | demo. A subdomain of a listed domain counts too.
    |
    | Only such public inboxes belong here. Forwarding and privacy services
    | (aliases that reach a real person's own mailbox) are left out on purpose.
    |
    | LISTEN_BLOCK_DISPOSABLE_EMAILS=false turns the check off.
    |
    */

    'enabled' => (bool) env('LISTEN_BLOCK_DISPOSABLE_EMAILS', true),

    'domains' => [
        // Mailinator and the domains it answers on.
        'mailinator.com', 'mailinator.net', 'mailinator.org', 'mailinator2.com', 'mailinater.com',
        'notmailinator.com', 'binkmail.com', 'bobmail.info', 'chammy.info', 'devnullmail.com',
        'letthemeatspam.com', 'mailismagic.com', 'safetymail.info', 'sogetthis.com', 'spamherelots.com',
        'spamhereplease.com', 'streetwisemail.com', 'suremail.info', 'thisisnotmyrealemail.com',
        'tradermail.info', 'veryrealemail.com', 'zippymail.info', 'reallymymail.com',

        // Guerrilla Mail.
        'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamail.biz',
        'guerrillamail.de', 'guerrillamail.info', 'guerrillamailblock.com', 'sharklasers.com',
        'grr.la', 'pokemail.net', 'spam4.me',

        // YOPmail and its alternate domains.
        'yopmail.com', 'yopmail.net', 'yopmail.fr', 'cool.fr.nf', 'jetable.fr.nf', 'courriel.fr.nf',
        'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf', 'nospam.ze.tc', 'nomail.xl.cx',
        'mega.zik.dj', 'speed.1s.fr',

        // Ten-minute and temporary inboxes.
        '10minutemail.com', '10minutemail.net', '10minutemail.co.uk', '20minutemail.com',
        'temp-mail.org', 'temp-mail.io', 'tempmail.com', 'tempmail.net', 'tempmailo.com', 'tempail.com',
        'tempr.email', 'tempinbox.com', 'tmpmail.org', 'tmpmail.net', 'mytemp.email', 'emltmp.com',
        '1secmail.com', '1secmail.org', '1secmail.net', 'dropmail.me', 'getnada.com', 'nada.email',
        'maildrop.cc', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com',
        'dispostable.com', 'discard.email', 'discardmail.com', 'discardmail.de', 'throwawaymail.com',
        'mailcatch.com', 'mailpoof.com', 'mailsac.com', 'inboxkitten.com', 'harakirimail.com',
        'moakt.com', 'moakt.cc', 'crazymailing.com', 'linshiyouxiang.net', 'fakeinbox.com',

        // Trash and spam-catcher inboxes.
        'trashmail.com', 'trashmail.net', 'trashmail.de', 'trashmail.me', 'trashmail.at', 'trash-mail.com',
        'wegwerfmail.de', 'wegwerfmail.net', 'wegwerfmail.org', 'spamgourmet.com', 'spambog.com',
        'spambox.us', 'mailnull.com', 'mailforspam.com', 'mailexpire.com', 'mailmetrash.com', '0815.ru',

        // Fake-identity generators' inboxes.
        'emailfake.com', 'email-fake.com', 'fakemailgenerator.com', 'armyspy.com', 'cuvox.de',
        'dayrep.com', 'einrot.com', 'fleckens.hu', 'gustr.com', 'jourrapide.com', 'rhyta.com',
        'superrito.com', 'teleworm.us',
    ],
];
