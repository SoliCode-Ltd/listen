<?php

namespace App\Services\Billing;

use App\Mail\PurchaseFollowUpMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\PurchaseFollowUp;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Services\PlanAccessService;
use App\Support\LiveServer;
use App\Support\StripeInvoice;
use App\Support\TitanMailbox;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
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
 *
 * A third email is for a paying member whose renewal could not be charged
 * (renewalFailed()): the warning App\Services\Billing\RenewalGrace sends the moment it
 * happens, in the same design, with its words edited in the same place and its case in the
 * same list. It is a billing notice, not a follow-up: it has no switch, wait or weekly
 * limit, and when it cannot be sent RenewalGrace sends its plain-text one instead.
 *
 * From the list the team can also act on one case by hand: send its email now (sendNow()),
 * or take a percentage off a declined renewal (giveDiscount()): that lowers what the member
 * owes for that one renewal, and, when asked, the same percentage comes off that member's
 * next renewals for a number of months. From then on the case's email is the one with the
 * offer's words and the lower price. Giving a discount is the only thing here that touches
 * a payment, and it is for the one member whose case it is: nothing anyone else can use.
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

    /** renewalFailed(): the warning went out now. */
    public const RENEWAL_SENT = 'sent';

    /** renewalFailed(): this renewal's warning went out before. */
    public const RENEWAL_ALREADY = 'already';

    /** renewalFailed(): no email for this member or server (the case says why). */
    public const RENEWAL_SKIPPED = 'skipped';

    /** renewalFailed(): it could not be sent, or recorded: the caller sends its plain one. */
    public const RENEWAL_FAILED = 'failed';

    /** A failed renewal with no invoice named is the member's open case from the last this many days. */
    private const RENEWAL_CASE_DAYS = 45;

    /** How long one warning waits for another that is being sent for the same member (seconds). */
    protected const RENEWAL_LOCK_SECONDS = 45;

    /** The most that can be taken off a declined renewal, in percent. */
    public const MAX_DISCOUNT = 90;

    /** The most renewals after the declined one the same discount can cover. */
    public const MAX_DISCOUNT_MONTHS = 12;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            // Off until the owner has read the words and turned them on.
            'failed_enabled' => '0',
            'left_enabled' => '0',
            // The "Give a discount" button on a declined renewal: off until the owner turns it on.
            'discounts_enabled' => '0',
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

            'renewal_subject_en' => 'Payment failed - action required',
            'renewal_subject_ar' => 'لم يكتمل الدفع - مطلوب إجراء منك',
            'renewal_headline_en' => 'Your {plan} plan could not be renewed',
            'renewal_headline_ar' => 'تعذّر تجديد باقة {plan}',
            // A member who paid for the plan before: it stays on for the grace days.
            'renewal_body_en' => "Hi {name}, we tried to renew your {plan} plan, but your bank declined the payment{reason}. Nothing was charged.\n\nYour plan stays on until {date}, with all its paid features. To keep it, press the button below and pay with a card that has enough balance, or with another card.\n\nIf it is still unpaid on {date}, your subscription will be cancelled and your account will move to the free plan.",
            'renewal_body_ar' => "أهلاً {name}، حاولنا تجديد باقة {plan} الخاصة بك، لكن البنك رفض عملية الدفع{reason}. لم يُخصم منك أي مبلغ.\n\nباقتك مستمرة حتى {date} بكل مزاياها المدفوعة. لتحتفظ بها، اضغط الزر أدناه وادفع ببطاقة فيها رصيد كافٍ أو ببطاقة أخرى.\n\nإذا بقي المبلغ غير مدفوع حتى {date}، سيُلغى اشتراكك وينتقل حسابك إلى الباقة المجانية.",
            // No grace (the first charge after a free trial): the paid features paused at once.
            'renewal_paused_body_en' => "Hi {name}, we tried to take the payment for your {plan} plan, but your bank declined it{reason}, so your paid features are paused. Nothing was charged.\n\nTo get them back straight away, press the button below and pay with a card that has enough balance, or with another card.",
            'renewal_paused_body_ar' => "أهلاً {name}، حاولنا تحصيل قيمة باقة {plan}، لكن البنك رفض عملية الدفع{reason}، لذلك توقفت مزاياك المدفوعة مؤقتاً. لم يُخصم منك أي مبلغ.\n\nلتعود مزاياك فوراً، اضغط الزر أدناه وادفع ببطاقة فيها رصيد كافٍ أو ببطاقة أخرى.",
            'renewal_cta_label_en' => 'Pay now',
            'renewal_cta_label_ar' => 'ادفع الآن',
            'renewal_footer_en' => 'Need another way to pay? Just reply to this email and we will help.',
            'renewal_footer_ar' => 'تحتاج طريقة دفع أخرى؟ ردّ على هذه الرسالة وسنساعدك.',

            // A declined renewal the team gave a discount to (giveDiscount()).
            'renewal_offer_subject_en' => '{discount} off your {plan} renewal',
            'renewal_offer_subject_ar' => 'خصم {discount} على تجديد باقة {plan}',
            'renewal_offer_headline_en' => 'Keep your {plan} plan for {price}',
            'renewal_offer_headline_ar' => 'احتفظ بباقة {plan} مقابل {price}',
            'renewal_offer_body_en' => "Hi {name}, we would like you to stay. We have taken {discount} off the renewal of your {plan} plan: you pay {price} instead of {old_price}.{next}\n\nPress the button below to pay the lower price and keep your plan, with every voice, dialect and feature in it.",
            'renewal_offer_body_ar' => "أهلاً {name}، يهمّنا أن تبقى معنا. خصمنا لك {discount} من تجديد باقة {plan}: تدفع {price} بدلاً من {old_price}.{next}\n\nاضغط الزر أدناه لتدفع السعر المخفّض وتحتفظ بباقتك بكل أصواتها ولهجاتها ومزاياها.",
            'renewal_offer_cta_label_en' => 'Pay {price}',
            'renewal_offer_cta_label_ar' => 'ادفع {price}',
            'renewal_offer_footer_en' => 'Questions about your plan or the payment? Just reply to this email.',
            'renewal_offer_footer_ar' => 'لديك سؤال عن باقتك أو عن الدفع؟ ردّ على هذه الرسالة.',
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
        // A failed renewal's warning has no switch: a paying member is always told.
        return $kind === PurchaseFollowUp::KIND_RENEWAL_FAILED
            || $this->settings()[self::prefix($kind).'_enabled'] === '1';
    }

    /**
     * A paying member's renewal could not be charged: the case is recorded and its warning
     * sent now (RenewalGrace::warn() decides when). One case for a renewal: the same
     * invoice failing again, or a warning tried again after it could not be sent, keeps its
     * case, and a warning that went out is not sent twice.
     *
     * @return string RENEWAL_SENT, RENEWAL_ALREADY, RENEWAL_SKIPPED, or RENEWAL_FAILED when
     *                the caller should send its plain-text warning instead
     */
    public function renewalFailed(User $user, Subscription $plan, ?object $invoice = null): string
    {
        if (! PurchaseFollowUp::ready()) {
            return self::RENEWAL_FAILED;
        }

        // The failed payment and the subscription falling behind reach the site as two events,
        // often in the same second: one of them warns the member, the other waits and finds it done.
        $lock = rescue(fn () => Cache::lock('purchase_followup:renewal_warning:'.$user->id, static::RENEWAL_LOCK_SECONDS + 75), null, false);
        try {
            $lock?->block(static::RENEWAL_LOCK_SECONDS);
        } catch (LockTimeoutException) {
            return self::RENEWAL_SKIPPED;
        } catch (\Throwable) {
            // A cache that cannot lock: the warning still goes.
            $lock = null;
        }

        try {
            return $this->warnOfRenewal($user, $plan, $invoice);
        } finally {
            rescue(fn () => $lock?->release(), null, false);
        }
    }

    private function warnOfRenewal(User $user, Subscription $plan, ?object $invoice): string
    {
        $row = null;
        try {
            $kind = PurchaseFollowUp::KIND_RENEWAL_FAILED;
            $invoiceId = trim((string) ($invoice->id ?? ''));
            // A case that was paid is closed: a renewal failing after it is a new one.
            $row = ($invoiceId !== '' ? PurchaseFollowUp::query()->where('kind', $kind)->where('stripe_invoice_id', $invoiceId)->whereNull('paid_at')->latest('id')->first() : null)
                ?? PurchaseFollowUp::query()
                    ->where('user_id', $user->id)
                    ->where('kind', $kind)
                    ->whereNull('paid_at')
                    ->where('happened_at', '>=', now()->subDays(self::RENEWAL_CASE_DAYS))
                    ->when($invoiceId !== '', fn ($query) => $query->whereNull('stripe_invoice_id'))
                    ->latest('id')
                    ->first()
                ?? new PurchaseFollowUp(['user_id' => $user->id, 'kind' => $kind, 'happened_at' => now()]);
            if ($row->email_status === PurchaseFollowUp::SENT) {
                return self::RENEWAL_ALREADY;
            }

            $plan->loadMissing('plan');
            $customerId = trim((string) ($invoice->customer ?? $user->stripe_id ?? ''));
            $reason = Cache::pull(self::reasonKey((int) $user->id));
            if (! is_string($reason) || $reason === '') {
                $reason = $row->reason ?: $this->lookUpReason($customerId);
            }
            $row->forceFill([
                'plan_slug' => $plan->plan?->slug ?? $row->plan_slug,
                'billing_cycle' => self::cycleOf($invoice) ?? ($row->billing_cycle ?: 'monthly'),
                'locale' => $row->locale ?: 'ar',
                'reason' => is_string($reason) && $reason !== '' ? substr($reason, 0, 60) : $row->reason,
                'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : $row->stripe_invoice_id,
                'amount_cents' => max(0, (int) ($invoice->amount_due ?? 0)) ?: $row->amount_cents,
                'currency' => strtolower(substr((string) ($invoice->currency ?? ''), 0, 3)) ?: $row->currency,
                'email_due_at' => null,
            ]);
            $row->setRelation('user', $user);

            $why = match (true) {
                ! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) => 'No email address on the account',
                PaidWelcomeEmail::isPhoneSignupAddress((string) $user->email) => 'Signed up with a phone number: no email address',
                ! LiveServer::allowsCustomerActions() => 'Not the live site: only the live site emails members',
                default => null,
            };
            if ($why !== null) {
                $row->forceFill(['email_status' => PurchaseFollowUp::SKIPPED, 'email_note' => $why])->save();

                return self::RENEWAL_SKIPPED;
            }

            // Saved first: the email's button names the case.
            $row->forceFill(['email_status' => PurchaseFollowUp::FAILED, 'email_note' => 'Not sent yet'])->save();
            $this->send($row);
            $row->forceFill(['email_status' => PurchaseFollowUp::SENT, 'email_sent_at' => now(), 'email_note' => null])->save();

            return self::RENEWAL_SENT;
        } catch (\Throwable $e) {
            Log::warning('A failed renewal\'s email was not sent in the email design; the plain one goes instead.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            rescue(function () use ($row, $e): void {
                if ($row?->exists) {
                    $row->forceFill(['email_status' => PurchaseFollowUp::FAILED, 'email_note' => mb_substr($e->getMessage(), 0, 160)])->save();
                }
            }, null, false);

            return self::RENEWAL_FAILED;
        }
    }

    /** The plain-text warning went out in place of the designed one: the case says so. */
    public function renewalWarnedPlainly(User $user): void
    {
        if (! PurchaseFollowUp::ready()) {
            return;
        }

        rescue(function () use ($user): void {
            PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_RENEWAL_FAILED)
                ->where('email_status', PurchaseFollowUp::FAILED)
                ->whereNull('paid_at')
                ->latest('id')
                ->first()
                ?->forceFill(['email_status' => PurchaseFollowUp::SENT, 'email_sent_at' => now(), 'email_note' => 'Sent as a plain email'])
                ->save();
        }, null, false);
    }

    /** The member's missed renewal is paid: their open cases say so. */
    public function renewalPaid(User $user): void
    {
        if (! PurchaseFollowUp::ready()) {
            return;
        }

        rescue(function () use ($user): void {
            $plan = app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);
            PurchaseFollowUp::query()
                ->where('user_id', $user->id)
                ->where('kind', PurchaseFollowUp::KIND_RENEWAL_FAILED)
                ->whereNull('paid_at')
                ->update(['paid_at' => now(), 'paid_plan_slug' => $plan?->slug, 'updated_at' => now()]);
        }, null, false);
    }

    /**
     * Sends a case's email to its member now, whatever its switch, wait or state: the team
     * asked for it from the list. A declined renewal with a discount gets the offer's words,
     * one without gets the warning. Throws, with the reason, when it could not be sent.
     */
    public function sendNow(PurchaseFollowUp $row): void
    {
        $row->loadMissing('user');
        $this->send($row);
        $row->forceFill(['email_status' => PurchaseFollowUp::SENT, 'email_sent_at' => now(), 'email_note' => 'Sent by hand'])->save();

        // The member has been told of the missed renewal: the automatic warning is not owed any more.
        if ($row->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED && $row->user_id) {
            Subscription::query()
                ->where('user_id', $row->user_id)
                ->where('billing_payment_state', 'past_due')
                ->whereNull('billing_failed_notice_sent_at')
                ->update(['billing_failed_notice_sent_at' => now()]);
        }
    }

    /** The team may give discounts on declined renewals: the switch is on and the database is ready. */
    public function discountsOn(): bool
    {
        return PurchaseFollowUp::discountsReady() && $this->settings()['discounts_enabled'] === '1';
    }

    /**
     * Takes $percent off what the member owes for this declined renewal: the unpaid payment
     * itself is lowered, so its payment page shows the lower price and the card is tried
     * again for the lower price. With $months, the same percentage also comes off that
     * member's next $months renewals (a monthly plan only): a coupon made for this one case,
     * put on their subscription, that nobody else can use. With 0 it is this renewal only.
     *
     * Both or neither: the months are added first and taken back if the payment cannot then
     * be lowered. The case keeps the discount, and its email is the offer's from now on.
     * Never on a server that is not the live site: it changes a real payment. Throws, with
     * the reason, when it was not given.
     */
    public function giveDiscount(PurchaseFollowUp $row, int $percent, int $months = 0): void
    {
        if (! PurchaseFollowUp::discountsReady()) {
            throw new RuntimeException('Not given: the database is not ready for discounts yet (its migration has not been run).');
        }
        if (! $this->discountsOn()) {
            throw new RuntimeException('Not given: discounts on declined renewals are switched off (Emails and settings).');
        }
        if ($row->kind !== PurchaseFollowUp::KIND_RENEWAL_FAILED) {
            throw new InvalidArgumentException('A discount is for a declined renewal.');
        }
        if ($percent < 1 || $percent > self::MAX_DISCOUNT) {
            throw new InvalidArgumentException('The discount must be between 1 and '.self::MAX_DISCOUNT.' percent.');
        }
        if ($months < 0 || $months > self::MAX_DISCOUNT_MONTHS) {
            throw new InvalidArgumentException('The discount can cover up to '.self::MAX_DISCOUNT_MONTHS.' renewals after this one.');
        }
        if ($row->paid_at !== null) {
            throw new RuntimeException('Not given: this renewal has been paid.');
        }
        if ($row->hasDiscount()) {
            throw new RuntimeException('Not given: this renewal already has '.$row->discount_percent.'% off.');
        }
        if (! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('a discount on a declined renewal', ['user_id' => $row->user_id]);

            throw new RuntimeException('Not given: this is not the live site. A discount changes a real payment, so only the live site gives one.');
        }
        $invoiceId = trim((string) $row->stripe_invoice_id);
        if ($invoiceId === '') {
            throw new RuntimeException('Not given: this case does not name the unpaid payment, so it cannot be lowered from here.');
        }

        // Everything is checked before anything is changed.
        $unpaid = $this->unpaidInvoice($invoiceId);
        if ($unpaid === null) {
            throw new RuntimeException('Not given: the payment is no longer waiting to be paid (it was paid or closed).');
        }
        $off = (int) round($unpaid['amount'] * $percent / 100);
        if ($off < 1 || $off >= $unpaid['amount']) {
            throw new RuntimeException('Not given: '.$percent.'% of what is owed leaves nothing to pay, or takes nothing off.');
        }
        $subscriptionId = trim((string) ($unpaid['subscription'] ?? ''));
        if ($months > 0) {
            $onlyThis = ' Choose "this renewal only" to lower this payment.';
            $subscription = $subscriptionId !== '' ? $this->subscriptionState($subscriptionId) : null;
            if ($subscription === null || ! $subscription['open']) {
                throw new RuntimeException('Not given: their subscription has ended, so there are no later renewals to discount.'.$onlyThis);
            }
            if (! $subscription['monthly']) {
                throw new RuntimeException('Not given: later renewals can be discounted on a plan that renews every month, and this one does not.'.$onlyThis);
            }
            if ($subscription['discounted']) {
                throw new RuntimeException('Not given: their subscription already has a discount running on its renewals, and a second one would be added on top of it.'.$onlyThis);
            }
        }

        $key = 'listen-renewal-discount-'.$row->id;
        $couponId = $months > 0 ? $this->discountRenewals($subscriptionId, $percent, $months, $key) : null;
        try {
            $this->lowerInvoice($invoiceId, $off, $percent.'% off this renewal', $key);
        } catch (\Throwable $e) {
            $undone = $couponId === null || rescue(fn (): bool => $this->undiscountRenewals($subscriptionId, $couponId) || true, false, false);
            Log::warning('A discount on a declined renewal could not be given.', ['follow_up_id' => $row->id, 'months_taken_back' => $undone, 'error' => $e->getMessage()]);

            throw new RuntimeException($undone
                ? 'Not given: the payment could not be lowered ('.mb_substr($e->getMessage(), 0, 200).'). Nothing was changed.'
                : 'The payment could not be lowered ('.mb_substr($e->getMessage(), 0, 160).'), but '.$percent.'% off their next '.$months.' renewals was added and could not be taken back: remove that discount from their subscription by hand.', 0, $e);
        }

        $row->forceFill([
            'amount_cents' => $unpaid['amount'],
            'currency' => $unpaid['currency'] ?: $row->currency,
            'discount_percent' => $percent,
            'discount_cents' => $off,
            'discount_months' => $months > 0 ? $months : null,
            'discount_coupon_id' => $couponId,
            'discounted_at' => now(),
        ])->save();
        Log::info('A declined renewal was given a discount.', ['follow_up_id' => $row->id, 'user_id' => $row->user_id, 'percent' => $percent, 'off_cents' => $off, 'months' => $months]);
    }

    /**
     * Asks Stripe what is still owed on the payment: its amount (in the currency's smallest
     * unit), currency and subscription while it waits to be paid, or null once it is paid
     * or closed.
     *
     * @return array{amount: int, currency: string, subscription: string}|null
     */
    protected function unpaidInvoice(string $invoiceId): ?array
    {
        $invoice = Cashier::stripe()->invoices->retrieve($invoiceId, []);
        $owed = (int) ($invoice->amount_remaining ?? 0);

        return (string) ($invoice->status ?? '') === 'open' && $owed > 0
            ? ['amount' => $owed, 'currency' => strtolower((string) ($invoice->currency ?? '')), 'subscription' => StripeInvoice::subscriptionId($invoice)]
            : null;
    }

    /**
     * Asks Stripe about the subscription: whether it is still running (not ended), whether
     * it renews every month, and whether a discount is already on its renewals.
     *
     * @return array{open: bool, monthly: bool, discounted: bool}|null null when it is not there
     */
    protected function subscriptionState(string $subscriptionId): ?array
    {
        $subscription = Cashier::stripe()->subscriptions->retrieve($subscriptionId, []);
        $recurring = $subscription->items->data[0]->price->recurring ?? null;

        return [
            'open' => in_array((string) ($subscription->status ?? ''), ['active', 'past_due', 'unpaid', 'trialing'], true),
            'monthly' => (string) ($recurring->interval ?? '') === 'month' && (int) ($recurring->interval_count ?? 1) === 1,
            'discounted' => ! empty($subscription->discounts) || ! empty($subscription->discount),
        ];
    }

    /**
     * Takes $offCents off the unpaid payment at Stripe (a credit note on the open invoice:
     * what is owed drops by that much, and nothing is refunded as nothing was paid). The
     * key makes a second try of the same discount do nothing more. Stripe's own email about
     * the credit note is not sent: the member hears of the discount in the offer's email.
     */
    protected function lowerInvoice(string $invoiceId, int $offCents, string $memo, string $idempotencyKey): void
    {
        Cashier::stripe()->creditNotes->create(
            ['invoice' => $invoiceId, 'amount' => $offCents, 'memo' => $memo, 'email_type' => 'none'],
            ['idempotency_key' => $idempotencyKey],
        );
    }

    /**
     * Puts $percent off the subscription's next $months renewals at Stripe: a coupon made
     * for this one case (it can be used once, here), on this one subscription. The payment
     * already waiting is not touched by it (lowerInvoice() lowers that one). The coupon's id.
     */
    protected function discountRenewals(string $subscriptionId, int $percent, int $months, string $idempotencyKey): string
    {
        $stripe = Cashier::stripe();
        $coupon = $stripe->coupons->create([
            'percent_off' => $percent,
            'duration' => 'repeating',
            'duration_in_months' => $months,
            'max_redemptions' => 1,
            'name' => $percent.'% off, '.$months.' '.($months === 1 ? 'renewal' : 'renewals'),
            'metadata' => ['listen' => 'renewal discount', 'key' => $idempotencyKey],
        ], ['idempotency_key' => $idempotencyKey.'-coupon']);
        $couponId = (string) $coupon->id;

        try {
            $stripe->subscriptions->update($subscriptionId, [
                'discounts' => [['coupon' => $couponId]],
                'proration_behavior' => 'none',
            ], ['idempotency_key' => $idempotencyKey.'-renewals']);
        } catch (\Throwable $e) {
            rescue(fn () => $stripe->coupons->delete($couponId, []), null, false);

            throw $e;
        }

        return $couponId;
    }

    /** Takes the renewals' discount back off the subscription, and removes its coupon. */
    protected function undiscountRenewals(string $subscriptionId, string $couponId): bool
    {
        $stripe = Cashier::stripe();
        $stripe->subscriptions->update($subscriptionId, ['discounts' => '', 'proration_behavior' => 'none']);
        rescue(fn () => $stripe->coupons->delete($couponId, []), null, false);

        return true;
    }

    /** {next}: the sentence saying the discount also covers the next renewals, or nothing. */
    public static function nextRenewalsWords(?int $months, string $lang): string
    {
        $months = (int) $months;
        if ($months <= 0) {
            return '';
        }
        if ($lang === 'ar') {
            return match (true) {
                $months === 1 => ' ويُطبَّق الخصم نفسه على تجديدك القادم أيضاً.',
                $months === 2 => ' ويُطبَّق الخصم نفسه على تجديدَيك القادمَين أيضاً.',
                default => ' ويُطبَّق الخصم نفسه على تجديداتك الـ'.$months.' القادمة أيضاً.',
            };
        }

        return $months === 1
            ? ' The same discount also comes off your next renewal.'
            : ' The same discount also comes off your next '.$months.' renewals.';
    }

    /** An amount as a member reads it: "$49.00", or "49.00 EGP" in another currency. */
    public static function money(?int $cents, ?string $currency): string
    {
        if ($cents === null) {
            return '';
        }
        $amount = number_format($cents / 100, 2, '.', ',');
        $currency = strtolower(trim((string) $currency));

        return $currency === '' || $currency === 'usd' ? '$'.$amount : $amount.' '.strtoupper($currency);
    }

    /** 'yearly' or 'monthly' from the invoice's own period, or null when it does not say. */
    private static function cycleOf(?object $invoice): ?string
    {
        $period = $invoice?->lines?->data[0]?->period ?? null;
        $start = (int) ($period->start ?? 0);
        $end = (int) ($period->end ?? 0);
        if ($start <= 0 || $end <= $start) {
            return null;
        }

        return ($end - $start) > 62 * 86400 ? 'yearly' : 'monthly';
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
                ->whereIn('kind', [PurchaseFollowUp::KIND_PAYMENT_FAILED, PurchaseFollowUp::KIND_RENEWAL_FAILED])
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
            // A failed renewal's member has their plan all along: renewalPaid() marks those.
            ->where('kind', '!=', PurchaseFollowUp::KIND_RENEWAL_FAILED)
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

        $email = $this->emailFor($row);

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new PurchaseFollowUpMail(
            htmlBody: $email['html'],
            textBody: $email['text'],
            subjectLine: $email['subject'],
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
     * A case's email exactly as its member gets it now: the subject, the HTML and text, and
     * the address its button opens in each language (send() sends this very email). For the
     * list's "See the email", so the words, the amounts and the link can be checked before
     * anything is sent.
     *
     * @return array{subject: string, html: string, text: string, button_en: string, button_ar: string}
     */
    public function emailFor(PurchaseFollowUp $row): array
    {
        $name = (string) ($row->user?->name ?? '');
        $newsletter = $this->asNewsletter($this->settings(), $row, $name);
        $renderer = app(NewsletterRenderer::class);
        // About their own purchase, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => $name, 'no_greeting' => true];

        return [
            'subject' => $renderer->subject($newsletter, Newsletter::LANGUAGE_BOTH),
            'html' => $renderer->html($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            'text' => $renderer->text($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            'button_en' => (string) $newsletter->cta_url_en,
            'button_ar' => (string) $newsletter->cta_url_ar,
        ];
    }

    /**
     * Where a case's button takes the member right now, in plain words and with the address:
     * the payment's own page while it waits to be paid (there the price after any discount
     * shows), else the page the button falls back to.
     *
     * @return array{label: string, url: string}
     */
    public function buttonLeadsTo(PurchaseFollowUp $row, string $lang = 'en'): array
    {
        $payment = $row->exists ? rescue(fn () => $this->paymentPageUrl($row), null, false) : null;
        if ($payment !== null) {
            return ['label' => 'The payment page for this payment (it shows what is owed now)', 'url' => $payment];
        }

        return [
            'label' => $row->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED
                ? 'Their own subscription page (the payment is not waiting to be paid, or it is not named on this case)'
                : 'The checkout for the plan (the payment is no longer waiting to be paid)',
            'url' => $this->fallbackUrl($row, $lang),
        ];
    }

    /**
     * One kind's email as the admin preview shows it, for a sample member and plan.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(string $kind, ?array $settings = null, bool $withDiscount = false): string
    {
        $newsletter = $this->asNewsletter($settings ?? $this->settings(), $this->sample($kind, null, $withDiscount), '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /**
     * A case that stands in for a real one, in previews and test sends. Never saved.
     * $withDiscount: a declined renewal with 20% off it and the next 3, to show the offer's words.
     */
    public function sample(string $kind, ?User $user = null, bool $withDiscount = false): PurchaseFollowUp
    {
        $plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $row = new PurchaseFollowUp([
            'kind' => in_array($kind, [PurchaseFollowUp::KIND_PAYMENT_FAILED, PurchaseFollowUp::KIND_RENEWAL_FAILED], true) ? $kind : PurchaseFollowUp::KIND_CHECKOUT_LEFT,
            'plan_slug' => $plan?->slug,
            'billing_cycle' => 'monthly',
            'reason' => 'insufficient_funds',
        ]);
        if ($kind === PurchaseFollowUp::KIND_RENEWAL_FAILED) {
            $row->sampleKeptUntil = now()->addDays(RenewalGrace::graceDays());
            $row->amount_cents = max(0, (int) ($plan?->price_cents ?? 0)) ?: null;
            $row->currency = 'usd';
            if ($withDiscount && $row->amount_cents) {
                $row->discount_percent = 20;
                $row->discount_cents = (int) round($row->amount_cents * 0.2);
                $row->discount_months = 3;
            }
        }
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
        // A declined renewal the team gave a discount to has the offer's words, not the warning's.
        $offer = $row->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED && $row->hasDiscount();
        $prefix = $offer ? 'renewal_offer' : self::prefix((string) $row->kind);
        $plan = $row->plan_slug ? Plan::query()->where('slug', $row->plan_slug)->first() : null;
        $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'ar_en') === 'en_ar' ? 'en_ar' : 'ar_en',
        ];

        // A failed renewal: the day the plan stays on until, or none (the paid features paused).
        $keptUntil = $row->planKeptUntil();
        $body = ! $offer && $row->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED && $keptUntil === null ? 'paused_body' : 'body';
        $amounts = [
            '{discount}' => $offer ? $row->discount_percent.'%' : '',
            '{price}' => self::money($row->priceCents(), $row->currency),
            '{old_price}' => self::money($row->amount_cents, $row->currency),
        ];

        foreach (['en', 'ar'] as $lang) {
            $date = $keptUntil !== null ? Carbon::instance($keptUntil)->locale($lang)->translatedFormat('j F Y') : '';
            $next = $offer ? self::nextRenewalsWords($row->discount_months, $lang) : '';
            $fill = function (string $text) use ($plan, $lang, $first, $row, $date, $amounts, $next): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace(['{reason}', '{date}'], [self::reasonWords($row->reason, $lang), $date], $text);
                $text = strtr($text, $amounts + ['{next}' => $next]);
                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'body', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$prefix.'_'.($field === 'body' ? $body : $field).'_'.$lang] ?? ''));
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
        if ($row->exists && $row->isDeclinedPayment() && trim((string) $row->stripe_invoice_id) !== '') {
            return URL::temporarySignedRoute('billing.follow-up', now()->addDays(self::LINK_DAYS), ['followUp' => $row->id, 'lang' => $lang]);
        }

        return $this->fallbackUrl($row, $lang);
    }

    /**
     * Where a case's member goes when Stripe's page for the payment is not there to pay on:
     * the checkout for the plan they nearly bought, or, for a renewal, their own
     * subscription page (they have the plan: there they change the card and pay).
     */
    public function fallbackUrl(PurchaseFollowUp $row, string $lang): string
    {
        return $row->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED
            ? rtrim((string) config('app.url', ''), '/').'/'.($lang === 'en' ? 'en' : 'ar').'/manage-subscription'
            : $this->checkoutUrl($row, $lang);
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
        if (! $row->isDeclinedPayment() || $invoiceId === '') {
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
            $invoice = Cashier::stripe()->invoices->retrieve($invoiceId, []);

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
            $payments = Cashier::stripe()->paymentIntents->all(['customer' => $customerId, 'limit' => 3]);
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
        return match ($kind) {
            PurchaseFollowUp::KIND_PAYMENT_FAILED => 'failed',
            PurchaseFollowUp::KIND_RENEWAL_FAILED => 'renewal',
            default => 'left',
        };
    }
}
