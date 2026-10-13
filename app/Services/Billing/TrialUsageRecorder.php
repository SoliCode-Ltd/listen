<?php

namespace App\Services\Billing;

use App\Models\BillingTrial;
use App\Models\UsageCounter;

class TrialUsageRecorder
{
    /**
     * @param  array{chars?:int,seconds?:int}  $estimate
     */
    public function add(int $userId, array $estimate): void
    {
        $trial = $this->meteredTrial($userId);
        if (! $trial) {
            return;
        }

        $seconds = (int) $trial->seconds_used + max(0, (int) ($estimate['seconds'] ?? 0));
        $trial->forceFill([
            'chars_used' => (int) $trial->chars_used + max(0, (int) ($estimate['chars'] ?? 0)),
            'requests_used' => (int) $trial->requests_used + 1,
            'seconds_used' => $seconds,
            'minutes_used' => UsageCounter::minutesFor($seconds),
        ])->save();
    }

    /**
     * @param  array{chars?:int,seconds?:int}  $estimate
     */
    public function release(int $userId, array $estimate): void
    {
        $trial = $this->meteredTrial($userId);
        if (! $trial) {
            return;
        }

        $seconds = max(0, (int) $trial->seconds_used - max(0, (int) ($estimate['seconds'] ?? 0)));
        $trial->forceFill([
            'chars_used' => max(0, (int) $trial->chars_used - max(0, (int) ($estimate['chars'] ?? 0))),
            'requests_used' => max(0, (int) $trial->requests_used - 1),
            'seconds_used' => $seconds,
            'minutes_used' => UsageCounter::minutesFor($seconds),
        ])->save();
    }

    private function meteredTrial(int $userId): ?BillingTrial
    {
        return BillingTrial::query()
            ->where('user_id', $userId)
            ->whereNull('paid_at')
            ->where(function ($query) {
                $query->where(function ($trialing) {
                    $trialing->where('status', BillingTrial::STATUS_TRIALING)
                        ->where('trial_ends_at', '>', now()->subDays(3));
                })->orWhere(function ($canceled) {
                    $canceled->where('status', BillingTrial::STATUS_CANCELED)
                        ->where('trial_ends_at', '>', now());
                });
            })
            ->latest('id')
            ->first();
    }
}
