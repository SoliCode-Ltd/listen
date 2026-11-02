<?php

namespace App\Services\Billing;

use App\Mail\PlanChangeMail;
use App\Models\Newsletter;
use App\Models\PlanChange;
use App\Models\User;
use App\Services\LocalizedPricingService;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Support\LiveServer;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The emails of a lower plan booked for the end of the paid period (PlanChanges), in the
 * newsletter's design and both languages, like the paid welcome email. Only what Stripe
 * does not send itself, to spare the mailbox's quota:
 *
 * - When the change is booked: Stripe sends nothing for a subscription schedule.
 * - The reminders before the day, for a plan paid by hand or given by the team that the
 *   lower plan follows: they take the place of the plan-end reminders
 *   (BillingLifecycleService), which would say the plan ends and does not renew. A card
 *   plan gets Stripe's own renewal reminder instead, with the lower plan's amount.
 *
 * Only the live site sends them, and each one once.
 */
final class PlanChangeEmails
{
    public function __construct(
        private readonly PlanChanges $planChanges,
        private readonly LocalizedPricingService $pricing,
    ) {}

    /** After the page has answered, so it never slows the change down. */
    public function queueBooked(PlanChange $change): void
    {
        if (! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('plan change email', ['plan_change_id' => $change->id]);

            return;
        }

        $id = (int) $change->id;
        dispatch(function () use ($id): void {
            $fresh = PlanChange::query()->with(['user', 'toPlan', 'fromPlan'])->find($id);
            if ($fresh && $fresh->isScheduled()) {
                app(self::class)->send($fresh, 'booked');
            }
        })->afterResponse();
    }

    /** The reminder $days before the change, in place of the plan-end reminder. */
    public function sendReminder(PlanChange $change, int $days): bool
    {
        return $this->send($change, 'reminder', $days);
    }

    /** Whether the email went out now (never twice, never from a server that is not the live site). */
    public function send(PlanChange $change, string $kind, int $days = 0): bool
    {
        $change->loadMissing(['user', 'toPlan', 'fromPlan']);
        $user = $change->user;
        if (! $user instanceof User || ! LiveServer::allowsCustomerActions()) {
            return false;
        }

        $to = strtolower(trim((string) $user->email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || PaidWelcomeEmail::isPhoneSignupAddress($to) || $user->isBlocked()) {
            return false;
        }

        $key = 'plan_change_mail:'.$change->id.':'.$kind.':'.$days;
        if (! Cache::add($key, 1, now()->addDays(40))) {
            return false;
        }

        try {
            $newsletter = $this->newsletter($change, $kind, $days);
            $renderer = app(NewsletterRenderer::class);
            $recipient = ['name' => (string) $user->name];

            $fromAddress = TitanMailbox::ADDRESS;
            if (! app()->environment('testing')) {
                TitanMailbox::apply();
                TitanMailbox::requirePassword();
                $fromAddress = TitanMailbox::username();
            }

            $mail = new PlanChangeMail(
                htmlBody: $renderer->html($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
                textBody: $renderer->text($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
                subjectLine: $renderer->subject($newsletter, Newsletter::LANGUAGE_BOTH),
                fromAddress: $fromAddress,
                fromName: TitanMailbox::fromName(),
                replyToAddress: TitanMailbox::ADDRESS,
            );
            (app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan'))->to($to)->send($mail);
        } catch (\Throwable $exception) {
            // Not sent: a later run may try again.
            Cache::forget($key);
            Log::warning('A plan change email was not sent.', [
                'plan_change_id' => $change->id,
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The email in both languages, Arabic first as the site's other emails: what happens
     * and when, what it costs then, what changes, and how to keep the current plan.
     */
    public function newsletter(PlanChange $change, string $kind, int $days = 0): Newsletter
    {
        $change->loadMissing(['toPlan', 'fromPlan']);
        $fields = ['language' => Newsletter::LANGUAGE_BOTH, 'bilingual_order' => 'ar_en'];
        foreach (['ar', 'en'] as $lang) {
            foreach ($this->words($change, $lang, $kind, $days) as $field => $value) {
                $fields[$field.'_'.$lang] = $value;
            }
        }

        return new Newsletter($fields);
    }

    /** @return array<string, mixed> */
    private function words(PlanChange $change, string $lang, string $kind, int $days): array
    {
        $t = fn (string $key, array $replace = []): string => (string) __('ui.plan_change_mail.'.$key, $replace, $lang);
        $plan = $change->toPlan ? ($change->toPlan->getLocalizedName($lang) ?: (string) $change->toPlan->name) : '';
        $current = $change->fromPlan ? ($change->fromPlan->getLocalizedName($lang) ?: (string) $change->fromPlan->name) : $t('your_plan');
        $date = $this->planChanges->date($change->starts_at, $lang);
        $terms = (string) ($change->terms[$lang]['terms'] ?? '');
        if ($terms === '' && $change->source !== PlanChange::SOURCE_MANUAL) {
            // No price kept with the change: today's, in dollars.
            $terms = $this->planChanges->describe($change, $this->pricing->resolveCountryContext(null), $lang)['terms'];
        }
        // The page keeps a price apart from the words around it with invisible direction
        // marks; some mail apps draw them as boxes, and each paragraph has its own direction.
        $terms = str_replace(["\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"], '', $terms);
        $replace = ['plan' => $plan, 'current' => $current, 'date' => $date, 'terms' => $terms, 'days' => $days];

        $what = match ($change->source) {
            PlanChange::SOURCE_MANUAL => $t('what_manual', $replace),
            PlanChange::SOURCE_CARD_START => $t('what_card_start', $replace),
            default => $t('what_card', $replace),
        };
        $paragraphs = [$kind === 'booked' && $change->source === PlanChange::SOURCE_MANUAL ? $t('paid_approved', $replace).' '.$what : $what];
        if ($kind === 'booked' && $change->source !== PlanChange::SOURCE_MANUAL) {
            $paragraphs[] = $t('nothing_today');
        }

        if ($change->canBeKept()) {
            $paragraphs[] = $t('keep_line', $replace);
        }
        // Last, as the list follows the paragraphs in the email.
        $changes = $this->planChanges->differences($change->fromPlan, $change->toPlan, $lang);
        if ($changes !== []) {
            $paragraphs[] = $t('changes_title', $replace);
        }

        $subject = $kind === 'booked'
            ? $t('subject_booked', $replace)
            : ($days <= 1 ? $t('subject_tomorrow', $replace) : $t('subject_days', $replace));

        return [
            'subject' => $subject,
            'preheader' => $what,
            'eyebrow' => $t('eyebrow'),
            'headline' => $t('headline', $replace),
            'intro' => implode("\n\n", $paragraphs),
            'body' => '',
            'highlights' => array_map(
                fn (array $row): string => $t('change_row', ['label' => $row['label'], 'from' => $row['from'], 'to' => $row['to']]),
                $changes
            ),
            'cta_label' => $t('cta'),
            'cta_url' => rtrim((string) config('app.url', ''), '/').'/'.$lang.'/manage-subscription',
            'footer' => $t('footer', $replace),
        ];
    }
}
