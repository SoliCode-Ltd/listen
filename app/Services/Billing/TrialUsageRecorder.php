<?php

namespace App\Services\Billing;

use App\Models\BillingTrial;

class TrialUsageRecorder
{
    /**
     * @param  array{chars?:int,minutes?:int}  $estimate
     */
    public function add(int $userId, array $estimate): void
    {
        $trial = $this->meteredTrial($userId);
        if (! $trial) {
            return;
        }

        $trial->forceFill([
            'chars_used' => (int) $trial->chars_used + max(0, (int) ($estimate['chars'] ?? 0)),
            'requests_used' => (int) $trial->requests_used + 1,
            'minutes_used' => (int) $trial->minutes_used + max(0, (int) ($estimate['minutes'] ?? 0)),
        ])->save();
    }

    /**
     * @param  array{chars?:int,minutes?:int}  $estimate
     */
    public function release(int $userId, array $estimate): void
    {
        $trial = $this->meteredTrial($userId);
        if (! $trial) {
            return;
        }

        $trial->forceFill([
            'chars_used' => max(0, (int) $trial->chars_used - max(0, (int) ($estimate['chars'] ?? 0))),
            'requests_used' => max(0, (int) $trial->requests_used - 1),
            'minutes_used' => max(0, (int) $trial->minutes_used - max(0, (int) ($estimate['minutes'] ?? 0))),
        ])->save();
    }

    private function meteredTrial(int $userId): ?BillingTrial
    {
        return BillingTrial::query()
            ->where('user_id', $userId)
            ->whereNull('paid_at')
            ->where('trial_ends_at', '>', now())
            ->whereIn('status', [BillingTrial::STATUS_TRIALING, BillingTrial::STATUS_CANCELED])
            ->latest('id')
            ->first();
    }
}
