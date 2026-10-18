<?php

namespace App\Services\Billing;

use App\Models\CharGift;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\CreditPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Characters Listen gives a member on top of their plan for their current
 * credit period (App\Support\CreditPeriod: the plan's own month). They
 * raise that period's limit only and end with it, like the plan's own
 * characters.
 */
class CharGiftService
{
    public const MAX_GIFT = 10_000_000;

    public function grant(Subscription $subscription, int $chars, ?string $note = null, ?User $admin = null): CharGift
    {
        if ($chars < 1 || $chars > self::MAX_GIFT) {
            throw new \InvalidArgumentException('A gift must be between 1 and '.number_format(self::MAX_GIFT).' characters.');
        }

        $period = CreditPeriod::current((int) $subscription->user_id)['key'];

        $gift = DB::transaction(function () use ($subscription, $chars, $note, $admin, $period): CharGift {
            $counter = $this->lockedCounter((int) $subscription->user_id, $period);
            $counter->bonus_chars = (int) $counter->bonus_chars + $chars;
            $counter->save();

            return CharGift::query()->create([
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'period_ym' => $period,
                'chars' => $chars,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                'granted_by' => $admin?->id,
            ]);
        });

        Log::info('Characters gifted.', [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'chars' => $chars,
            'period' => $period,
            'granted_by' => $admin?->id,
        ]);

        return $gift;
    }

    /**
     * Undo a gift made by mistake. Only gifts of the member's current credit
     * period can be taken back; earlier ones ended with their period anyway.
     */
    public function revoke(CharGift $gift, ?User $admin = null): void
    {
        if (! $gift->isActive()) {
            return;
        }

        if ($gift->period_ym !== CreditPeriod::current((int) $gift->user_id)['key']) {
            throw new \InvalidArgumentException('Only gifts from the current credit period can be taken back.');
        }

        DB::transaction(function () use ($gift, $admin): void {
            $counter = $this->lockedCounter((int) $gift->user_id, $gift->period_ym);
            $counter->bonus_chars = max(0, (int) $counter->bonus_chars - $gift->chars);
            $counter->save();

            $gift->forceFill(['revoked_at' => now(), 'revoked_by' => $admin?->id])->save();
        });
    }

    /**
     * Gifts still counting in a member's current credit period.
     */
    public function activeGiftChars(int $userId): int
    {
        return (int) (UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', CreditPeriod::current($userId)['key'])
            ->value('bonus_chars') ?? 0);
    }

    private function lockedCounter(int $userId, string $period): UsageCounter
    {
        UsageCounter::query()->firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $period],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        return UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', $period)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
