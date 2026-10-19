<?php

namespace App\Services\Auth;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Support\EmailVerification;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

use function Illuminate\Support\defer;

/**
 * The email with the link that confirms a member's address, in the site's email
 * design (the newsletter's, as the paid welcome email), in the language of the
 * page the member was on. It goes
 * out at sign-up and when the member asks for a new link. That is the
 * member's own request, so staging sends it too (App\Support\LiveServer only
 * holds back the emails scheduled jobs send). A failure never stops sign-up:
 * it is logged, and the member can send a new link from the demo page.
 */
class EmailVerificationMailer
{
    /**
     * Sent once the reply has gone, so sign-up never waits for the mail server.
     * The page the member lands on already says a link is on its way.
     */
    public function sendAfterResponse(User $user, string $locale): void
    {
        $userId = (int) $user->id;
        $this->rememberLinkSent($userId);

        defer(function () use ($userId, $locale): void {
            $member = User::query()->find($userId);
            if ($member) {
                $this->send($member, $locale);
            }
        }, always: true);
    }

    /**
     * Whether the email went out. An address that is already confirmed, or the
     * internal address of a phone sign-up, gets none.
     */
    public function send(User $user, string $locale): bool
    {
        $address = trim((string) $user->email);
        if ($user->email_verified_at !== null || ! self::canReceive($address)) {
            return false;
        }

        $locale = $locale === 'ar' ? 'ar' : 'en';

        try {
            $name = trim((string) $user->name);
            Mail::to($address, $name !== '' ? $name : null)->send($this->mail($user, $this->verificationUrl($user), $locale));
        } catch (\Throwable $e) {
            Cache::forget(self::sentKey((int) $user->id));
            Log::warning('The email confirmation link was not sent.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->rememberLinkSent((int) $user->id);

        return true;
    }

    /**
     * Whether a link went out to this member (or is on its way) and still
     * works, so the page says "we sent a link" only when there is one: members
     * who signed up before links were sent, or whose email failed, are asked to
     * send themselves one instead.
     */
    public static function linkSentRecently(User $user): bool
    {
        return Cache::has(self::sentKey((int) $user->id));
    }

    private function rememberLinkSent(int $userId): void
    {
        Cache::put(self::sentKey($userId), true, now()->addDays(EmailVerification::LINK_DAYS));
    }

    private static function sentKey(int $userId): string
    {
        return 'verify-email:sent:'.$userId;
    }

    /**
     * A signed link that confirms this account's address while it is still the
     * account's address, for LINK_DAYS days. It opens the page in the language
     * of the page it was made on.
     */
    public function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addDays(EmailVerification::LINK_DAYS), [
            'id' => (int) $user->id,
            'hash' => self::addressHash((string) $user->email),
        ]);
    }

    public static function addressHash(string $email): string
    {
        return sha1($email);
    }

    public static function canReceive(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! PaidWelcomeEmail::isPhoneSignupAddress($email);
    }

    /**
     * The email in the newsletter's design (resources/views/mail/newsletter.blade.php),
     * as the paid welcome email is, in the member's language: a button that opens the
     * link, and the link itself in the plain-text part. No unsubscribe link: the member
     * asked for it.
     */
    public function mail(User $user, string $url, string $locale): NewsletterMail
    {
        $locale = $locale === 'ar' ? Newsletter::LANGUAGE_AR : Newsletter::LANGUAGE_EN;
        $text = fn (string $key, array $replace = []): string => (string) __('ui.verify_email.'.$key, $replace, $locale);
        $suffix = '_'.$locale;

        $newsletter = new Newsletter([
            'language' => $locale,
            'subject'.$suffix => $text('mail_subject'),
            'preheader'.$suffix => $text('mail_preheader'),
            'headline'.$suffix => $text('mail_headline'),
            'body'.$suffix => implode("\n\n", [
                $text('mail_intro'),
                $text('mail_expiry', ['days' => EmailVerification::LINK_DAYS]),
                $text('mail_ignore'),
            ]),
            'cta_label'.$suffix => $text('mail_button'),
            'cta_url'.$suffix => $url,
            'footer'.$suffix => $text('mail_footer'),
        ]);

        $renderer = app(NewsletterRenderer::class);
        $recipient = ['name' => (string) $user->name];

        return new NewsletterMail(
            htmlBody: $renderer->html($newsletter, $locale, $recipient),
            textBody: $renderer->text($newsletter, $locale, $recipient),
            subjectLine: $renderer->subject($newsletter, $locale),
            fromAddress: (string) config('mail.from.address'),
            fromName: (string) config('mail.from.name'),
            replyToAddress: TitanMailbox::ADDRESS,
        );
    }
}
