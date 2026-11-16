<?php

namespace App\Support\Transcription;

use App\Models\Plan;
use App\Models\Transcription;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\InApp\InApp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use speech to text ("Transcribe"), and how much: what each plan
 * includes, as set on the plan (Admin → Plans → Transcribe), and the
 * member's minutes this billing month. The minutes come from the plan only:
 * there are no packs, top-ups or pay-as-you-go for transcription. They are
 * counted by the second of audio and start again with the member's billing
 * month, the same day as their voice credits (CreditPeriod).
 */
final class TranscriptionAccess
{
    /** Whether the plans table has the transcription columns yet (before the migration it has not). */
    public static function planColumns(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('plans', 'transcription_enabled'), false, false));
    }

    public static function planIncludes(?Plan $plan): bool
    {
        return $plan !== null && self::planColumns() && (bool) $plan->transcription_enabled && (int) $plan->transcription_minutes_month > 0;
    }

    /** Minutes of audio a month on the plan (0 when it has no transcription). */
    public static function monthlyMinutes(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(0, (int) $plan->transcription_minutes_month) : 0;
    }

    /** The longest file the plan takes, in minutes (0 when it has no transcription). */
    public static function maxFileMinutes(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(1, (int) $plan->transcription_max_file_minutes) : 0;
    }

    /** Jobs one member may have working at once. */
    public static function concurrentJobs(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(1, (int) $plan->transcription_concurrent_jobs) : 1;
    }

    /** What a minute of audio costs Listen (the same transcript on every plan). */
    public static function costPerMinute(): float
    {
        return TranscriptionSettings::costPerMinute();
    }

    /** Whether this person sees Transcribe at all (Transcribe → Settings: who can see it). */
    public static function openTo(?User $user): bool
    {
        if ($user === null || ! TranscriptionSettings::visibleTo((string) $user->email)) {
            return false;
        }
        // Inside the Listen app nothing is sold: the free try that leads to a plan is not offered there.
        if (InApp::hidesPlans() && ! self::planIncludes(self::planOf($user))) {
            return false;
        }

        return true;
    }

    /** The plan whose features the member has. */
    public static function planOf(User $user): ?Plan
    {
        return app(PlanAccessService::class)->resolveAccessPlanForUserId((int) $user->id);
    }

    /**
     * The member's minutes this billing month: what the plan gives, what is
     * used (transcripts working or done, deleted ones too), what is left, and
     * when it starts again. Seconds throughout.
     *
     * @return array{plan: ?Plan, included: bool, allowance: int, used: int, left: int, period: string, resets_at: Carbon}
     */
    public static function balance(User $user): array
    {
        $plan = self::planOf($user);
        $period = CreditPeriod::current((int) $user->id);
        $allowance = self::monthlyMinutes($plan) * 60;
        $used = (int) Transcription::withTrashed()->where('user_id', $user->id)->where('is_free', false)
            ->where('period_ym', $period['key'])->where('status', '!=', Transcription::FAILED)->sum('charged_seconds');

        return [
            'plan' => $plan,
            'included' => self::planIncludes($plan),
            'allowance' => $allowance,
            'used' => $used,
            'left' => max(0, $allowance - $used),
            'period' => (string) $period['key'],
            'resets_at' => $period['ends_at'],
        ];
    }

    /**
     * What a file of $seconds uses: every second of audio, a part second as a
     * whole one. The tenth of a second an MP3 or AAC encoder adds as silence
     * at the start (a 20 s recording reads 20.04 s) is not counted.
     */
    public static function chargeFor(float $seconds): int
    {
        return max(1, (int) ceil($seconds - 0.1));
    }

    /** Whether the member can still have the free try: once, and while the site's free tries for today last. */
    public static function freeTryLeft(User $user): bool
    {
        if (TranscriptionSettings::freeTrySeconds() <= 0) {
            return false;
        }
        $used = Transcription::withTrashed()->where('user_id', $user->id)->where('is_free', true)->where('status', '!=', Transcription::FAILED)->exists();

        return ! $used && ! self::freeDayFull();
    }

    public static function freeDayFull(): bool
    {
        $today = Transcription::withTrashed()->where('is_free', true)->where('status', '!=', Transcription::FAILED)
            ->where('created_at', '>=', now()->startOfDay())->count();

        return $today >= TranscriptionSettings::freePerDay();
    }

    /** Seconds the whole site may still transcribe today (members, the API and MCP together); null: no ceiling. */
    public static function siteSecondsLeftToday(): ?int
    {
        $minutes = TranscriptionSettings::dailyMinutes();
        if ($minutes <= 0) {
            return null;
        }
        $used = (int) Transcription::withTrashed()->where('status', '!=', Transcription::FAILED)
            ->where('created_at', '>=', now()->startOfDay())->sum('charged_seconds');

        return max(0, $minutes * 60 - $used);
    }

    /** Files the member has working now. */
    public static function working(User $user): int
    {
        return Transcription::query()->where('user_id', $user->id)->whereIn('status', Transcription::WORKING)->count();
    }

    /**
     * The plan to move up to: the cheapest plan on sale above the member's
     * own that includes Transcribe and fits a file of $fileSeconds with
     * $needSeconds this month (0: any). Null when none fits.
     */
    public static function nextPlanThatFits(?Plan $current, int $needSeconds = 0, int $fileSeconds = 0): ?Plan
    {
        if (! self::planColumns()) {
            return null;
        }
        $price = (int) ($current?->price_cents ?? 0);
        $plans = rescue(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)
            ->where('transcription_enabled', true)->orderBy('price_cents')->get(), collect(), false);
        foreach ($plans as $plan) {
            if ($current !== null && $plan->id === $current->id) {
                continue;
            }
            if ((int) $plan->price_cents <= $price && self::planIncludes($current)) {
                continue;
            }
            if (self::monthlyMinutes($plan) * 60 >= $needSeconds && self::maxFileMinutes($plan) * 60 >= $fileSeconds) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The upgrade to show: the plan's name in the page's language and where
     * to get it (the plan's checkout, or the pricing page when no plan fits;
     * inside the Listen app, where plans are not sold, nothing).
     *
     * @return array{plan: ?string, slug: ?string, url: ?string}
     */
    public static function upgrade(?Plan $plan, bool $arabic): array
    {
        if (InApp::hidesPlans()) {
            return ['plan' => null, 'slug' => null, 'url' => null];
        }
        if ($plan === null) {
            return ['plan' => null, 'slug' => null, 'url' => route('pricing')];
        }

        return [
            'plan' => $arabic ? ((string) ($plan->name_ar ?? '') ?: (string) $plan->name) : (string) $plan->name,
            'slug' => (string) $plan->slug,
            'url' => rescue(fn () => route('billing.page', ['plan' => $plan->slug]), route('pricing'), false),
        ];
    }
}
