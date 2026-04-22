<?php

namespace App\Services\Referral;

use App\Models\DemoRewardTransaction;
use App\Models\DemoVoiceGeneration;
use App\Models\ReferralConversion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ledger service for demo-only reward characters.
 *
 * This ledger is strictly isolated from dashboard balances, plan balances,
 * and subscription quotas. Reward characters gifted through the referral
 * program can only be consumed inside the demo page flow.
 */
class DemoRewardLedgerService
{
    /**
     * Current demo reward balance for the user (non-negative).
     */
    public function balanceFor(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $balance = (int) DemoRewardTransaction::query()
            ->where('user_id', $userId)
            ->sum('delta_chars');

        return max(0, $balance);
    }

    /**
     * Net demo reward characters attributable to the user: grants minus revokes.
     * This is the "currently earned" figure shown in UI denominators and stats —
     * a revoked grant is subtracted, because it no longer represents reward the
     * user is entitled to (even though the original grant transaction stays in
     * the ledger for auditability).
     */
    public function totalGrantedFor(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return max(0, (int) DemoRewardTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('kind', [DemoRewardTransaction::KIND_GRANT, DemoRewardTransaction::KIND_REVOKE])
            ->sum('delta_chars'));
    }

    /**
     * Total demo reward characters ever consumed by the user (absolute).
     */
    public function totalConsumedFor(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return (int) abs((int) DemoRewardTransaction::query()
            ->where('user_id', $userId)
            ->where('kind', DemoRewardTransaction::KIND_CONSUME)
            ->sum('delta_chars'));
    }

    public function grant(User $user, int $chars, ?ReferralConversion $conversion = null, ?string $reason = null): DemoRewardTransaction
    {
        $chars = max(0, (int) $chars);

        return DemoRewardTransaction::query()->create([
            'user_id' => $user->id,
            'conversion_id' => $conversion?->id,
            'demo_voice_generation_id' => null,
            'kind' => DemoRewardTransaction::KIND_GRANT,
            'delta_chars' => $chars,
            'reason' => $reason,
        ]);
    }

    /**
     * Consume up to $chars from the reward balance. Returns the amount actually consumed.
     */
    public function consume(User $user, int $chars, ?DemoVoiceGeneration $generation = null, ?string $reason = null): int
    {
        $chars = max(0, (int) $chars);
        if ($chars === 0) {
            return 0;
        }

        return (int) DB::transaction(function () use ($user, $chars, $generation, $reason): int {
            $available = $this->balanceFor($user);
            $toConsume = min($chars, $available);
            if ($toConsume <= 0) {
                return 0;
            }

            DemoRewardTransaction::query()->create([
                'user_id' => $user->id,
                'conversion_id' => null,
                'demo_voice_generation_id' => $generation?->id,
                'kind' => DemoRewardTransaction::KIND_CONSUME,
                'delta_chars' => -$toConsume,
                'reason' => $reason,
            ]);

            return $toConsume;
        });
    }

    public function revoke(User $user, int $chars, ?ReferralConversion $conversion = null, ?string $reason = null): DemoRewardTransaction
    {
        $chars = max(0, (int) $chars);

        return DemoRewardTransaction::query()->create([
            'user_id' => $user->id,
            'conversion_id' => $conversion?->id,
            'demo_voice_generation_id' => null,
            'kind' => DemoRewardTransaction::KIND_REVOKE,
            'delta_chars' => -$chars,
            'reason' => $reason,
        ]);
    }
}
