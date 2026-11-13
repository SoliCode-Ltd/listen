<?php

namespace App\Services\Billing;

use App\Mail\PurchaseFollowUpMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\PurchaseFollowUp;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Services\PlanAccessService;
use App\Support\LiveServer;
use App\Support\StripeInvoice;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;

/**
 * Members who nearly bought a plan get one email that brings them back:
 *
 *  - their first payment was declined (the bank said no): a little later, an email with a
 *    button to Stripe's own page for that payment (the checkout once that page has closed);
 *  - they opened the checkout and left without paying: a few hours later, a reminder.
 *
 * Both are in Arabic and English, in the newsletter's email design; their words, the waits
 * and the two on/off switches are edited in Newsletter → Purchase follow-ups, which also
 * lists every case with its email and whether the member bought a plan afterwards.
 *
 * A member gets at most one of these in a week, never once they have a paid plan, and only
 * from the live site. Nothing here touches a payment: it only records and emails.
 */
class PurchaseFollowUps
{
    public const GROUP = 'purchase_followup';

    /** A member is emailed at most once in this many days, whatever happened. */
    public const QUIET_DAYS = 7;

    /** An email that came due this long ago is not sent any more (the moment has passed). */
    public const TOO_LATE_HOURS = 48;

    /** A purchase this long after the event still counts as following it. */
    public const PAID_WITHIN_DAYS = 14;

    /** How long the button in a declined payment's email keeps working. */
    public const LINK_DAYS = 30;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            // Off until the owner has read the words and turned them on.
            'failed_enabled' => '0',
            'left_enabled' => '0',
            // A member whose card was declined often tries another one at once: wait a little.
            'failed_after_minutes' => '30',
            'left_after_hours' => '3',
            'order' => 'ar_en',

            'failed_subject_en' => 'Your payment for Listen {plan} did not go through',
            'failed_subject_ar' => 'لم يكتمل الدفع لباقة {plan} في إسمع',
            'failed_headline_en' => 'Your {plan} plan is one step away',
            'failed_headline_ar' => 'باقة {plan} على بُعد خطوة واحدة',
            'failed_body_en' => "Hi {name}, you tried to subscribe to the {plan} plan, but your bank declined the payment{reason}. Nothing was charged, and the plan is not active yet.\n\nTo finish, press the button below and pay with a card that has enough balance, or with another card. Your plan is activated the moment the payment goes through.",
            'failed_body_ar' => "أهلاً {name}، حاولت الاشتراك في باقة {plan}، لكن البنك رفض عملية الدفع{reason}. لم يُخصم منك أي مبلغ، ولم تُفعَّل الباقة بعد.\n\nلإكمال اشتراكك، اضغط الزر أدناه وادفع ببطاقة فيها رصيد كافٍ أو ببطاقة أخرى. تُفعَّل باقتك فور إتمام الدفع.",
            'failed_cta_label_en' => 'Complete my payment',
            'failed_cta_label_ar' => 'إكمال الدفع',
            'failed_footer_en' => 'Need another way to pay? Just reply to this email and we will help.',
            'failed_footer_ar' => 'تحتاج طريقة دفع أخرى؟ ردّ على هذه الرسالة وسنساعدك.',

            'left_subject_en' => 'Your Listen {plan} plan is waiting for you',
            'left_subject_ar' => 'باقة {plan} في إسمع بانتظارك',
            'left_headline_en' => 'Finish your {plan} subscription',
            'left_headline_ar' => 'أكمل اشتراكك في باقة {plan}',
            'left_body_en' => "Hi {name}, you were one step away from the {plan} plan. It takes less than a minute to finish, and every voice, dialect and feature in the plan is ready the moment you do.\n\nIf something stopped you, such as a card that did not work or a question about the plan, reply to this email and we will help.",
            'left_body_ar' => "أهلاً {name}، كنت على بُعد خطوة واحدة من باقة {plan}. إكمال الاشتراك يأخذ أقل من دقيقة، وكل الأصوات واللهجات والمزايا في الباقة تصبح جاهزة لك فور إتمامه.\n\nإن أوقفك شيء، مثل بطاقة لم تعمل أو سؤال عن الباقة، ردّ على هذه الرسالة وسنساعدك.",
            'left_cta_label_en' => 'Complete my subscription',
            'left_cta_label_ar' => 'إكمال الاشتراك',
            'left_footer_en' => 'Not what you were looking for? Reply and tell us; we read every message.',
            'left_footer_ar' => 'لم تجد ما تبحث عنه؟ ردّ علينا وأخبرنا، فنحن نقرأ كل رسالة.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function settings(): array
    {
        $settings = [];
        foreach (self::defaults() as $key => $default) {
            $value = SiteSetting::get(self::GROUP.'_'.$key);
            $settings[$key] = is_string($value) && trim($value) !== '' ? $value : $default;
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        foreach (array_keys(self::defaults()) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : trim((string) $data[$key]);
            if ($key === 'failed_after_minutes') {
                $value = (string) max(5, min(720, (int) $value));
            }
            if ($key === 'left_after_hours') {
                $value = (string) max(1, min(self::TOO_LATE_HOURS, (int) $value));
            }
            SiteSetting::set(self::GROUP.'_'.$key, $value, self::GROUP);
        }
    }

    public function enabled(string $kind): bool
    {
        return $this->settings()[self::prefix($kind).'_enabled'] === '1';
    }

    /**
     * A member without a paid plan opened the checkout for a plan. One open case per member
     * a day: opening it again moves the reminder later.
     */
    public function checkoutOpened(User $user, Plan $plan, string $cycle, string $locale): void
    {
        if (! PurchaseFollowUp::ready() || $user->is_admin || (int) $plan->price_cents <= 0) {
            return;
        }

        rescue(function () use ($user, $plan, $cycle, $locale): void {
            $hours = max(1, (int) $this->settings()['left_after_hours']);
            $open = PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_CHECKOUT_LEFT)
                ->where('email_status', PurchaseFollowUp::WAITING)
                ->whereNull('paid_at')
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->first();

            $values = [
                'plan_slug' => (string) $plan->slug,
                'billing_cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly',
                'locale' => $locale === 'en' ? 'en' : 'ar',
                'happened_at' => now(),
                'email_due_at' => now()->addHours($hours),
            ];

            if ($open) {
                $open->forceFill($values + ['opens' => min(65000, (int) $open->opens + 1)])->save();

                return;
            }

            // A declined payment from the same visit already has its own email coming.
            $declined = PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)
                ->where('email_status', PurchaseFollowUp::WAITING)
                ->exists();
            if ($declined) {
                return;
            }

            PurchaseFollowUp::query()->create($values + [
                'user_id' => $user->id,
                'kind' => PurchaseFollowUp::KIND_CHECKOUT_LEFT,
                'email_status' => PurchaseFollowUp::WAITING,
            ]);
        }, null, false);
    }

    /**
     * Stripe says an invoice's payment failed. Only a first payment counts here (a renewal
     * that fails has its own grace days and warnings: App\Services\Billing\RenewalGrace).
     */
    public function paymentFailed(object $invoice): void
    {
        if (! PurchaseFollowUp::ready() || StripeInvoice::billingReason($invoice) !== 'subscription_create') {
            return;
        }

        rescue(function () use ($invoice): void {
            $customerId = trim((string) ($invoice->customer ?? ''));
            $user = $customerId !== '' ? User::query()->where('stripe_id', $customerId)->first() : null;
            if (! $user) {
                return;
            }

            $invoiceId = trim((string) ($invoice->id ?? ''));
            // The checkout this payment came from says which plan, cycle and language.
            $checkout = PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_CHECKOUT_LEFT)
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->first();

            $row = ($invoiceId !== '' ? PurchaseFollowUp::query()->where('stripe_invoice_id', $invoiceId)->first() : null)
                ?? PurchaseFollowUp::query()
                    ->where('user_id', $user->id)
                    ->where('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)
                    ->where('email_status', PurchaseFollowUp::WAITING)
                    ->latest('id')
                    ->first()
                ?? new PurchaseFollowUp(['user_id' => $user->id, 'kind' => PurchaseFollowUp::KIND_PAYMENT_FAILED, 'email_status' => PurchaseFollowUp::WAITING]);

            $minutes = max(5, (int) $this->settings()['failed_after_minutes']);
            // The bank's reason can arrive before the invoice's own event: it was kept for this.
            $reason = Cache::pull(self::reasonKey((int) $user->id));
            // Else asked from Stripe once: the invoice's event does not carry it.
            if (! is_string($reason) || $reason === '') {
                $reason = $row->reason ?: $this->lookUpReason($customerId);
            }
            if (is_string($reason) && $reason !== '') {
                $row->reason = substr($reason, 0, 60);
            }
            $row->forceFill([
                'plan_slug' => $checkout?->plan_slug ?? $row->plan_slug,
                'billing_cycle' => $checkout?->billing_cycle ?? ($row->billing_cycle ?: 'monthly'),
                'locale' => $checkout?->locale ?? ($row->locale ?: 'ar'),
                'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : $row->stripe_invoice_id,
                'amount_cents' => max(0, (int) ($invoice->amount_due ?? 0)) ?: $row->amount_cents,
                'currency' => strtolower(substr((string) ($invoice->currency ?? ''), 0, 3)) ?: $row->currency,
                'happened_at' => now(),
            ]);
            // Another declined try moves a waiting email later; one already decided stays as it is.
            if ($row->email_status === PurchaseFollowUp::WAITING) {
                $row->email_due_at = now()->addMinutes($minutes);
            }
            $row->save();

            // One email, about the payment: the checkout's own reminder is not needed.
            PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_CHECKOUT_LEFT)
                ->where('email_status', PurchaseFollowUp::WAITING)
                ->update(['email_status' => PurchaseFollowUp::SKIPPED, 'email_note' => 'Their payment was declined: that email goes instead', 'updated_at' => now()]);
        }, null, false);
    }

    /**
     * The bank's reason for a declined payment (Stripe's payment_intent.payment_failed or
     * charge.failed), noted on the member's waiting case.
     */
    public function declineReason(object $payment): void
    {
        if (! PurchaseFollowUp::ready()) {
            return;
        }

        rescue(function () use ($payment): void {
            $customerId = trim((string) ($payment->customer ?? ''));
            $error = $payment->last_payment_error ?? null;
            // The bank's own reason first (insufficient_funds), then Stripe's wider one (card_declined).
            $reason = trim((string) ($error->decline_code ?? $payment->outcome->reason ?? $error->code ?? $payment->failure_code ?? ''));
            $user = $customerId !== '' ? User::query()->where('stripe_id', $customerId)->first() : null;
            if (! $user || $reason === '') {
                return;
            }
            $reason = substr($reason, 0, 60);

            $row = PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)
                ->where('happened_at', '>=', now()->subHour())
                ->latest('id')
                ->first();
            if ($row) {
                $row->forceFill(['reason' => $reason])->save();

                return;
            }

            // The invoice's event has not come yet: kept for when it does.
            Cache::put(self::reasonKey((int) $user->id), $reason, now()->addMinutes(15));
        }, null, false);
    }

    /**
     * The scheduled run: notes who bought a plan since, then sends the emails that are due.
     *
     * @return array{paid: int, sent: int, skipped: int, failed: int}
     */
    public function run(int $limit = 50): array
    {
        $done = ['paid' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        if (! PurchaseFollowUp::ready()) {
            return $done;
        }

        $done['paid'] = $this->notePurchases();

        $due = PurchaseFollowUp::query()
            ->where('email_status', PurchaseFollowUp::WAITING)
            ->where('email_due_at', '<=', now())
            ->orderBy('email_due_at')
            ->limit($limit)
            ->with('user')
            ->get();

        foreach ($due as $row) {
            $why = $this->whyNot($row);
            if ($why !== null) {
                $row->forceFill(['email_status' => $why[0], 'email_note' => $why[1]])->save();
                $done['skipped']++;

                continue;
            }

            try {
                $this->send($row);
                $row->forceFill(['email_status' => PurchaseFollowUp::SENT, 'email_sent_at' => now(), 'email_note' => null])->save();
                $done['sent']++;
            } catch (\Throwable $e) {
                Log::warning('A purchase follow-up email was not sent.', ['id' => $row->id, 'user_id' => $row->user_id, 'error' => $e->getMessage()]);
                $row->forceFill(['email_status' => PurchaseFollowUp::FAILED, 'email_note' => mb_substr($e->getMessage(), 0, 160)])->save();
                $done['failed']++;
            }
        }

        return $done;
    }

    /**
     * Members with an open case who have a paid plan now: the case is marked paid, and an
     * email still waiting is dropped. How many cases were marked.
     */
    public function notePurchases(): int
    {
        $marked = 0;
        $plans = app(PlanAccessService::class);
        $open = PurchaseFollowUp::query()
            ->whereNull('paid_at')
            ->whereNotNull('user_id')
            ->where('happened_at', '>=', now()->subDays(self::PAID_WITHIN_DAYS))
            ->orderBy('id')
            ->limit(1000)
            ->get()
            ->groupBy('user_id');

        foreach ($open as $userId => $rows) {
            $plan = $plans->resolveActivePlanForUserId((int) $userId);
            if (! $plan || (int) $plan->price_cents <= 0) {
                continue;
            }
            foreach ($rows as $row) {
                $row->paid_at = now();
                $row->paid_plan_slug = (string) $plan->slug;
                if ($row->email_status === PurchaseFollowUp::WAITING) {
                    $row->email_status = PurchaseFollowUp::SKIPPED;
                    $row->email_note = 'Bought a plan before the email was due';
                }
                $row->save();
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * Why a due email is not sent: its new state and the note for the admin, or null to send it.
     *
     * @return array{0: string, 1: string}|null
     */
    private function whyNot(PurchaseFollowUp $row): ?array
    {
        $user = $row->user;

        return match (true) {
            ! $this->enabled((string) $row->kind) => [PurchaseFollowUp::OFF, 'This email was turned off'],
            ! $user => [PurchaseFollowUp::SKIPPED, 'The account no longer exists'],
            $user->isBlocked() => [PurchaseFollowUp::SKIPPED, 'The account is blocked'],
            PaidWelcomeEmail::isPhoneSignupAddress((string) $user->email) => [PurchaseFollowUp::SKIPPED, 'Signed up with a phone number: no email address'],
            ! LiveServer::allowsCustomerActions() => [PurchaseFollowUp::SKIPPED, 'Not the live site: only the live site emails members'],
            $row->email_due_at !== null && $row->email_due_at->lt(now()->subHours(self::TOO_LATE_HOURS)) => [PurchaseFollowUp::SKIPPED, 'Came due more than '.self::TOO_LATE_HOURS.' hours ago'],
            PurchaseFollowUp::query()
                ->where('user_id', $row->user_id)
                ->where('id', '!=', $row->id)
                ->where('email_status', PurchaseFollowUp::SENT)
                ->where('email_sent_at', '>=', now()->subDays(self::QUIET_DAYS))
                ->exists() => [PurchaseFollowUp::SKIPPED, 'Already emailed in the last '.self::QUIET_DAYS.' days'],
            default => null,
        };
    }

    /**
     * Sends the case's email to its member. With $testTo it goes to that address instead.
     * Members are emailed from the live site only; a test copy can go anywhere.
     * Throws when it could not be sent.
     */
    public function send(PurchaseFollowUp $row, ?string $testTo = null): void
    {
        $user = $row->user;
        if (! $user instanceof User) {
            throw new InvalidArgumentException('There is no member to send to.');
        }
        if ($testTo === null && ! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('purchase follow-up email', ['user_id' => $user->id]);

            throw new RuntimeException('Not sent: this is not the live site, and only the live site emails members. A test copy to your own address works here.');
        }

        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }
        if (PaidWelcomeEmail::isPhoneSignupAddress($to)) {
            throw new InvalidArgumentException('This member signed up with a phone number, so there is no email address to send to.');
        }

        $newsletter = $this->asNewsletter($this->settings(), $row, (string) $user->name);
        $renderer = app(NewsletterRenderer::class);
        // About their own purchase, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new PurchaseFollowUpMail(
            htmlBody: $renderer->html($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            textBody: $renderer->text($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            subjectLine: $renderer->subject($newsletter, Newsletter::LANGUAGE_BOTH),
            fromAddress: $fromAddress,
            fromName: TitanMailbox::fromName(),
            replyToAddress: TitanMailbox::ADDRESS,
        );

        try {
            (app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan'))->to($to)->send($mail);
        } catch (\Throwable $e) {
            throw new RuntimeException(TitanMailbox::explainFailure($e->getMessage()), 0, $e);
        }
    }

    /**
     * One kind's email as the admin preview shows it, for a sample member and plan.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(string $kind, ?array $settings = null): string
    {
        $newsletter = $this->asNewsletter($settings ?? $this->settings(), $this->sample($kind), '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /** A case that stands in for a real one, in previews and test sends. Never saved. */
    public function sample(string $kind, ?User $user = null): PurchaseFollowUp
    {
        $plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $row = new PurchaseFollowUp([
            'kind' => $kind === PurchaseFollowUp::KIND_PAYMENT_FAILED ? PurchaseFollowUp::KIND_PAYMENT_FAILED : PurchaseFollowUp::KIND_CHECKOUT_LEFT,
            'plan_slug' => $plan?->slug,
            'billing_cycle' => 'monthly',
            'reason' => 'insufficient_funds',
        ]);
        if ($user) {
            $row->setRelation('user', $user);
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, PurchaseFollowUp $row, string $memberName): Newsletter
    {
        $prefix = self::prefix((string) $row->kind);
        $plan = $row->plan_slug ? Plan::query()->where('slug', $row->plan_slug)->first() : null;
        $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'ar_en') === 'en_ar' ? 'en_ar' : 'ar_en',
        ];

        foreach (['en', 'ar'] as $lang) {
            $fill = function (string $text) use ($plan, $lang, $first, $row): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace('{reason}', self::reasonWords($row->reason, $lang), $text);
                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'body', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$prefix.'_'.$field.'_'.$lang] ?? ''));
            }
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = $this->buttonUrl($row, $lang);
        }

        return new Newsletter($fields);
    }

    /**
     * Where the email's button goes. A declined payment: a link of ours that sends the member
     * on to Stripe's own page for that payment while it can still be paid there, and to the
     * checkout once it has lapsed (PurchaseFollowUpLinkController), so the button never leads
     * to a dead page. A checkout left, a preview or a test: the checkout for the plan.
     */
    private function buttonUrl(PurchaseFollowUp $row, string $lang): string
    {
        if ($row->exists && $row->kind === PurchaseFollowUp::KIND_PAYMENT_FAILED && trim((string) $row->stripe_invoice_id) !== '') {
            return URL::temporarySignedRoute('billing.follow-up', now()->addDays(self::LINK_DAYS), ['followUp' => $row->id, 'lang' => $lang]);
        }

        return $this->checkoutUrl($row, $lang);
    }

    /** The checkout for the case's plan and cycle; with no plan known, the plans. */
    public function checkoutUrl(PurchaseFollowUp $row, string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $base = rtrim((string) config('app.url', ''), '/');
        $plan = $row->plan_slug ? Plan::query()->where('slug', $row->plan_slug)->first() : null;

        return $plan
            ? $base.'/'.$lang.'/billing?'.http_build_query(['plan' => (string) $plan->slug, 'billing_cycle' => $row->billing_cycle === 'yearly' ? 'yearly' : 'monthly'])
            : $base.'/'.$lang.'/pricing';
    }

    /**
     * Stripe's page where the declined payment can be paid, while it still can be: the
     * invoice of a first payment stays open for about a day, then Stripe closes it. Null once
     * it is closed or paid, or for a case that is not a declined payment.
     */
    public function paymentPageUrl(PurchaseFollowUp $row): ?string
    {
        $invoiceId = trim((string) $row->stripe_invoice_id);
        if ($row->kind !== PurchaseFollowUp::KIND_PAYMENT_FAILED || $invoiceId === '') {
            return null;
        }

        $url = $this->openInvoiceUrl($invoiceId);

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /** Asks Stripe: the invoice's own payment page if the invoice is still open, else null. */
    protected function openInvoiceUrl(string $invoiceId): ?string
    {
        if (trim((string) config('services.stripe.secret', '')) === '' || app()->runningUnitTests()) {
            return null;
        }

        return rescue(function () use ($invoiceId): ?string {
            $invoice = \Laravel\Cashier\Cashier::stripe()->invoices->retrieve($invoiceId, []);

            return (string) ($invoice->status ?? '') === 'open' ? ((string) ($invoice->hosted_invoice_url ?? '') ?: null) : null;
        }, null, false);
    }

    /** {reason}: a few words after "declined the payment", only when the bank's reason helps the member. */
    public static function reasonWords(?string $reason, string $lang): string
    {
        return match ($reason) {
            'insufficient_funds' => $lang === 'ar' ? ' لعدم كفاية الرصيد في البطاقة' : ' because the card did not have enough balance',
            'expired_card' => $lang === 'ar' ? ' لأن البطاقة منتهية الصلاحية' : ' because the card has expired',
            'incorrect_cvc', 'invalid_cvc' => $lang === 'ar' ? ' لأن رمز الأمان غير صحيح' : ' because the security code was not right',
            'authentication_required', 'authentication_failed' => $lang === 'ar' ? ' لأن تأكيد البنك لم يكتمل' : ' because the bank\'s confirmation was not completed',
            default => '',
        };
    }

    /**
     * The bank's reason for the customer's latest declined payment, read from Stripe
     * (insufficient_funds, expired_card...). Null when Stripe is not set up or says nothing.
     */
    protected function lookUpReason(string $customerId): ?string
    {
        if ($customerId === '' || trim((string) config('services.stripe.secret', '')) === '' || app()->runningUnitTests()) {
            return null;
        }

        return rescue(function () use ($customerId): ?string {
            $payments = \Laravel\Cashier\Cashier::stripe()->paymentIntents->all(['customer' => $customerId, 'limit' => 3]);
            foreach ($payments->data as $payment) {
                $error = $payment->last_payment_error ?? null;
                $reason = trim((string) ($error->decline_code ?? $error->code ?? ''));
                if ($reason !== '') {
                    return $reason;
                }
            }

            return null;
        }, null, false);
    }

    private static function reasonKey(int $userId): string
    {
        return 'purchase_followup:reason:'.$userId;
    }

    private static function prefix(string $kind): string
    {
        return $kind === PurchaseFollowUp::KIND_PAYMENT_FAILED ? 'failed' : 'left';
    }
}
