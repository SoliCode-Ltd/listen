<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Extra credits: a paid member whose month's credits run out buys more, once, by
 * card (or, when they pay their plan by hand, by InstaPay or Vodafone Cash).
 *
 * Nothing new is made for them but the purchase itself. Each purchase is a row in
 * char_pack_purchases (kind extra_credits), and its credits go on the member's own
 * usage row for the credit period (usage_counters.extra_chars), beside the credits
 * the plan gives and those Listen gifts:
 *
 * - the plan's credits are used first, then the gifts, then the extra credits;
 * - what is left of them moves on to the next period each time the plan renews,
 *   and with a change of plan (CreditPeriod::carry());
 * - when the plan stops (cancelled, or a renewal left unpaid when its grace days
 *   end), what is left ends with it. A cancelled plan keeps them until the end
 *   of the period it paid for.
 *
 * The usage row keeps, for the period, the plan's own credits (extra_base_chars:
 * what was used beyond them came from the extra credits) and when it ends
 * (extra_ends_at). Once the period is over, settle() moves what is left on or
 * ends it, once (extra_settled_at, extra_outcome).
 */
class ExtraCredits
{
    /** Admin switch (Billing Settings): off until the owner turns it on. */
    public const ENABLED_SETTING = 'extra_credits_enabled';

    /** Admin presets: {"10": 200, ...}, minutes to whole US dollar prices in cents. */
    public const PRESETS_SETTING = 'extra_credits_presets';

    /** @var array<int, int> minutes => US cents */
    public const DEFAULT_PRESETS = [10 => 200, 20 => 400, 30 => 500, 50 => 800, 100 => 1200];

    /** The preset chosen when the dialog opens. */
    public const DEFAULT_MINUTES = 30;

    /** Stripe metadata listen_purpose of the card payment. */
    public const PURPOSE = 'extra_credits';

    public const STATUS_OK = 'ok';

    /** The owner has not turned extra credits on. */
    public const STATUS_OFF = 'off';

    /** No paid plan (the free plan, a pack, an unpaid trial): upgrade instead. */
    public const STATUS_NO_PLAN = 'no_plan';

    /** The plan's renewal is unpaid: pay it first. */
    public const STATUS_RENEWAL_DUE = 'renewal_due';

    public const OUTCOME_CARRIED = 'carried';

    public const OUTCOME_ENDED = 'ended';

    /** All of them were used: nothing was left to move on. */
    public const OUTCOME_USED = 'used';

    /**
     * How long after its period ended the plan may still go on (a card renewal
     * on its way, as in CreditPeriod): only after that does what is left end.
     */
    private const CONTINUE_HOURS = 2;

    private const READY_CACHE = 'extra_credits.columns_ready';

    private static bool $ready = false;

    public function __construct(private readonly PlanAccessService $plans) {}

    /**
     * The member's plan is cancelled and runs to the end of what was paid for:
     * credits will not renew, and extra credits end with the plan.
     */
    public function planEnds(int $userId): bool
    {
        return BillingSubscription::query()
            ->where('user_id', $userId)
            ->where('type', 'default')
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->exists();
    }

    /**
     * Whether the database has the columns (the migration runs after the code
     * is deployed). Only a yes is remembered.
     */
    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            if (Cache::get(self::READY_CACHE) === true
                || (Schema::hasColumn('usage_counters', 'extra_chars') && Schema::hasColumn('usage_counters', 'credits_ended_email_at') && Schema::hasColumn('char_pack_purchases', 'kind'))) {
                Cache::put(self::READY_CACHE, true, now()->addDay());

                return self::$ready = true;
            }
        } catch (\Throwable) {
            // No database to ask: not ready.
        }

        return false;
    }

    public static function enabled(): bool
    {
        return filter_var(SiteSetting::get(self::ENABLED_SETTING, false), FILTER_VALIDATE_BOOL) && self::ready();
    }

    /**
     * The presets on sale, fewest minutes first: minutes => US cents (whole dollars).
     *
     * @return array<int, int>
     */
    public static function presets(): array
    {
        $saved = SiteSetting::get(self::PRESETS_SETTING);
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;

        $presets = [];
        foreach (is_array($saved) ? $saved : [] as $minutes => $cents) {
            $minutes = (int) $minutes;
            $cents = (int) $cents;
            if ($minutes >= 1 && $minutes <= 10000 && $cents >= 100 && $cents % 100 === 0) {
                $presets[$minutes] = $cents;
            }
        }
        $presets = $presets !== [] ? $presets : self::DEFAULT_PRESETS;
        ksort($presets);

        return $presets;
    }

    public static function priceCents(int $minutes): ?int
    {
        return self::presets()[$minutes] ?? null;
    }

    /** Credits for whole minutes of voice. */
    public static function creditsFor(int $minutes): int
    {
        return max(0, $minutes) * Credits::PER_MINUTE;
    }

    /** "Listen Extra Credits (30 minutes)": the card statement line and the payment's description. */
    public static function description(int $minutes): string
    {
        return 'Listen Extra Credits ('.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').')';
    }

    // ──────────────────────────────────────────────────────────────────
    //  Who may buy
    // ──────────────────────────────────────────────────────────────────

    public function status(int $userId): string
    {
        if (! self::enabled()) {
            return self::STATUS_OFF;
        }

        $plan = $this->plans->resolveActivePlanForUserId($userId);
        if (! $plan || (int) $plan->price_cents <= 0 || $plan->isPack() || $this->plans->userHasUnpaidTrialWindow($userId)) {
            return self::STATUS_NO_PLAN;
        }

        $subscription = $this->subscription($userId);
        if ($subscription && (string) $subscription->billing_payment_state === 'past_due') {
            return self::STATUS_RENEWAL_DUE;
        }

        return self::STATUS_OK;
    }

    /** The member pays their plan by hand (InstaPay / Vodafone Cash), not by card. */
    public function paysByHand(int $userId): bool
    {
        return (string) ($this->subscription($userId)?->billing_payment_state ?? '') === 'manual_override';
    }

    /** The member's plan row (the latest decides). */
    public function subscription(int $userId): ?Subscription
    {
        return Subscription::query()->where('user_id', $userId)->latest('id')->first();
    }

    // ──────────────────────────────────────────────────────────────────
    //  What the member has
    // ──────────────────────────────────────────────────────────────────

    /** Whether extra credits count on this plan (as the quota resolves it). */
    public static function counts(?Plan $quotaPlan): bool
    {
        return $quotaPlan !== null
            && (int) $quotaPlan->price_cents > 0
            && ! $quotaPlan->isPack()
            && ! $quotaPlan->getAttribute('billed_to_pack')
            && ! $quotaPlan->getAttribute('enforce_trial_minute_cap')
            && ! $quotaPlan->getAttribute('no_free_monthly_chars');
    }

    /** The extra credits this period's allowance holds (PlanUsageGuard::charsLimit()). */
    public static function available(?Plan $quotaPlan, ?UsageCounter $counter): int
    {
        $extra = max(0, (int) ($counter?->getAttribute('extra_chars') ?? 0));
        if ($extra === 0 || ! self::counts($quotaPlan)) {
            return 0;
        }

        // What was left already moved on: only what was used here still counts here.
        return $counter->getAttribute('extra_settled_at') !== null
            ? self::usedOf($counter, PlanUsageGuard::planChars($quotaPlan))
            : $extra;
    }

    /** The extra credits still left now. */
    public static function left(?Plan $quotaPlan, ?UsageCounter $counter): int
    {
        $available = self::available($quotaPlan, $counter);

        return $available > 0 ? max(0, $available - self::usedOf($counter, PlanUsageGuard::planChars($quotaPlan))) : 0;
    }

    /** Extra credits used in a period: what was used beyond the plan's credits and the gifts. */
    public static function usedOf(UsageCounter $counter, int $planChars): int
    {
        $beyond = (int) $counter->total_chars - max(0, $planChars) - PlanUsageGuard::giftChars($counter);

        return max(0, min(max(0, (int) $counter->getAttribute('extra_chars')), $beyond));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Buying
    // ──────────────────────────────────────────────────────────────────

    public function startPurchase(User $user, int $minutes, string $method, int $amountMinor, string $currency, ?int $manualPaymentRequestId = null): CharPackPurchase
    {
        return CharPackPurchase::query()->create([
            'user_id' => $user->id,
            'kind' => CharPackPurchase::KIND_EXTRA_CREDITS,
            // The plan the member bought them on, for the record (they stay with the member).
            'plan_id' => $this->plans->resolveActivePlanForUserId((int) $user->id)?->id,
            'chars' => self::creditsFor($minutes),
            'days' => 0,
            'amount_minor' => max(0, $amountMinor),
            'currency' => strtoupper($currency),
            'method' => $method,
            'status' => CharPackPurchase::STATUS_PENDING,
            'manual_payment_request_id' => $manualPaymentRequestId,
        ]);
    }

    /**
     * Adds a paid purchase's credits to the member's current period. Safe to
     * call more than once: only the first call adds them.
     */
    public function markPaid(CharPackPurchase $purchase): CharPackPurchase
    {
        $userId = (int) $purchase->user_id;
        // What is left from a period that has ended moves on (or ends) first.
        $this->settle($userId);

        return DB::transaction(function () use ($purchase, $userId): CharPackPurchase {
            /** @var CharPackPurchase $locked */
            $locked = CharPackPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [CharPackPurchase::STATUS_PAID, CharPackPurchase::STATUS_REFUNDED], true)) {
                return $locked;
            }

            $period = CreditPeriod::current($userId);
            $quota = $this->plans->resolveQuotaPlanForUserId($userId);
            $counter = $this->lockedCounter($userId, $period['key']);
            $this->deposit($counter, (int) $locked->chars, $quota, $period);

            $locked->forceFill([
                'status' => CharPackPurchase::STATUS_PAID,
                'paid_at' => now(),
                'failure_message' => null,
                'period_ym' => $period['key'],
            ])->save();

            if (! self::counts($quota)) {
                Log::warning('Extra credits were paid for while the member has no paid plan; they count again only if a paid plan follows this period.', [
                    'user_id' => $userId,
                    'purchase_id' => $locked->id,
                ]);
            }

            Log::info('Extra credits added.', [
                'user_id' => $userId,
                'purchase_id' => $locked->id,
                'credits' => (int) $locked->chars,
                'method' => $locked->method,
                'period' => $period['key'],
            ]);

            return $locked;
        });
    }

    public function markFailed(CharPackPurchase $purchase, string $message): void
    {
        CharPackPurchase::query()
            ->whereKey($purchase->id)
            ->where('status', CharPackPurchase::STATUS_PENDING)
            ->update([
                'status' => CharPackPurchase::STATUS_FAILED,
                'failure_message' => mb_substr($message, 0, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * The payment was refunded: takes back what is still left of the member's
     * extra credits, up to this purchase's. Credits already used stay used.
     */
    public function refund(CharPackPurchase $purchase): int
    {
        $userId = (int) $purchase->user_id;
        $this->settle($userId);

        return DB::transaction(function () use ($purchase, $userId): int {
            /** @var CharPackPurchase $locked */
            $locked = CharPackPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CharPackPurchase::STATUS_PAID) {
                return 0;
            }

            $taken = 0;
            $counter = $this->lockedCounter($userId, CreditPeriod::current($userId)['key']);
            if ($counter->extra_settled_at === null && (int) $counter->extra_chars > 0) {
                $quota = $this->plans->resolveQuotaPlanForUserId($userId);
                $left = (int) $counter->extra_chars - self::usedOf($counter, PlanUsageGuard::planChars($quota));
                $taken = max(0, min((int) $locked->chars, $left));
                if ($taken > 0) {
                    $counter->forceFill(['extra_chars' => (int) $counter->extra_chars - $taken])->save();
                }
            }

            $locked->forceFill([
                'status' => CharPackPurchase::STATUS_REFUNDED,
                'refunded_at' => now(),
            ])->save();

            Log::info('Extra credits refunded.', [
                'user_id' => $userId,
                'purchase_id' => $locked->id,
                'credits_taken_back' => $taken,
            ]);

            return $taken;
        });
    }

    // ──────────────────────────────────────────────────────────────────
    //  From one period to the next
    // ──────────────────────────────────────────────────────────────────

    /**
     * Moves on (or ends) what is left of the extra credits of the member's
     * periods that are over. Called whenever their current usage row is read
     * (CreditPeriod::counter()) and every hour (listen:extra-credits-settle).
     * Returns whether anything changed.
     *
     * @param  array{key: string, starts_at: Carbon, ends_at: Carbon}|null  $period  the current one, when the caller has it
     */
    public function settle(int $userId, ?array $period = null): bool
    {
        if (! self::ready()) {
            return false;
        }

        $period ??= CreditPeriod::current($userId);
        $over = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('extra_chars', '>', 0)
            ->whereNull('extra_settled_at')
            ->where('period_ym', '!=', $period['key'])
            ->orderBy('id')
            ->pluck('id');

        $changed = false;
        foreach ($over as $id) {
            $changed = $this->settleOne($userId, (int) $id, $period) || $changed;
        }

        return $changed;
    }

    /**
     * Keeps the plan's own credits of the member's current period up to date
     * (a change of plan that kept the period, or the plan's credits changed),
     * so what was used beyond them is told right when the period ends.
     */
    public function refreshBase(int $userId): void
    {
        if (! self::ready() || ! UsageCounter::query()->where('user_id', $userId)->where('extra_chars', '>', 0)->whereNull('extra_settled_at')->exists()) {
            return;
        }

        $period = CreditPeriod::current($userId);
        $counter = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', $period['key'])
            ->where('extra_chars', '>', 0)
            ->whereNull('extra_settled_at')
            ->first();
        if (! $counter) {
            return;
        }

        $quota = $this->plans->resolveQuotaPlanForUserId($userId);
        $changes = array_filter([
            'extra_base_chars' => self::counts($quota) ? PlanUsageGuard::planChars($quota) : null,
            'extra_ends_at' => $period['ends_at'],
        ], fn ($value) => $value !== null);
        $counter->forceFill($changes);
        if ($counter->isDirty()) {
            $counter->save();
        }
    }

    /**
     * The extra credits a change of plan carries into the new period's row,
     * which starts with the old one's usage (CreditPeriod::carry()).
     *
     * @return array{extra_chars:int, extra_base_chars:int, extra_ends_at:?Carbon}
     */
    public static function carriedOver(UsageCounter $previous, ?Plan $newPlan, Carbon $newEndsAt): array
    {
        $extra = $previous->extra_settled_at === null ? max(0, (int) $previous->extra_chars) : 0;

        return [
            'extra_chars' => $extra,
            'extra_base_chars' => $extra > 0 ? PlanUsageGuard::planChars($newPlan) : 0,
            'extra_ends_at' => $extra > 0 ? $newEndsAt : null,
        ];
    }

    /** The old period's row, once a change of plan carried its extra credits on. */
    public static function markCarried(UsageCounter $previous): void
    {
        if ($previous->extra_settled_at === null && (int) $previous->extra_chars > 0) {
            UsageCounter::query()->whereKey($previous->getKey())->whereNull('extra_settled_at')->update([
                'extra_settled_at' => now(),
                'extra_outcome' => self::OUTCOME_CARRIED,
                'updated_at' => now(),
            ]);
        }
    }

    /** @param  array{key: string, starts_at: Carbon, ends_at: Carbon}  $period */
    private function settleOne(int $userId, int $counterId, array $period): bool
    {
        return DB::transaction(function () use ($userId, $counterId, $period): bool {
            /** @var UsageCounter|null $old */
            $old = UsageCounter::query()->whereKey($counterId)->lockForUpdate()->first();
            if (! $old || $old->extra_settled_at !== null || $old->period_ym === $period['key']) {
                return false;
            }

            $left = (int) $old->extra_chars - self::usedOf($old, (int) $old->extra_base_chars);
            if ($left <= 0) {
                $this->close($old, self::OUTCOME_USED, 0);

                return true;
            }

            $endedAt = $old->extra_ends_at !== null ? Carbon::instance($old->extra_ends_at)->utc() : now()->utc();
            $deadline = $endedAt->copy()->addHours(self::CONTINUE_HOURS);
            $quota = $this->plans->resolveQuotaPlanForUserId($userId);

            // The plan went on: renewed (the new period starts when the old one ends),
            // or a change of plan the carry-over at the change did not take care of.
            $continues = self::counts($quota) && ($period['starts_at']->lte($deadline)
                || $this->renewalPaidByHandInTime($userId, (string) $old->period_ym, $deadline));

            if ($continues) {
                $current = $this->lockedCounter($userId, $period['key']);
                $this->deposit($current, $left, $quota, $period);
                $this->close($old, self::OUTCOME_CARRIED, $left, $period['key']);

                return true;
            }

            // A renewal paid by hand in time that the team has not approved yet: wait for it.
            if ($this->renewalPaidByHandWaiting($userId, (string) $old->period_ym, $deadline)) {
                return false;
            }

            if (now()->utc()->gt($deadline)) {
                $this->close($old, self::OUTCOME_ENDED, $left);

                return true;
            }

            return false;
        });
    }

    /**
     * Adds credits to a period's row. A row whose extra credits had already
     * moved on (its period came back, as when the team undoes a payment) keeps
     * only what was used of them before these are added.
     *
     * @param  array{key: string, starts_at: Carbon, ends_at: Carbon}  $period
     */
    private function deposit(UsageCounter $counter, int $credits, ?Plan $quotaPlan, array $period): void
    {
        $planChars = PlanUsageGuard::planChars($quotaPlan);
        $extra = $counter->extra_settled_at !== null
            ? self::usedOf($counter, (int) ($counter->extra_base_chars ?: $planChars))
            : max(0, (int) $counter->extra_chars);

        $counter->forceFill([
            'extra_chars' => $extra + max(0, $credits),
            'extra_base_chars' => $planChars,
            'extra_ends_at' => $period['ends_at'],
            'extra_settled_at' => null,
            'extra_outcome' => null,
            // With credits again, running out again is told again (CreditsEndedEmail).
            'credits_ended_email_at' => null,
        ])->save();
    }

    private function close(UsageCounter $old, string $outcome, int $left, ?string $to = null): void
    {
        $old->forceFill([
            'extra_settled_at' => now(),
            'extra_outcome' => $outcome,
        ])->save();

        if ($outcome !== self::OUTCOME_USED) {
            Log::info($outcome === self::OUTCOME_CARRIED ? 'Extra credits moved on to the next period.' : 'Extra credits ended with the plan.', [
                'user_id' => $old->user_id,
                'from' => $old->period_ym,
                'to' => $to,
                'credits' => $left,
            ]);
        }
    }

    private function lockedCounter(int $userId, string $key): UsageCounter
    {
        UsageCounter::query()->firstOrCreate(
            ['user_id' => $userId, 'period_ym' => $key],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        return UsageCounter::query()->where('user_id', $userId)->where('period_ym', $key)->lockForUpdate()->firstOrFail();
    }

    /** Plan payments by hand sent between the period's start and its deadline. */
    private function renewalsByHand(int $userId, string $periodKey, Carbon $deadline)
    {
        $from = CreditPeriod::startOf($periodKey);

        return ManualPaymentRequest::query()
            ->forPlans()
            ->where('user_id', $userId)
            ->where('submitted_at', '<=', $deadline)
            ->when($from !== null, fn ($query) => $query->where('submitted_at', '>=', $from));
    }

    private function renewalPaidByHandWaiting(int $userId, string $periodKey, Carbon $deadline): bool
    {
        return $this->renewalsByHand($userId, $periodKey, $deadline)
            ->where('status', ManualPaymentRequest::STATUS_PENDING)
            ->exists();
    }

    /** The plan the member has now was paid by hand in time, and approved after the period ended. */
    private function renewalPaidByHandInTime(int $userId, string $periodKey, Carbon $deadline): bool
    {
        $current = $this->subscription($userId);
        if (! $current) {
            return false;
        }

        return $this->renewalsByHand($userId, $periodKey, $deadline)
            ->where('status', ManualPaymentRequest::STATUS_APPROVED)
            ->where('approved_subscription_id', $current->id)
            ->exists();
    }
}
