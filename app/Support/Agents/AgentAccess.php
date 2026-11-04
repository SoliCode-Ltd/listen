<?php

namespace App\Support\Agents;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\VoiceCloningAccess;
use Illuminate\Support\Facades\Schema;

/**
 * Who has Listen Agents, and how much of them.
 *
 * - Who sees Agents at all (Admin → Agents → Settings): everyone, the admin
 *   email only, or nobody.
 * - Which plans include them, set on each plan (Plans → Voice agents): a plan
 *   with agents puts them live and sets how many a member keeps, the call
 *   minutes a month, calls at the same time and the longest call. A member
 *   whose plan has none still builds and tests one agent with the free test
 *   minutes, and sees the cheapest plan that has them.
 * - The site-wide rate, free test seconds and safety switches (settings()).
 *
 * Before the plan columns exist on a server (the migration not run yet),
 * every plan counts as including agents, as before.
 */
final class AgentAccess
{
    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_OFF = 'off';

    /** Agents a member may build to try them, before a plan with agents. */
    public const TRY_AGENTS = 1;

    /** The longest call the live voice allows in one session. */
    public const MAX_CALL_MINUTES = 15;

    public static function audience(): string
    {
        $value = (string) self::setting('agents_audience', self::AUDIENCE_EVERYONE);

        return in_array($value, [self::AUDIENCE_PREVIEW, self::AUDIENCE_OFF], true) ? $value : self::AUDIENCE_EVERYONE;
    }

    /** Whether this visitor or member sees Agents (the pages, the menu, the homepage section). */
    public static function openTo(?User $user): bool
    {
        // Between a deploy and its migration nobody sees Agents: their pages read tables not made yet.
        if (! self::tablesReady()) {
            return false;
        }

        return match (self::audience()) {
            self::AUDIENCE_EVERYONE => true,
            self::AUDIENCE_OFF => false,
            default => VoiceCloningAccess::isPreviewAccount($user),
        };
    }

    /** Whether this server has the agents tables yet (the migration has run). */
    public static function tablesReady(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasTable('agent_conversations'), false, false));
    }

    /** Whether the plans table has the agents columns yet. */
    public static function planColumns(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('plans', 'agents_enabled'), false, false));
    }

    public static function planIncludes(?Plan $plan): bool
    {
        if (! $plan) {
            return false;
        }
        if (! self::planColumns()) {
            return true;
        }

        return (bool) $plan->agents_enabled;
    }

    /**
     * What a member on this plan gets.
     *
     * @return array{included: bool, agents: int, minutes: int, concurrent: int, call_seconds: int}
     */
    public static function limits(?Plan $plan): array
    {
        $included = self::planIncludes($plan);
        $siteAgents = max(1, (int) config('listen.agents.max_agents', 10));
        $siteSeconds = max(60, min(self::MAX_CALL_MINUTES * 60, (int) config('listen.agents.max_call_seconds', 600)));
        $columns = self::planColumns() && $plan;

        $agents = $columns ? (int) $plan->agents_max : 0;
        $minutes = $columns ? (int) $plan->agent_minutes_per_month : 0;
        $concurrent = $columns ? (int) $plan->agent_concurrent_calls : 0;
        $callMinutes = $columns ? (int) $plan->agent_max_call_minutes : 0;

        return [
            'included' => $included,
            'agents' => $included ? ($agents > 0 ? min(100, $agents) : $siteAgents) : self::TRY_AGENTS,
            // 0: only the plan's credits limit the call minutes.
            'minutes' => $included ? max(0, $minutes) : 0,
            'concurrent' => max(1, $concurrent > 0 ? min(50, $concurrent) : (int) config('listen.agents.concurrent_calls', 3)),
            'call_seconds' => $callMinutes > 0 ? min(self::MAX_CALL_MINUTES, $callMinutes) * 60 : $siteSeconds,
        ];
    }

    /** The plan whose features the member uses (their plan, or a pack's while it lasts). */
    public static function planFor(int $userId): ?Plan
    {
        return app(PlanAccessService::class)->resolveAccessPlanForUserId($userId);
    }

    /** @return array{included: bool, agents: int, minutes: int, concurrent: int, call_seconds: int} */
    public static function limitsFor(int $userId): array
    {
        return self::limits(self::planFor($userId));
    }

    /** The cheapest plan on sale with agents, for the upgrade button. */
    public static function cheapestPlan(): ?Plan
    {
        return rescue(fn () => once(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)
            ->where('price_cents', '>', 0)->orderBy('price_cents')->get()
            ->first(fn (Plan $plan): bool => self::planIncludes($plan))), null, false);
    }

    /**
     * Where the upgrade button goes and the plan it names; none: no plan on sale has agents.
     *
     * @return array{url: string, plan: ?string, none: bool}
     */
    public static function upgrade(bool $arabic): array
    {
        $plan = self::cheapestPlan();
        if (! $plan) {
            return ['url' => route('pricing'), 'plan' => null, 'none' => true];
        }

        return [
            'url' => route('billing.page', ['plan' => $plan->slug]),
            'plan' => $arabic && filled($plan->name_ar) ? (string) $plan->name_ar : (string) $plan->name,
            'none' => false,
        ];
    }

    /** A site-wide setting from Agents → Settings, or the config default. */
    public static function setting(string $key, mixed $default = null): mixed
    {
        return rescue(fn () => SiteSetting::get($key, $default), $default, false);
    }

    /**
     * Credits a call minute uses: the rate saved in Agents → Settings, else the one the plans call
     * for (AgentCost::recommendedRate: the least that keeps 40% on every plan), else the config.
     */
    public static function creditsPerMinute(): int
    {
        $value = (int) self::setting('agents_credits_per_minute', 0);
        if ($value > 0) {
            return $value;
        }

        return max(1, AgentCost::recommendedRate() ?? (int) config('listen.agents.credits_per_minute', 8100));
    }

    /** Whether the admin saved a rate of their own (otherwise it follows the plans). */
    public static function rateIsSet(): bool
    {
        return (int) self::setting('agents_credits_per_minute', 0) > 0;
    }

    public static function freeTestSeconds(): int
    {
        $value = self::setting('agents_free_test_seconds', null);

        return max(0, $value !== null && $value !== '' ? (int) $value : (int) config('listen.agents.free_test_seconds', 120));
    }

    /** "Stop all calls now" in Agents → Settings: no call starts anywhere, Listen's own agent included. */
    public static function callsStopped(): bool
    {
        return (bool) self::setting('agents_calls_stopped', false);
    }

    /** Call minutes a day for the whole site (0: no limit), and for the free test calls alone. */
    public static function siteDailyMinutes(): int
    {
        return max(0, (int) self::setting('agents_site_daily_minutes', 0));
    }

    public static function freeDailyMinutes(): int
    {
        $value = self::setting('agents_free_daily_minutes', null);

        return max(0, $value !== null && $value !== '' ? (int) $value : 120);
    }

    /** Call minutes a day for Listen's own agent on its public pages (HouseAgent). */
    public static function houseDailyMinutes(): int
    {
        $value = self::setting('agents_house_daily_minutes', null);

        return max(0, $value !== null && $value !== '' ? (int) $value : 180);
    }

    /**
     * Whether Listen's own agents' calls take credits from the account that owns them, like a
     * member's agent (so the owner sees what they use). A call that account cannot pay for goes
     * on free all the same: Listen's own button never turns a visitor away for it.
     */
    public static function houseCharged(): bool
    {
        return (bool) self::setting('agents_house_charged', true);
    }

    /** Whether an agent is checked by the text model before it first goes live. */
    public static function reviewOn(): bool
    {
        return (bool) self::setting('agents_review', true);
    }
}
