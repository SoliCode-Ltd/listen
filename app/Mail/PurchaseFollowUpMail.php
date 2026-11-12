<?php

namespace App\Mail;

/**
 * The email after a declined first payment or a checkout left without paying
 * (Newsletter → Purchase follow-ups). Same design as a newsletter, without an unsubscribe link.
 */
class PurchaseFollowUpMail extends NewsletterMail {}
