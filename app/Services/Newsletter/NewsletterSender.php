<?php

namespace App\Services\Newsletter;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\User;
use App\Support\TitanMailbox;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

class NewsletterSender
{
    public function __construct(
        private readonly NewsletterAudience $audience,
        private readonly NewsletterRenderer $renderer,
    ) {}

    /**
     * Send one preview copy. This never selects the saved audience.
     */
    public function sendExample(Newsletter $newsletter, string $email, string $locale): void
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\s,;]/', $email)) {
            throw new InvalidArgumentException('Enter one email address for the example.');
        }

        $locale = in_array($locale, [
            Newsletter::LANGUAGE_EN,
            Newsletter::LANGUAGE_AR,
            Newsletter::LANGUAGE_BOTH,
        ], true) ? $locale : Newsletter::LANGUAGE_EN;

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $delivered = $this->deliver($newsletter, [
            'email' => $email,
            'name' => $user ? (string) $user->name : '',
            'user_id' => $user?->id,
        ], $locale, true);

        if (! $delivered) {
            $error = (string) $newsletter->deliveries()
                ->where('email', $email)
                ->where('is_test', true)
                ->latest('id')
                ->value('error');

            throw new RuntimeException($error !== '' ? $error : 'The example email could not be sent.');
        }

        $newsletter->forceFill([
            'last_test_sent_at' => now(),
            'last_test_email' => $email,
        ])->save();
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function sendToAudience(Newsletter $newsletter, bool $confirmed = false): array
    {
        if (! $confirmed) {
            throw new RuntimeException('Audience send must be confirmed in the Newsletter admin.');
        }

        $newsletter = $newsletter->fresh() ?? $newsletter;
        if ($newsletter->status === Newsletter::STATUS_SENDING) {
            throw new RuntimeException('A send is already running for this newsletter.');
        }

        $previous = (string) $newsletter->status;
        $claimed = Newsletter::query()
            ->whereKey($newsletter->getKey())
            ->where('status', '!=', Newsletter::STATUS_SENDING)
            ->update(['status' => Newsletter::STATUS_SENDING]);

        if ($claimed === 0) {
            throw new RuntimeException('A send is already running for this newsletter.');
        }

        $newsletter->refresh();
        $sent = 0;
        $failed = 0;

        try {
            $this->audience->each($newsletter, function (array $recipient) use ($newsletter, &$sent, &$failed): void {
                $ok = $this->deliver($newsletter, $recipient, (string) $newsletter->language, false);
                if ($ok) {
                    $sent++;
                } else {
                    $failed++;
                }
            });
        } finally {
            $sentTotal = $newsletter->deliveries()
                ->where('is_test', false)
                ->where('status', NewsletterDelivery::STATUS_SENT)
                ->count();
            $failedTotal = $newsletter->deliveries()
                ->where('is_test', false)
                ->where('status', NewsletterDelivery::STATUS_FAILED)
                ->count();

            $newsletter->forceFill([
                'status' => ($sent > 0 || $previous === Newsletter::STATUS_SENT || $sentTotal > 0)
                    ? Newsletter::STATUS_SENT
                    : Newsletter::STATUS_DRAFT,
                'sent_at' => $sent > 0 ? now() : $newsletter->sent_at,
                'sent_count' => $sentTotal,
                'failed_count' => $failedTotal,
            ])->save();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param  array{email: string, name: string, user_id: int|null}  $recipient
     */
    private function deliver(Newsletter $newsletter, array $recipient, string $locale, bool $isTest): bool
    {
        try {
            $html = $this->renderer->html($newsletter, $locale, $recipient, false);
            $text = $this->renderer->text($newsletter, $locale, $recipient, false);
            $subject = $this->renderer->subject($newsletter, $locale);
            if ($subject === '') {
                throw new RuntimeException('Add a subject before sending.');
            }

            $fromName = filled($newsletter->from_name)
                ? (string) $newsletter->from_name
                : TitanMailbox::fromName();
            $replyTo = filter_var((string) $newsletter->reply_to, FILTER_VALIDATE_EMAIL)
                ? (string) $newsletter->reply_to
                : null;
            $unsubscribeUrl = $this->renderer->unsubscribeUrl($recipient['email'], $locale);
            $fromAddress = filter_var((string) $newsletter->from_address, FILTER_VALIDATE_EMAIL)
                ? (string) $newsletter->from_address
                : TitanMailbox::ADDRESS;

            if (! app()->environment('testing')) {
                TitanMailbox::apply();
                TitanMailbox::requirePassword();
                // Titan rejects mail whose From address is not the signed-in mailbox.
                $fromAddress = TitanMailbox::username();
            }

            $pending = new NewsletterMail(
                htmlBody: $html,
                textBody: $text,
                subjectLine: $subject,
                fromAddress: $fromAddress,
                fromName: $fromName,
                replyToAddress: $replyTo,
                unsubscribeUrl: $unsubscribeUrl !== '' ? $unsubscribeUrl : null,
            );

            $mailer = app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan');
            $mailer->to($recipient['email'], $recipient['name'] !== '' ? $recipient['name'] : null)
                ->send($pending);

            $this->log($newsletter, $recipient, $locale, $isTest, NewsletterDelivery::STATUS_SENT, null);

            return true;
        } catch (\Throwable $exception) {
            $this->log(
                $newsletter,
                $recipient,
                $locale,
                $isTest,
                NewsletterDelivery::STATUS_FAILED,
                $exception->getMessage()
            );

            return false;
        }
    }

    /**
     * @param  array{email: string, name: string, user_id: int|null}  $recipient
     */
    private function log(
        Newsletter $newsletter,
        array $recipient,
        string $locale,
        bool $isTest,
        string $status,
        ?string $error,
    ): void {
        if (! $newsletter->getKey()) {
            return;
        }

        NewsletterDelivery::query()->create([
            'newsletter_id' => $newsletter->getKey(),
            'user_id' => $recipient['user_id'],
            'email' => $recipient['email'],
            'locale' => $locale,
            'is_test' => $isTest,
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === NewsletterDelivery::STATUS_SENT ? now() : null,
        ]);
    }
}
