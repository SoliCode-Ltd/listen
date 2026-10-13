<?php

namespace App\Services\Newsletter;

use App\Mail\PaidWelcomeMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * The welcome email for paid members, in Arabic and English. It goes out by itself
 * each time a member starts a paid plan (paid online or set by an admin), and the
 * owner can send it again by hand. Its words are edited in
 * Newsletter → Paid welcome email and use the newsletter's email design.
 */
class PaidWelcomeEmail
{
    public const GROUP = 'paid_welcome';

    public const REVIEW_URL = 'https://www.trustpilot.com/review/listenai.studio';

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            'order' => 'ar_en',
            'subject_en' => 'Welcome to Listen {plan} — you are all set',
            'subject_ar' => 'أهلاً بك في باقة {plan} من إسمع — كل شيء جاهز',
            'headline_en' => 'Welcome to {plan}, {name}!',
            'headline_ar' => 'أهلاً بك في باقة {plan} يا {name}!',
            'body_en' => "Thank you for choosing the {plan} plan. It is active now, and every voice, dialect and feature in it is ready for you.\n\nWe would love to hear how Listen works for you. If you have a minute, a short review helps other creators find us and helps us keep improving.",
            'body_ar' => "شكراً لاختيارك باقة {plan}. باقتك مفعّلة الآن، وكل الأصوات واللهجات والمزايا فيها جاهزة لك.\n\nيسعدنا أن نسمع رأيك في تجربتك مع إسمع. إن كان لديك دقيقة، فتقييمك القصير يساعد صنّاع محتوى آخرين على اكتشافنا ويساعدنا على التحسين المستمر.",
            'cta_label_en' => 'Leave a quick review',
            'cta_label_ar' => 'شاركنا تقييمك',
            'review_url' => self::REVIEW_URL,
            'footer_en' => 'Questions? Just reply to this email — we read every message.',
            'footer_ar' => 'لديك سؤال؟ ردّ على هذه الرسالة مباشرة، فنحن نقرأ كل رسالة.',
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

    public static function canTrack(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('users', 'paid_welcome_sent_at'), false, false));
    }

    public function isPaidMember(User $user): bool
    {
        $plan = app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);

        return $plan !== null && (int) $plan->price_cents > 0;
    }

    /**
     * Sends it by itself each time a member starts a paid plan: from free, or by
     * moving to another paid plan. Renewals never call this, and the same plan is
     * not welcomed twice within a minute (one checkout can save its subscription twice).
     * Runs after the page or webhook has answered, so it never slows a payment down.
     */
    public function queueWelcome(User $user): void
    {
        if ($user->isBlocked() || ! $this->enabled()) {
            return;
        }

        $userId = (int) $user->id;
        dispatch(function () use ($userId): void {
            $fresh = User::query()->find($userId);
            $welcome = app(self::class);
            $plan = $fresh ? app(PlanAccessService::class)->resolveActivePlanForUserId($userId) : null;
            // Checked again here: a trial row can be saved after its subscription.
            if (! $fresh || ! $welcome->enabled() || ! $plan || (int) $plan->price_cents <= 0
                || app(PlanAccessService::class)->userHasUnpaidTrialWindow($userId)
                || ! Cache::add('paid_welcome:'.$userId.':'.$plan->id, 1, now()->addMinute())) {
                return;
            }
            try {
                $welcome->send($fresh);
            } catch (\Throwable $e) {
                Cache::forget('paid_welcome:'.$userId.':'.$plan->id);
                Log::warning('Paid welcome email was not sent.', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        })->afterResponse();
    }

    /**
     * Sends the welcome email to a member and records when. With $testTo it goes
     * to that address instead, using the member's name, and records nothing.
     */
    public function send(User $user, ?string $testTo = null): void
    {
        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }

        $plan = app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);
        $newsletter = $this->asNewsletter($this->settings(), $plan);
        $renderer = app(NewsletterRenderer::class);
        // No email on the recipient: this is a welcome, not a newsletter, so no unsubscribe link.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];
        $html = $renderer->html($newsletter, Newsletter::LANGUAGE_BOTH, $recipient);
        $text = $renderer->text($newsletter, Newsletter::LANGUAGE_BOTH, $recipient);
        $subject = $renderer->subject($newsletter, Newsletter::LANGUAGE_BOTH);

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new PaidWelcomeMail(
            htmlBody: $html,
            textBody: $text,
            subjectLine: $subject,
            fromAddress: $fromAddress,
            fromName: TitanMailbox::fromName(),
            replyToAddress: TitanMailbox::ADDRESS,
        );

        try {
            (app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan'))->to($to)->send($mail);
        } catch (\Throwable $e) {
            throw new RuntimeException(TitanMailbox::explainFailure($e->getMessage()), 0, $e);
        }

        if ($testTo === null && self::canTrack()) {
            $user->forceFill(['paid_welcome_sent_at' => now()])->saveQuietly();
        }
    }

    public static function withPlan(string $text, ?Plan $plan, string $lang): string
    {
        $name = $plan ? trim($plan->getLocalizedName($lang)) : '';
        if ($name !== '') {
            return str_replace('{plan}', $name, $text);
        }
        // No plan name: drop the placeholder and any space left before it.
        $text = preg_replace('/\s*\{plan\}/u', '', $text) ?? $text;

        return str_replace(['باقة  ', ' ,'], ['باقة ', ','], $text);
    }

    /**
     * The email shown in the admin preview, for a sample member.
     */
    public function previewHtml(?array $settings = null): string
    {
        // A paid plan stands in for the member's plan.
        $sample = Plan::query()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $newsletter = $this->asNewsletter($settings ?? $this->settings(), $sample);

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, ?Plan $plan = null): Newsletter
    {
        $url = trim((string) ($settings['review_url'] ?? '')) ?: self::REVIEW_URL;
        // {plan} becomes the member's plan name in each language.
        foreach ($settings as $key => $value) {
            $lang = str_ends_with($key, '_ar') ? 'ar' : 'en';
            $settings[$key] = self::withPlan((string) $value, $plan, $lang);
        }

        return new Newsletter([
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'ar_en') === 'en_ar' ? 'en_ar' : 'ar_en',
            'subject_en' => $settings['subject_en'] ?? '',
            'subject_ar' => $settings['subject_ar'] ?? '',
            'headline_en' => $settings['headline_en'] ?? '',
            'headline_ar' => $settings['headline_ar'] ?? '',
            'body_en' => $settings['body_en'] ?? '',
            'body_ar' => $settings['body_ar'] ?? '',
            'cta_label_en' => $settings['cta_label_en'] ?? '',
            'cta_label_ar' => $settings['cta_label_ar'] ?? '',
            'cta_url_en' => $url,
            'cta_url_ar' => $url,
            'footer_en' => $settings['footer_en'] ?? '',
            'footer_ar' => $settings['footer_ar'] ?? '',
        ]);
    }
}
