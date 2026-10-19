<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Support\LiveServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The plain-text billing emails Listen sends by itself: plan end reminders,
 * a missed card payment and a subscription cancelled unpaid (its grace days
 * ran out, or the member cancelled during them).
 * Only the live site sends them, and each one once (its dedupe key).
 */
class BillingLifecycleMail
{
    private const SIGNATURE = 'Listen Studio Team';

    /**
     * Whether the email went out now. Not when it was sent before (the dedupe key),
     * on a server that is not the live site, or when sending failed; a failed send
     * is not remembered, so a later run can try again.
     */
    public function send(User $user, string $subject, string $body, ?string $dedupeKey = null, int $ttlHours = 72): bool
    {
        if (! $user->email) {
            return false;
        }

        if (! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('billing email "'.$subject.'"', ['user_id' => $user->id]);

            return false;
        }

        if ($dedupeKey !== null && ! Cache::add($this->cacheKey($dedupeKey), now()->timestamp, now()->addHours(max(1, $ttlHours)))) {
            return false;
        }

        try {
            Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name ?: null)->subject($subject);
            });
        } catch (\Throwable $exception) {
            if ($dedupeKey !== null) {
                Cache::forget($this->cacheKey($dedupeKey));
            }

            Log::warning('Unable to send billing lifecycle email.', [
                'user_id' => $user->id,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $summaryLines
     * @param  array<string, string|null>  $details
     */
    public function compose(User $user, array $summaryLines, array $details = [], ?string $actionLine = null): string
    {
        $lines = [
            'Hi '.(trim((string) $user->name) !== '' ? (string) $user->name : 'there').',',
            '',
        ];

        foreach ($summaryLines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $filtered = [];
        foreach ($details as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '' && $value !== 'N/A') {
                $filtered[$label] = $value;
            }
        }

        if ($filtered !== []) {
            $lines[] = '';
            $lines[] = 'Details:';
            foreach ($filtered as $label => $value) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        if ($actionLine !== null && trim($actionLine) !== '') {
            $lines[] = '';
            $lines[] = trim($actionLine);
        }

        $lines[] = '';
        $lines[] = 'Thanks,';
        $lines[] = self::SIGNATURE;

        return implode("\n", $lines);
    }

    public function manageBillingUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/').'/manage-subscription';
    }

    private function cacheKey(string $key): string
    {
        return 'billing_lifecycle_email:'.sha1($key);
    }
}
