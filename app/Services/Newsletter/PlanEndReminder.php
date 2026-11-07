<?php

namespace App\Services\Newsletter;

use App\Mail\PlanEndReminderMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LiveServer;
use App\Support\TitanMailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * The reminder a plan that does not renew by itself gets before it ends: one paid by
 * InstaPay or Vodafone Cash, or given by the team. A card plan renews by itself and
 * Stripe sends its own reminder, so it never gets this one
 * (BillingLifecycleService::sendPreRenewalReminders()).
 *
 * In Arabic and English, in the newsletter's email design. Its words, and how many
 * days before the end each of its two reminders goes out, are edited in
 * Newsletter → Reminder.
 */
class PlanEndReminder
{
    public const GROUP = 'plan_reminder';

    /** The most days before the end a reminder can go out. */
    public const MAX_DAYS = 30;

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            'order' => 'ar_en',
            // A monthly plan paid by hand: three days leaves time to transfer and be
            // approved, and the day before is the last call.
            'first_days' => '3',
            'second_days' => '1',
            'subject_en' => 'Your {plan} plan ends {when}',
            'subject_ar' => 'باقة {plan} تنتهي {when}',
            'headline_en' => 'Your {plan} plan ends {when}',
            'headline_ar' => 'باقة {plan} تنتهي {when}',
            'body_en' => "Hi {name}, your {plan} plan ends on {date}. It does not renew by itself.\n\nTo keep your voices, dialects and credits going without a break, renew before then. Paying early loses nothing: the new month starts when this one ends.",
            'body_ar' => "أهلاً {name}، باقة {plan} الخاصة بك تنتهي يوم {date}، ولا تتجدد تلقائياً.\n\nلتستمر أصواتك ولهجاتك ورصيدك بدون توقف، جدّد قبل هذا الموعد. التجديد المبكر لا يضيع عليك شيئاً: الشهر الجديد يبدأ بعد انتهاء الشهر الحالي.",
            'cta_label_en' => 'Renew my plan',
            'cta_label_ar' => 'جدّد باقتي',
            'footer_en' => 'Questions about paying? Just reply to this email and we will help.',
            'footer_ar' => 'لديك سؤال عن الدفع؟ ردّ على هذه الرسالة وسنساعدك.',
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
                if (in_array($key, ['first_days', 'second_days'], true)) {
                    $value = (string) max(0, min(self::MAX_DAYS, (int) $value));
                }
                SiteSetting::set(self::GROUP.'_'.$key, $value, self::GROUP);
            }
        }
    }

    public function enabled(): bool
    {
        return $this->settings()['enabled'] === '1';
    }

    /**
     * How many days before the end each reminder goes out: the first, then the second.
     * A second reminder of 0 days, or the same day as the first, is not sent.
     *
     * @return array{first:int, second:int|null}
     */
    public function days(): array
    {
        $settings = $this->settings();
        $first = max(1, min(self::MAX_DAYS, (int) $settings['first_days']));
        $second = max(0, min(self::MAX_DAYS, (int) $settings['second_days']));

        return ['first' => $first, 'second' => $second > 0 && $second < $first ? $second : null];
    }

    /**
     * Sends the reminder for this plan, $days before it ends. With $testTo it goes to
     * that address instead. Members are emailed from the live site only; a test copy
     * can go anywhere. Throws when it could not be sent, so the caller can try again.
     */
    public function send(User $user, Subscription $subscription, int $days, ?string $testTo = null): void
    {
        if ($testTo === null && ! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('plan end reminder', ['user_id' => $user->id]);

            throw new RuntimeException('Not sent: this is not the live site, and only the live site emails members. A test copy to your own address works here.');
        }

        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }
        if (PaidWelcomeEmail::isPhoneSignupAddress($to)) {
            throw new InvalidArgumentException('This member signed up with a phone number, so there is no email address to send to.');
        }

        $subscription->loadMissing('plan');
        $newsletter = $this->asNewsletter($this->settings(), $subscription->plan, $subscription->current_period_end ?? now()->addDays($days), $days, (string) $user->name);
        $renderer = app(NewsletterRenderer::class);
        // A billing message, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new PlanEndReminderMail(
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
     * The email shown in the admin preview, for a sample member whose plan ends in
     * the first reminder's days.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(?array $settings = null): string
    {
        $settings ??= $this->settings();
        $sample = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $days = max(1, (int) ($settings['first_days'] ?? 3));
        $newsletter = $this->asNewsletter($settings, $sample, now()->addDays($days), $days, '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /** "in 3 days", "tomorrow" or "today", in the email's language. */
    public static function when(int $days, string $lang): string
    {
        if ($lang === 'ar') {
            return match (true) {
                $days <= 0 => 'اليوم',
                $days === 1 => 'غداً',
                $days === 2 => 'بعد يومين',
                $days <= 10 => 'بعد '.$days.' أيام',
                default => 'بعد '.$days.' يوماً',
            };
        }

        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            default => 'in '.$days.' days',
        };
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, ?Plan $plan, \DateTimeInterface $endsAt, int $days, string $memberName): Newsletter
    {
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'ar_en') === 'en_ar' ? 'en_ar' : 'ar_en',
        ];

        foreach (['en', 'ar'] as $lang) {
            $date = Carbon::instance($endsAt)->locale($lang)->translatedFormat('j F Y');
            $fill = function (string $text) use ($plan, $lang, $date, $days, $memberName): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace(['{date}', '{days}', '{when}'], [$date, (string) $days, self::when($days, $lang)], $text);

                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'body', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$field.'_'.$lang] ?? ''));
            }
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = rtrim((string) config('app.url', ''), '/').'/'.$lang.'/manage-subscription';
        }

        return new Newsletter($fields);
    }
}
