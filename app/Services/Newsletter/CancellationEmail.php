<?php

namespace App\Services\Newsletter;

use App\Mail\CancellationMail;
use App\Models\BillingSubscription;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Support\LiveServer;
use App\Support\TitanMailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * The email a member gets the moment they cancel their subscription: it will not renew,
 * nothing more is charged, the plan stays on until its end date, and a button to keep
 * it. Between the two, a paragraph that answers the reason they chose in the cancel
 * dialog, with something real to stay for: a smaller plan or paying yearly when the
 * price was the reason (only what their plan really offers), help choosing a voice,
 * help with a problem, or simply the way back. A member who cancelled another way (in
 * Stripe) gave no reason, and is asked to reply.
 *
 * In Arabic and English, in the newsletter's email design, from the help mailbox so
 * replies reach the team. Its words are edited in Newsletter → Cancellation.
 * Sent by StripeWebhookEmailService, once per cancellation.
 */
class CancellationEmail
{
    public const GROUP = 'cancellation_email';

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            'order' => 'ar_en',
            'subject_en' => "Your {plan} plan won't renew",
            'subject_ar' => 'تم إلغاء تجديد باقة {plan}',
            'headline_en' => "Your {plan} plan won't renew",
            'headline_ar' => 'تم إلغاء تجديد باقة {plan}',
            'body_en' => "Hi {name}, we've cancelled the renewal of your {plan} plan, as you asked. You won't be charged again, and your plan keeps all its features until {date}. After that, your account moves to the free plan.",
            'body_ar' => 'أهلاً {name}، ألغينا تجديد اشتراكك في باقة {plan} كما طلبت. لن يُخصم منك أي مبلغ بعد الآن، وتبقى باقتك بكل مزاياها حتى {date}، ثم ينتقل حسابك إلى الباقة المجانية.',
            // The paragraph for the reason the member chose (SubscriptionCancellation::REASONS).
            // The price: an opening, then only what their plan really offers, then where to change it.
            'reason_price_en' => "If the price was the reason, you may not need to leave to pay less.",
            'reason_price_ar' => 'إذا كان السعر هو السبب، فقد لا تحتاج إلى المغادرة لتدفع أقل.',
            'price_smaller_en' => 'You can move to a smaller plan with fewer credits a month.',
            'price_smaller_ar' => 'يمكنك الانتقال إلى باقة أصغر برصيد شهري أقل.',
            'price_yearly_en' => 'Paying yearly saves you {percent}% compared with paying monthly.',
            'price_yearly_ar' => 'ويوفّر لك الدفع السنوي {percent}% مقارنةً بالدفع الشهري.',
            'price_change_en' => 'You can change your plan any time before {date}, from Manage subscription.',
            'price_change_ar' => 'ويمكنك تغيير باقتك في أي وقت قبل {date} من صفحة إدارة الاشتراك.',
            // The price, when their plan has neither (the smallest plan, already paid yearly).
            'price_fallback_en' => "Thank you for telling us. It helps us keep Listen within everyone's reach. If a plan or a price would work better for you, just reply to this email and tell us.",
            'price_fallback_ar' => 'شكرًا لأنك أخبرتنا، فرأيك يساعدنا على أن يبقى إسمع في متناول الجميع. وإن كانت هناك باقة أو سعر يناسبك أكثر، ردّ على هذه الرسالة وأخبرنا.',
            'reason_quality_en' => "If a voice didn't sound the way you wanted, there is still a lot to try: more than 200 dialects and accents, and for every voice you choose the emotion, the speaking style and the speed. Reply with the text you are working on and the dialect you need, and we'll suggest the voice that fits it best.",
            'reason_quality_ar' => 'إذا لم يكن الصوت كما أردت، فما زال هناك الكثير لتجربه: أكثر من 200 لهجة ولكنة، ولكل صوت تختار الشعور وأسلوب الأداء والسرعة. ردّ على هذه الرسالة بالنص الذي تعمل عليه واللهجة التي تحتاجها، وسنقترح عليك الصوت الأنسب له.',
            'reason_technical_en' => "We're sorry something didn't work as it should. Reply to this email and tell us what happened (a screenshot helps a lot): we read every message ourselves and we'll fix it with you. Your plan stays on until {date}, so you can keep using it while we do.",
            'reason_technical_ar' => 'نأسف لأن شيئًا لم يعمل كما ينبغي. ردّ على هذه الرسالة وأخبرنا بما حدث (وصورة الشاشة تساعدنا كثيرًا)، فنحن نقرأ كل رسالة بأنفسنا وسنحلّ المشكلة معك. وتبقى باقتك مفعّلة حتى {date}، فيمكنك الاستمرار في استخدامها حتى نحلّها.',
            'reason_not_needed_en' => "That's fine: you can come back whenever you need a voice-over again. Until {date} your plan keeps all its features, so it's a good time to make anything you have planned.",
            'reason_not_needed_ar' => 'لا بأس، يمكنك العودة متى احتجت إلى تعليق صوتي من جديد. وحتى {date} تبقى باقتك بكل مزاياها، فهو وقت مناسب لإنجاز ما خططت له.',
            'reason_other_en' => "Thank you for telling us. If you'd like to say more, just reply to this email: we read every message ourselves.",
            'reason_other_ar' => 'شكرًا لأنك أخبرتنا. وإن أردت أن تضيف شيئًا، يكفي أن تردّ على هذه الرسالة، فنحن نقرأ كل رسالة بأنفسنا.',
            // Cancelled another way (in Stripe), with no reason given.
            'reason_none_en' => "If something wasn't what you hoped for, reply to this email and tell us: we read every message ourselves, and we'll help right away.",
            'reason_none_ar' => 'إذا لم يكن شيء كما توقعت، ردّ على هذه الرسالة وأخبرنا، فنحن نقرأ كل رسالة بأنفسنا وسنساعدك فورًا.',
            'keep_en' => 'Changed your mind? You can turn renewal back on any time before {date}:',
            'keep_ar' => 'إذا غيّرت رأيك قبل {date}، يمكنك إعادة تفعيل التجديد بضغطة واحدة:',
            'cta_label_en' => 'Keep my plan',
            'cta_label_ar' => 'أبقِ اشتراكي',
            'footer_en' => 'Thanks for being with us. The Listen team',
            'footer_ar' => 'شكرًا لأنك كنت معنا. فريق إسمع',
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
            if (array_key_exists($key, $data)) {
                $value = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : trim((string) $data[$key]);
                SiteSetting::set(self::GROUP.'_'.$key, $value, self::GROUP);
            }
        }
    }

    public function enabled(): bool
    {
        return $this->settings()['enabled'] === '1';
    }

    /**
     * Sends the email for a plan that ends at $endsAt. $reason is what the member chose in
     * the cancel dialog (SubscriptionCancellation::REASONS), null when they cancelled another
     * way. With $testTo it goes to that address instead. Members are emailed from the live
     * site only; a test copy can go anywhere. Throws when it could not be sent, so the caller
     * can try again.
     */
    public function send(User $user, ?Plan $plan, \DateTimeInterface $endsAt, ?string $reason, ?string $testTo = null): void
    {
        if ($testTo === null && ! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('cancellation email', ['user_id' => $user->id]);

            throw new RuntimeException('Not sent: this is not the live site, and only the live site emails members. A test copy to your own address works here.');
        }

        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }
        if (PaidWelcomeEmail::isPhoneSignupAddress($to)) {
            throw new InvalidArgumentException('This member signed up with a phone number, so there is no email address to send to.');
        }

        $newsletter = $this->asNewsletter($this->settings(), $plan, $endsAt, $reason, $this->offersFor($user, $plan), (string) $user->name);
        $renderer = app(NewsletterRenderer::class);
        // A billing message, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new CancellationMail(
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
     * What this member could really do instead of leaving when the price was the reason:
     * a smaller paid plan exists below theirs, and paying yearly saves (their plan has a
     * yearly price, the saving is set, and they pay monthly today).
     *
     * @return array{smaller: bool, yearly_percent: int|null}
     */
    public function offersFor(?User $user, ?Plan $plan): array
    {
        if ($plan === null) {
            return ['smaller' => false, 'yearly_percent' => null];
        }

        $smaller = Plan::query()->subscriptionPlans()->where('is_active', true)
            ->where('price_cents', '>', 0)->where('price_cents', '<', (int) $plan->price_cents)
            ->exists();

        $percent = SiteSetting::yearlyDiscountPercent();
        $paysYearly = $user !== null && trim((string) $plan->stripe_price_yearly) !== ''
            && BillingSubscription::query()->where('user_id', $user->id)->where('type', 'default')
                ->latest('id')->value('stripe_price') === $plan->stripe_price_yearly;
        $yearly = $percent > 0 && trim((string) $plan->stripe_price_yearly) !== '' && ! $paysYearly ? $percent : null;

        return ['smaller' => $smaller, 'yearly_percent' => $yearly];
    }

    /**
     * The email shown in the admin preview, for a sample member whose plan ends in 20 days,
     * as it reads for $reason (null: cancelled in Stripe, no reason). The price paragraph
     * shows every option, as for a member who has both.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(?array $settings = null, ?string $reason = null): string
    {
        $settings ??= $this->settings();
        $sample = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $offers = ['smaller' => true, 'yearly_percent' => max(1, SiteSetting::yearlyDiscountPercent())];
        $newsletter = $this->asNewsletter($settings, $sample, now()->addDays(20), $reason, $offers, '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /**
     * The paragraph answering the member's reason, in one language.
     *
     * @param  array<string, string>  $settings
     * @param  array{smaller: bool, yearly_percent: int|null}  $offers
     */
    public static function reasonParagraph(array $settings, ?string $reason, array $offers, string $lang): string
    {
        $text = fn (string $key): string => trim((string) ($settings[$key.'_'.$lang] ?? ''));

        if ($reason === 'price') {
            $options = array_filter([
                $offers['smaller'] ? $text('price_smaller') : '',
                $offers['yearly_percent'] !== null ? str_replace('{percent}', (string) $offers['yearly_percent'], $text('price_yearly')) : '',
            ]);
            if ($options === []) {
                return $text('price_fallback');
            }

            return implode(' ', array_filter([$text('reason_price'), ...$options, $text('price_change')]));
        }

        return $text('reason_'.(in_array($reason, SubscriptionCancellation::REASONS, true) ? $reason : 'none'));
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array{smaller: bool, yearly_percent: int|null}  $offers
     */
    private function asNewsletter(array $settings, ?Plan $plan, \DateTimeInterface $endsAt, ?string $reason, array $offers, string $memberName): Newsletter
    {
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'ar_en') === 'en_ar' ? 'en_ar' : 'ar_en',
        ];

        foreach (['en', 'ar'] as $lang) {
            $date = Carbon::instance($endsAt)->locale($lang)->translatedFormat('j F Y');
            $fill = function (string $text) use ($plan, $lang, $date, $memberName): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace('{date}', $date, $text);

                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$field.'_'.$lang] ?? ''));
            }
            // What happened, then the answer to their reason, then the way back just above its button.
            $fields['body_'.$lang] = implode("\n\n", array_filter(array_map('trim', [
                $fill((string) ($settings['body_'.$lang] ?? '')),
                $fill(self::reasonParagraph($settings, $reason, $offers, $lang)),
                $fill((string) ($settings['keep_'.$lang] ?? '')),
            ])));
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = rtrim((string) config('app.url', ''), '/').'/'.$lang.'/manage-subscription';
        }

        return new Newsletter($fields);
    }
}
