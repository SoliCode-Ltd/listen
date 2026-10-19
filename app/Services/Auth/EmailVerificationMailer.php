<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Support\EmailVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

use function Illuminate\Support\defer;

/**
 * The email with the link that confirms a member's address: plain text, like
 * the billing emails, in the language of the page the member was on. It goes
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
            $body = $this->body($user, $this->verificationUrl($user), $locale);
            $subject = __('ui.verify_email.mail_subject', [], $locale);
            $name = trim((string) $user->name);

            Mail::raw($body, function ($message) use ($address, $name, $subject): void {
                $message->to($address, $name !== '' ? $name : null)->subject($subject);
            });
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

    private function body(User $user, string $url, string $locale): string
    {
        $name = trim((string) $user->name);

        return implode("\n", [
            $name !== ''
                ? __('ui.verify_email.mail_greeting', ['name' => $name], $locale)
                : __('ui.verify_email.mail_greeting_anonymous', [], $locale),
            '',
            __('ui.verify_email.mail_intro', [], $locale),
            '',
            $url,
            '',
            __('ui.verify_email.mail_expiry', ['days' => EmailVerification::LINK_DAYS], $locale),
            '',
            __('ui.verify_email.mail_ignore', [], $locale),
            '',
            __('ui.verify_email.mail_thanks', [], $locale),
            __('ui.verify_email.mail_signature', [], $locale),
        ]);
    }
}
