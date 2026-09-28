<?php

namespace App\Services\Newsletter;

use App\Models\NewsletterDelivery;
use App\Models\SiteSetting;
use App\Support\TitanMailbox;
use Illuminate\Support\Carbon;

class NewsletterPace
{
    public function dailyLimit(): int
    {
        return max(1, (int) config('newsletter.daily_limit', 500));
    }

    public function hourlyLimit(): int
    {
        return max(1, (int) config('newsletter.hourly_limit', 200));
    }

    public function perMinute(): int
    {
        return max(1, (int) config('newsletter.per_minute', 3));
    }

    /**
     * How many this click can finish: the smaller of today's remainder and this hour's remainder.
     */
    public function availableNow(): int
    {
        return min($this->dailyRemaining(), $this->hourlyRemaining());
    }

    public function sentToday(): int
    {
        return $this->sentSince(now()->startOfDay());
    }

    public function sentThisHour(): int
    {
        return $this->sentSince(now()->subHour());
    }

    public function queued(): int
    {
        return NewsletterDelivery::query()
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_QUEUED)
            ->count();
    }

    public function dailyRemaining(): int
    {
        return max(0, $this->dailyLimit() - $this->sentToday() - $this->queued());
    }

    public function hourlyRemaining(): int
    {
        return max(0, $this->hourlyLimit() - $this->sentThisHour());
    }

    public function isMailboxFailure(string $message): bool
    {
        if ($this->isAuthFailure($message)) {
            return true;
        }

        $haystack = strtolower($message);

        foreach ([
            'rate limit',
            'too many',
            'sending limit',
            'quota',
            'try again later',
            'temporarily',
            '421',
            '452',
            '4.7.',
            'unusual sending',
            'mailbox is paused',
            'sending paused',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function hold(string $reason, Carbon $until): void
    {
        SiteSetting::set('newsletter_send_hold_until', $until->toIso8601String(), 'mail');
        SiteSetting::set('newsletter_send_hold_reason', $reason, 'mail');
    }

    public function clearHold(): void
    {
        SiteSetting::set('newsletter_send_hold_until', '', 'mail');
        SiteSetting::set('newsletter_send_hold_reason', '', 'mail');
    }

    public function holdReason(): ?string
    {
        $until = trim((string) SiteSetting::get('newsletter_send_hold_until', ''));
        if ($until === '') {
            return null;
        }

        try {
            if (Carbon::parse($until)->isPast()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $reason = trim((string) SiteSetting::get('newsletter_send_hold_reason', ''));

        return $reason !== '' ? $reason : 'Sending is paused until the mailbox can accept more mail.';
    }

    public function holdUntilFor(string $message): Carbon
    {
        if ($this->isAuthFailure($message)) {
            return now()->addHours(12);
        }

        $haystack = strtolower($message);
        if (str_contains($haystack, 'daily') || str_contains($haystack, 'per day') || str_contains($haystack, '24 hour')) {
            return now()->addDay()->startOfDay();
        }

        return now()->addHour();
    }

    public function explainedHold(string $message): string
    {
        return TitanMailbox::explainFailure($message);
    }

    private function isAuthFailure(string $message): bool
    {
        return str_contains($message, '535')
            || str_contains(strtolower($message), 'authentication failed')
            || str_contains($message, 'UGFzc3dvcmQ6');
    }

    private function sentSince(Carbon $since): int
    {
        return NewsletterDelivery::query()
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_SENT)
            ->where('sent_at', '>=', $since)
            ->count();
    }
}
