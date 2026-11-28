<?php

namespace App\Mail;

/**
 * The email a member gets when they cancel their subscription (Newsletter → Cancellation).
 * Same design as a newsletter, without an unsubscribe link.
 */
class CancellationMail extends NewsletterMail {}
