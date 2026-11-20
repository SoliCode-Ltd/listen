<?php

namespace App\Mail;

/**
 * The email a paid member gets when the period's credits run out (Newsletter → Credits ended).
 * Same design as a newsletter, without an unsubscribe link.
 */
class CreditsEndedMail extends NewsletterMail {}
