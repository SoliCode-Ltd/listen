<?php

namespace App\Mail;

/**
 * The reminder before a plan that does not renew by itself ends (Newsletter → Reminder).
 * Same design as a newsletter, without an unsubscribe link.
 */
class PlanEndReminderMail extends NewsletterMail {}
