<?php

namespace App\Support\Api;

use App\Models\Plan;
use App\Models\User;
use App\Services\Api\ApiSpeechService;
use App\Services\VoiceCloningService;
use App\Support\PlanFeatures;
use App\Support\VoiceCloningAccess;
use Illuminate\Support\Collection;

/**
 * Studio options that only some plans include, as the developer API sees
 * them: which plans include each one (read live from the plans, so editing a
 * plan changes the API and its documentation at once), and the error a
 * request gets when the member's plan does not include it.
 */
final class ApiFeatureGate
{
    public const CUSTOM_DIRECTION = 'custom_direction';

    public const SOUND_ENVIRONMENTS = 'sound_environments';

    public const DIALOGUE = 'dialogue';

    public const CLONED_VOICES = 'cloned_voices';

    /** @var array<string, string> feature => name in messages */
    private const NAMES = [
        self::CUSTOM_DIRECTION => 'Custom voice direction (style_prompt)',
        self::SOUND_ENVIRONMENTS => 'Sound environments (audio_effect)',
        self::DIALOGUE => 'Dialogue with several voices (dialogue)',
        self::CLONED_VOICES => 'Cloned voices',
    ];

    /** @var array<string, string> feature => its field in requests and in `plan.features` */
    public const FIELDS = [
        self::CUSTOM_DIRECTION => 'style_prompt',
        self::SOUND_ENVIRONMENTS => 'audio_effect',
        self::DIALOGUE => 'dialogue',
        self::CLONED_VOICES => 'cloned_voices',
    ];

    /**
     * Whether at least one plan with the API includes each feature. The
     * documentation and the dashboard leave out a feature no plan includes,
     * and show it again by themselves once a plan includes it.
     *
     * @return array<string, bool> feature => offered on some plan
     */
    public static function offered(): array
    {
        $plans = self::apiPlans();
        $offered = [];
        foreach (array_keys(self::NAMES) as $feature) {
            $offered[$feature] = $plans->contains(fn (Plan $plan): bool => self::planOffers($plan, $feature));
        }

        return $offered;
    }

    /**
     * The plans and packs on sale that include the developer API, cheapest first.
     *
     * @return Collection<int, Plan>
     */
    public static function apiPlans(): Collection
    {
        try {
            // Read fresh each time (a handful of rows), so a plan edited in the admin shows at once.
            return Plan::query()
                ->where('is_active', true)
                ->orderBy('price_cents')
                ->get()
                ->filter(fn (Plan $plan): bool => ApiPlanAccess::enabled($plan))
                ->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** Whether a plan includes a feature (as the dashboard decides it). */
    public static function planOffers(?Plan $plan, string $feature): bool
    {
        return match ($feature) {
            self::CUSTOM_DIRECTION => PlanFeatures::planOffers($plan, PlanFeatures::PROMPT),
            self::SOUND_ENVIRONMENTS => PlanFeatures::planOffers($plan, PlanFeatures::EFFECTS),
            self::DIALOGUE => PlanFeatures::planOffers($plan, PlanFeatures::DIALOGUE),
            // As the pricing card says it: the plan's switch, cloning switched on for the
            // site and open to every customer (not only the private preview).
            self::CLONED_VOICES => VoiceCloningService::planAllows($plan) && ! VoiceCloningAccess::isPreviewOnly(),
            default => false,
        };
    }

    /**
     * The options this member can use now, by request field (their plan, or
     * an admin's grant, as in the dashboard).
     *
     * @return array{style_prompt:bool, audio_effect:bool, dialogue:bool, cloned_voices:bool}
     */
    public static function availableTo(?User $user, ?Plan $plan): array
    {
        return [
            'style_prompt' => self::planOffers($plan, self::CUSTOM_DIRECTION),
            'audio_effect' => PlanFeatures::userHas($user, $plan, PlanFeatures::EFFECTS),
            'dialogue' => PlanFeatures::userHas($user, $plan, PlanFeatures::DIALOGUE),
            'cloned_voices' => VoiceCloningAccess::allows($user, $plan),
        ];
    }

    /**
     * Plans with the API that include the feature.
     *
     * @return Collection<int, Plan>
     */
    public static function plansWith(string $feature): Collection
    {
        return self::apiPlans()->filter(fn (Plan $plan): bool => self::planOffers($plan, $feature))->values();
    }

    /**
     * Plans with the API that include an audio format.
     *
     * @return Collection<int, Plan>
     */
    public static function plansWithFormat(string $format): Collection
    {
        return self::apiPlans()->filter(fn (Plan $plan): bool => in_array($format, ApiSpeechService::formatsFor($plan), true))->values();
    }

    /** The refusal for a feature the member's plan does not include. */
    public static function refusal(string $feature, ?string $param = null): ApiError
    {
        return self::refuse(self::NAMES[$feature] ?? $feature, self::plansWith($feature), $param, $feature);
    }

    public static function formatRefusal(string $format): ApiError
    {
        return self::refuse('The '.$format.' output format', self::plansWithFormat($format), 'output_format', 'output_format_'.$format);
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    private static function refuse(string $what, Collection $plans, ?string $param, string $feature): ApiError
    {
        $names = $plans->map(fn (Plan $plan): string => (string) ($plan->getLocalizedName('en') ?: $plan->name))->all();
        $message = $names === []
            ? "{$what} is not included in your plan."
            : "{$what} is not included in your plan. It is included in: ".implode(', ', $names).'. Upgrade at '.url('/pricing').'.';

        return new ApiError(403, ApiError::TYPE_PERMISSION, 'feature_not_in_plan', $message, $param, [], [
            'required_feature' => $feature,
            'available_on_plans' => $plans->pluck('slug')->values()->all(),
            'upgrade_url' => url('/pricing'),
        ]);
    }

    /**
     * Every gated feature and the plans that include it, for the documentation.
     *
     * @return array<string, array<int, string>> feature => plan names
     */
    public static function matrix(string $locale): array
    {
        $name = fn (Plan $plan): string => $plan->getLocalizedName($locale) ?: (string) $plan->name;
        $rows = [];
        foreach (array_keys(self::NAMES) as $feature) {
            $rows[$feature] = self::plansWith($feature)->map($name)->values()->all();
        }

        return $rows;
    }

    /** Most characters one request may carry on a plan: waiting for the file, or in the background. */
    public static function maxChars(?Plan $plan, bool $async = false): int
    {
        return ApiSpeechService::maxCharsFor($plan, $async);
    }
}
