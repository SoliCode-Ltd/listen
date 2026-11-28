<?php

namespace App\Services\Newsletter;

use App\Mail\CancellationMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
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
 * it. Then it asks why they cancelled (reply with a number), or, when they already
 * said in the cancel dialog, thanks them for it.
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
            'ask_en' => "We'd like to know why you cancelled. It helps us make Listen better. Just reply to this email with a number:\n\n**1.** The price didn't suit me\n**2.** The voices or dialects weren't what I expected\n**3.** I had a technical problem\n**4.** I don't need it right now\n**5.** Something else (tell us)\n\nWe read every reply ourselves, and if there's a problem we can fix, we'll help right away.",
            'ask_ar' => "ويهمّنا أن نعرف سبب الإلغاء، فرأيك يساعدنا على تحسين إسمع. يكفي أن تردّ على هذه الرسالة برقم:\n\n**1.** السعر لم يكن مناسبًا\n**2.** جودة الأصوات أو اللهجات لم تكن كما توقعت\n**3.** واجهت مشكلة تقنية\n**4.** لم أعد بحاجة إلى الخدمة الآن\n**5.** سبب آخر (اكتبه لنا)\n\nنقرأ كل ردّ بأنفسنا، وإن كانت هناك مشكلة يمكننا حلّها فسنساعدك فورًا.",
            'thanks_en' => "Thank you for telling us why you cancelled. We read every answer ourselves. If something didn't work as it should, just reply to this email and we'll help right away.",
            'thanks_ar' => 'شكرًا لأنك أخبرتنا بسبب الإلغاء، فنحن نقرأ كل إجابة بأنفسنا. وإن واجهت أي مشكلة، ردّ على هذه الرسالة وسنساعدك فورًا.',
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
     * Sends the email for a plan that ends at $endsAt. $reasonGiven: the member already
     * said why in the cancel dialog, so it thanks them instead of asking. With $testTo it
     * goes to that address instead. Members are emailed from the live site only; a test
     * copy can go anywhere. Throws when it could not be sent, so the caller can try again.
     */
    public function send(User $user, ?Plan $plan, \DateTimeInterface $endsAt, bool $reasonGiven, ?string $testTo = null): void
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

        $newsletter = $this->asNewsletter($this->settings(), $plan, $endsAt, $reasonGiven, (string) $user->name);
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
     * The email shown in the admin preview, for a sample member whose plan ends in 20 days.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(?array $settings = null, bool $reasonGiven = false): string
    {
        $settings ??= $this->settings();
        $sample = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $newsletter = $this->asNewsletter($settings, $sample, now()->addDays(20), $reasonGiven, '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, ?Plan $plan, \DateTimeInterface $endsAt, bool $reasonGiven, string $memberName): Newsletter
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
            // What happened, then the question (or the thanks), then the way back just above its button.
            $fields['body_'.$lang] = implode("\n\n", array_filter(array_map(
                fn (string $part): string => trim($fill((string) ($settings[$part.'_'.$lang] ?? ''))),
                ['body', $reasonGiven ? 'thanks' : 'ask', 'keep']
            )));
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = rtrim((string) config('app.url', ''), '/').'/'.$lang.'/manage-subscription';
        }

        return new Newsletter($fields);
    }
}
