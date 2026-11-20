<?php

namespace App\Services\Newsletter;

use App\Mail\CreditsEndedMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\LiveServer;
use App\Support\TitanMailbox;
use App\Support\UsageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * The email a member on a paid plan gets when the period's credits run out,
 * wherever they ran out: the studio, the browser extension or the developer
 * API. It says when the plan's credits renew, and its button opens the studio
 * with the extra-credits dialog (App\Services\Billing\ExtraCredits).
 *
 * Once for each credit period (usage_counters.credits_ended_email_at), and again
 * only after extra credits were added and those ran out too. Only members who
 * can buy extra credits get it, so it is never sent while they are switched
 * off, to free members, during an unpaid renewal's grace, or to a member whose
 * plan is cancelled (nothing renews for them).
 *
 * In Arabic and English, in the newsletter's email design. Its words are edited
 * in Newsletter → Credits ended.
 */
class CreditsEndedEmail
{
    public const GROUP = 'credits_ended';

    /** After a send that failed, how long before it is tried again for that member. */
    private const RETRY_MINUTES = 60;

    public function __construct(
        private readonly ExtraCredits $extraCredits,
        private readonly PlanAccessService $plans,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            // English first, then Arabic.
            'order' => 'en_ar',
            'subject_en' => 'You have used all your {plan} credits',
            'subject_ar' => 'انتهى رصيد خطة {plan}',
            'headline_en' => 'Your credits have run out',
            'headline_ar' => 'انتهى رصيدك',
            'body_en' => "Hi {name}, you have used all the credits on your {plan} plan. They renew on {date}.\n\nTo keep going now, add extra credits: choose how many minutes you need and pay once. What you don't use carries over each time your plan renews.",
            'body_ar' => "أهلاً {name}، استخدمت كل رصيد خطة {plan}. يتجدد رصيدك يوم {date}.\n\nلتكمل الآن، أضف رصيداً إضافياً: اختر عدد الدقائق التي تحتاجها وادفع مرة واحدة. ما لا تستخدمه ينتقل معك مع كل تجديد لخطتك.",
            'cta_label_en' => 'Add credits',
            'cta_label_ar' => 'أضف رصيداً',
            'footer_en' => 'Using the API? Requests are answered with 402 until credits are added or your plan renews. Questions? Just reply to this email.',
            'footer_ar' => 'تستخدم الـ API؟ تُرفض الطلبات بالرمز 402 حتى تضيف رصيداً أو تتجدد خطتك. لديك سؤال؟ ردّ على هذه الرسالة.',
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
     * A member's credits just ran out (less than a minute of voice is left).
     * Sends the email when it is due; never throws, so the request that ran
     * out is answered as it would be without it. Returns whether it was sent.
     */
    public function memberRanOut(int $userId): bool
    {
        try {
            if (! ExtraCredits::ready() || ! $this->enabled()
                || $this->extraCredits->status($userId) !== ExtraCredits::STATUS_OK
                // Staging runs on a copy of live members: they never get emails from it.
                || ! LiveServer::allowsCustomerActions()
                || Cache::has($this->retryKey($userId))) {
                return false;
            }

            $user = User::query()->find($userId);
            if (! $user || ! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) || PaidWelcomeEmail::isPhoneSignupAddress((string) $user->email)) {
                return false;
            }

            // A plan cancelled to end with its period has no renewal to speak of: its member is not written to.
            if ($this->extraCredits->planEnds($userId)) {
                return false;
            }

            // Looked at again now: credits handed back since (a voice that failed) mean they did not run out.
            $left = (int) UsageSnapshot::for($userId, $this->plans->resolveQuotaPlanForUserId($userId), CreditPeriod::counter($userId))['remaining_chars'];
            if ($left >= Credits::PER_MINUTE) {
                return false;
            }

            $period = CreditPeriod::current($userId);
            // Claimed first, so two requests refused at the same moment send one email.
            $claimed = UsageCounter::query()
                ->where('user_id', $userId)
                ->where('period_ym', $period['key'])
                ->whereNull('credits_ended_email_at')
                ->update(['credits_ended_email_at' => now()]);
            if ($claimed !== 1) {
                return false;
            }

            try {
                $this->send($user, $this->plans->resolveActivePlanForUserId($userId), $period['ends_at']);
            } catch (\Throwable $e) {
                // Not sent: free to be tried again, a little later.
                UsageCounter::query()->where('user_id', $userId)->where('period_ym', $period['key'])->update(['credits_ended_email_at' => null]);
                Cache::put($this->retryKey($userId), true, now()->addMinutes(self::RETRY_MINUTES));
                Log::warning('The credits-ended email could not be sent.', ['user_id' => $userId, 'error' => $e->getMessage()]);

                return false;
            }

            Log::info('Credits-ended email sent.', ['user_id' => $userId, 'period' => $period['key']]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('The credits-ended email check failed.', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Sends the email. With $testTo it goes to that address instead (a test copy
     * can go from any server). Throws when it could not be sent.
     */
    public function send(User $user, ?Plan $plan, \DateTimeInterface $renewsAt, ?string $testTo = null): void
    {
        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }

        $newsletter = $this->asNewsletter($this->settings(), $plan, $renewsAt, (string) $user->name);
        $renderer = app(NewsletterRenderer::class);
        // An account message, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new CreditsEndedMail(
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
     * The email shown in the admin preview, for a sample member.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(?array $settings = null): string
    {
        $settings ??= $this->settings();
        $sample = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $newsletter = $this->asNewsletter($settings, $sample, now()->addDays(12), '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /** Where the email's button leads: the studio, with the extra-credits dialog open. */
    public static function addCreditsUrl(string $lang): string
    {
        return rtrim((string) config('app.url', ''), '/').'/'.$lang.'/dashboard?extra_credits=1';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, ?Plan $plan, \DateTimeInterface $renewsAt, string $memberName): Newsletter
    {
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'en_ar') === 'ar_en' ? 'ar_en' : 'en_ar',
        ];

        foreach (['en', 'ar'] as $lang) {
            $date = Carbon::instance($renewsAt)->utc()->locale($lang)->translatedFormat('j F');
            $fill = function (string $text) use ($plan, $lang, $date, $memberName): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace('{date}', $date, $text);

                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'body', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$field.'_'.$lang] ?? ''));
            }
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = self::addCreditsUrl($lang);
        }

        return new Newsletter($fields);
    }

    private function retryKey(int $userId): string
    {
        return 'credits_ended_email:retry:'.$userId;
    }
}
