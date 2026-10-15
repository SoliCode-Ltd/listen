<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Throwable;

/**
 * Studio options a plan may or may not include. A member whose plan lacks
 * one still sees it in the dashboard, dimmed, with a button to the billing
 * page that opens on the cheapest plan that has it.
 */
final class PlanFeatures
{
    /** Dialect, style, emotion, speed, pitch, tags and the extra languages: the premium voices. */
    public const STUDIO = 'studio';

    public const EFFECTS = 'effects';

    public const DIALOGUE = 'dialogue';

    public const PROMPT = 'prompt';

    /**
     * Options an admin can give one member for a while (UserResource → Feature trials).
     *
     * @var array<string, string>
     */
    public const GRANTABLE = [
        self::DIALOGUE => 'Dialogue mode (needs a plan on the premium voices)',
        self::EFFECTS => 'Sound environments',
    ];

    /**
     * Whether this member can use the option now: their plan has it, or an
     * admin gave it to them and the time has not run out.
     */
    public static function userHas(?User $user, ?Plan $plan, string $feature): bool
    {
        if (self::planOffers($plan, $feature)) {
            return true;
        }
        if (! $user || ! isset(self::GRANTABLE[$feature]) || ! self::granted($user, $feature)) {
            return false;
        }
        // Dialogue needs the premium voices whatever the grant says.
        if ($feature === self::DIALOGUE) {
            return $plan !== null && VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) === VoiceTier::HD;
        }

        return true;
    }

    /**
     * An active admin grant of the option to this member.
     */
    public static function granted(User $user, string $feature): bool
    {
        try {
            return UserFeatureGrant::query()
                ->where('user_id', $user->id)
                ->where('feature', $feature)
                ->active()
                ->exists();
        } catch (Throwable) {
            // Before the migration has run on a server: no grants.
            return false;
        }
    }

    public static function planOffers(?Plan $plan, string $feature): bool
    {
        if (! $plan) {
            return false;
        }
        $premium = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) === VoiceTier::HD;

        return match ($feature) {
            self::STUDIO => $premium,
            self::EFFECTS => (bool) ($plan->sound_effects_enabled ?? false),
            self::DIALOGUE => $premium && (bool) ($plan->dialogue_enabled ?? false),
            self::PROMPT => $premium && $plan->allowsCustomPrompt(),
            default => false,
        };
    }

    /**
     * The cheapest plan on sale that includes the feature.
     */
    public static function cheapestWith(string $feature): ?Plan
    {
        try {
            $plans = once(fn () => Plan::query()
                ->subscriptionPlans()
                ->where('is_active', true)
                ->where('price_cents', '>', 0)
                ->orderBy('price_cents')
                ->get());
        } catch (Throwable) {
            return null;
        }

        return $plans->first(fn (Plan $plan): bool => self::planOffers($plan, $feature));
    }

    /**
     * Null when the member has the feature; otherwise where the upgrade
     * button goes and the plan it names (null name: no single plan to name).
     *
     * @return array{url: string, plan: ?string, none?: bool}|null
     */
    public static function lock(string $feature, bool $allowed, bool $arabic): ?array
    {
        if ($allowed) {
            return null;
        }

        $plan = self::cheapestWith($feature);
        if (! $plan) {
            // No plan on sale includes it: nothing to offer, so the option stays hidden.
            return ['url' => route('pricing'), 'plan' => null, 'none' => true];
        }

        $name = $arabic && filled($plan->name_ar) ? (string) $plan->name_ar : (string) $plan->name;

        return ['url' => route('billing.page', ['plan' => $plan->slug]), 'plan' => $name];
    }
}
