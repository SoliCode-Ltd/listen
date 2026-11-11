<?php

namespace App\Services\Auth;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\Newsletter\NewsletterRenderer;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

use function Illuminate\Support\defer;

/**
 * "Forgot your password?" from the sign-in page: the email with the link to choose a new
 * password, in the site's email design (the newsletter's, as the paid welcome email and the
 * email-confirmation link), in the language of the page the member asked on. Every request
 * is written down for the admin (Newsletter → Password resets): who asked, whether the email
 * went out, and whether the password was changed.
 *
 * The link is Laravel's own reset token (kept hashed in password_reset_tokens, good for
 * PasswordResetRequest::linkMinutes(), used once). The page answers the same whether or not
 * the address has an account, and the link and its email are made after the reply, so neither
 * the words nor the wait tell a stranger which addresses are members. It is the member's own request,
 * so staging sends it too, as it does the confirmation link.
 */
class PasswordResetMailer
{
    /**
     * A request for the address typed on the page. Nothing is returned: the page says the
     * same thing whatever happens here.
     */
    public function request(string $email, string $locale, ?string $ip = null, ?string $userAgent = null): void
    {
        $email = trim($email);
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $user = $this->account($email);

        $status = match (true) {
            $user === null => PasswordResetRequest::NO_ACCOUNT,
            ! EmailVerificationMailer::canReceive((string) $user->email) => PasswordResetRequest::NO_MAILBOX,
            Password::broker()->getRepository()->recentlyCreatedToken($user) => PasswordResetRequest::TOO_SOON,
            default => PasswordResetRequest::SENT,
        };

        $row = $this->record($user, $email, $locale, $status, $ip, $userAgent);
        if ($status !== PasswordResetRequest::SENT) {
            return;
        }

        // The link's address is made now, in the language of the page. Its token (slow to hash) and
        // the email come once the reply has gone, so a member's address answers as fast as any other.
        $placeholder = str_repeat('0', 64);
        $url = route('password.reset', ['token' => $placeholder, 'email' => (string) $user->email]);
        $userId = (int) $user->id;
        $rowId = $row?->id;

        defer(function () use ($userId, $url, $placeholder, $locale, $rowId): void {
            $member = User::query()->find($userId);
            if ($member) {
                $this->send($member, str_replace($placeholder, Password::broker()->createToken($member), $url), $locale, $rowId);
            }
        }, always: true);
    }

    /** Whether the email went out. A failure is logged, noted on the request, and frees the member to ask again at once. */
    public function send(User $user, string $url, string $locale, ?int $rowId = null): bool
    {
        try {
            $name = trim((string) $user->name);
            Mail::to(trim((string) $user->email), $name !== '' ? $name : null)->send($this->mail($user, $url, $locale));
        } catch (\Throwable $e) {
            Log::warning('The password reset link was not sent.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            rescue(fn () => Password::broker()->deleteToken($user), null, false);
            $this->update($rowId, ['status' => PasswordResetRequest::FAILED]);

            return false;
        }

        $this->update($rowId, ['sent_at' => now()]);

        return true;
    }

    /** The password was changed with a link: the request it came from is marked done. */
    public function completed(User $user, ?string $ip = null): void
    {
        if (! PasswordResetRequest::ready()) {
            return;
        }

        rescue(function () use ($user, $ip): void {
            $row = PasswordResetRequest::query()
                ->where('user_id', $user->id)
                ->where('status', PasswordResetRequest::SENT)
                ->whereNull('completed_at')
                ->latest('id')
                ->first();
            $row?->forceFill(['completed_at' => now(), 'completed_ip' => $ip !== null ? substr($ip, 0, 45) : null])->save();
        }, null, false);
    }

    /**
     * The email in the newsletter's design (resources/views/mail/newsletter.blade.php), in the
     * member's language: a button that opens the link, and the link itself in the plain-text
     * part. No unsubscribe link: the member asked for it.
     */
    public function mail(User $user, string $url, string $locale): NewsletterMail
    {
        $locale = $locale === 'ar' ? Newsletter::LANGUAGE_AR : Newsletter::LANGUAGE_EN;
        $text = fn (string $key, array $replace = []): string => (string) __('ui.reset_password.'.$key, $replace, $locale);
        $suffix = '_'.$locale;

        $newsletter = new Newsletter([
            'language' => $locale,
            'subject'.$suffix => $text('mail_subject'),
            'preheader'.$suffix => $text('mail_preheader'),
            'headline'.$suffix => $text('mail_headline'),
            'body'.$suffix => implode("\n\n", [
                $text('mail_intro'),
                $text('mail_expiry', ['minutes' => PasswordResetRequest::linkMinutes()]),
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

    /** The account with this address, however its letters were typed. */
    public function account(string $email): ?User
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first()
            ?? ($email !== mb_strtolower($email) ? User::query()->where('email', mb_strtolower($email))->first() : null);
    }

    private function record(?User $user, string $email, string $locale, string $status, ?string $ip, ?string $userAgent): ?PasswordResetRequest
    {
        if (! PasswordResetRequest::ready()) {
            return null;
        }

        // The list never stops a reset: a failure to write it is only logged.
        return rescue(fn () => PasswordResetRequest::create([
            'user_id' => $user?->id,
            'email' => mb_substr($email, 0, 190),
            'locale' => $locale,
            'status' => $status,
            'ip' => $ip !== null ? substr($ip, 0, 45) : null,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]), null, true);
    }

    /** @param  array<string, mixed>  $values */
    private function update(?int $rowId, array $values): void
    {
        if ($rowId === null) {
            return;
        }

        rescue(fn () => PasswordResetRequest::query()->whereKey($rowId)->update($values + ['updated_at' => now()]), null, false);
    }
}
