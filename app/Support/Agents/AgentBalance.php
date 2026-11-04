<?php

namespace App\Support\Agents;

use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Support\Carbon;

/**
 * An owner's call time, as their agents' pages show it: the minutes of calls
 * left (from the plan's credits at the agents' rate, and the plan's call
 * minutes a month), what was used this period, when it renews, and the state
 * that decides what the page says:
 *
 *   ok    plenty left
 *   low   about to run out: a few calls left, or under a sixth of the month's
 *   out   none left: callers hear the agent is not available
 *   plan  the plan has no agents: build and test only, with the upgrade
 */
final class AgentBalance
{
    /**
     * @return array{state: string, included: bool, rate: int, credits_left: int, minutes_left: int, minutes_total: int,
     *   used_minutes: int, cap: int, by_cap: bool, renews_at: ?Carbon, pack: bool, free_test_seconds: int,
     *   upgrade: array{url: string, plan: ?string, none: bool}, percent_left: int}
     */
    public static function for(User $user): array
    {
        $userId = (int) $user->id;
        $calls = app(AgentCalls::class);
        $limits = AgentAccess::limitsFor($userId);
        $rate = AgentAccess::creditsPerMinute();
        $quotaPlan = app(PlanAccessService::class)->resolveQuotaPlanForUserId($userId);
        $snapshot = UsageSnapshot::for($userId, $quotaPlan, CreditPeriod::counter($userId));
        $creditsLeft = max(0, (int) ($snapshot['remaining_chars'] ?? 0));
        $creditsAll = max(0, (int) ($snapshot['plan_chars'] ?? 0));
        $usedSeconds = $calls->periodSeconds($userId);

        $byCredits = intdiv($creditsLeft, $rate);
        $cap = (int) $limits['minutes'];
        $capLeft = $cap > 0 ? max(0, intdiv($cap * 60 - $usedSeconds, 60)) : PHP_INT_MAX;
        $left = $limits['included'] ? min($byCredits, $capLeft) : 0;
        // What the month holds in all: the cap, or everything the month's credits pay for.
        $total = $cap > 0 ? min($cap, intdiv($creditsAll, $rate)) : intdiv($creditsAll, $rate);
        $total = max($total, $left + intdiv($usedSeconds, 60));

        $state = match (true) {
            ! $limits['included'] => 'plan',
            $left < 1 => 'out',
            $left < 10 || ($total > 0 && $left * 6 < $total) => 'low',
            default => 'ok',
        };
        $period = CreditPeriod::current($userId);
        $pack = ($snapshot['mode'] ?? '') === 'pack';

        return [
            'state' => $state,
            'included' => $limits['included'],
            'rate' => $rate,
            'credits_left' => $creditsLeft,
            'minutes_left' => $left,
            'minutes_total' => $total,
            'used_minutes' => intdiv($usedSeconds, 60),
            'cap' => $cap,
            // The plan's call minutes, not its credits, are what runs out first.
            'by_cap' => $cap > 0 && $capLeft < $byCredits,
            'renews_at' => $pack ? (isset($snapshot['pack_expires_at']) && $snapshot['pack_expires_at'] ? Carbon::parse($snapshot['pack_expires_at']) : null) : ($period['ends_at'] ?? null),
            'pack' => $pack,
            'free_test_seconds' => $calls->freeTestSecondsLeft($userId),
            'upgrade' => AgentAccess::upgrade(app()->getLocale() === 'ar'),
            'percent_left' => $total > 0 ? (int) max(0, min(100, round($left / $total * 100))) : 0,
        ];
    }
}
