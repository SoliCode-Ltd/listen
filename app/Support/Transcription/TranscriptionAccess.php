<?php

namespace App\Support\Transcription;

use App\Models\Plan;
use App\Models\Transcription;
use App\Models\User;
use App\Services\DemoLinkedAccounts;
use App\Services\DemoSamePerson;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\InApp\InApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use speech to text, and how much: what each plan
 * includes, as set on the plan (Admin → Plans → Speech to text), and the
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

    /** Whether this person sees speech to text at all (Speech to text → Settings: who can see it). */
    public static function openTo(?User $user): bool
    {
        if ($user === null || ! TranscriptionSettings::visibleTo((string) $user->email)) {
            return false;
        }
        // Inside the Listen apps it is offered as on the website, free try included, as the demo's
        // other tries are. Nothing there is sold: upgrade() gives no link, and the lines that name a
        // plan to buy say what is true instead (lang/*/in_app.php).
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
     * What a file of $seconds uses: its length to the nearest second, exactly
     * as its length is shown (TranscriptionPresenter: a file shown as 1:42 uses
     * 1 min 42 s, never a second more); at least one second.
     */
    public static function chargeFor(float $seconds): int
    {
        return max(1, (int) round($seconds));
    }

    /** Whether the member can still have the free try: once for each person, and while the site's free tries for today last. */
    public static function freeTryLeft(User $user, ?Request $request = null): bool
    {
        if (TranscriptionSettings::freeTrySeconds() <= 0) {
            return false;
        }

        return ! self::freeTryUsed($user, $request ?? request()) && ! self::freeDayFull();
    }

    /**
     * Whether this person has had the free try, as the demo counts one person
     * (App\Services\DemoSamePerson): this account, every account linked to it, this
     * browser or device, and their deleted accounts. A failed one does not count.
     */
    public static function freeTryUsed(User $user, Request $request): bool
    {
        $ids = rescue(fn () => app(DemoSamePerson::class)->ids($user, $request), [(int) $user->id], false);
        if (rescue(fn () => app(DemoSamePerson::class)->leftBehindFreeStt($user, $request), false, false)) {
            return true;
        }
        $browser = DemoLinkedAccounts::browserFrom($request);
        $device = DemoLinkedAccounts::deviceFrom($request)['signal'] ?? null;
        $marked = self::marksFreeTries();

        return Transcription::withTrashed()
            ->where('is_free', true)
            ->where('status', '!=', Transcription::FAILED)
            ->where(function ($q) use ($ids, $user, $browser, $device, $marked): void {
                $q->whereIn('user_id', array_values(array_unique([...$ids, (int) $user->id])));
                if ($marked && $browser !== null) {
                    $q->orWhere('browser', $browser);
                }
                if ($marked && $device !== null) {
                    $q->orWhere('device', $device);
                }
            })
            ->exists();
    }

    /**
     * What a free try keeps of where it was asked from (its browser cookie and device),
     * so a new account there gets no second one.
     *
     * @return array{browser?: string|null, device?: string|null}
     */
    public static function freeTryMarks(Request $request): array
    {
        if (! self::marksFreeTries()) {
            return [];
        }

        return ['browser' => DemoLinkedAccounts::browserFrom($request), 'device' => DemoLinkedAccounts::deviceFrom($request)['signal'] ?? null];
    }

    /** False until the migration that keeps a free try's browser and device has run. */
    private static function marksFreeTries(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('transcriptions', 'browser'), false, false));
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
     * own that includes speech to text and fits a file of $fileSeconds with
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
            // As the pricing page names it.
            'plan' => trim($plan->getLocalizedName($arabic ? 'ar' : 'en')) ?: (string) $plan->name,
            'slug' => (string) $plan->slug,
            'url' => rescue(fn () => route('billing.page', ['plan' => $plan->slug]), route('pricing'), false),
        ];
    }
}
