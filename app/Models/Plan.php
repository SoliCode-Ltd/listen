<?php

namespace App\Models;

use App\Services\Billing\StripePricePlans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    public const KIND_SUBSCRIPTION = 'subscription';

    /** A one-time character pack: bought once, never renews. */
    public const KIND_PACK = 'pack';

    /** How long each buyer keeps the plan's discount. */
    public const DISCOUNT_FIRST_PAYMENT = 'once';

    public const DISCOUNT_FIRST_MONTHS = 'repeating';

    public const DISCOUNT_FOREVER = 'forever';

    protected static function booted(): void
    {
        // Members who bought at the price a plan leaves keep paying it, so it
        // must keep meaning this plan.
        static::updating(function (Plan $plan): void {
            foreach (['stripe_price_monthly' => 'monthly', 'stripe_price_yearly' => 'yearly'] as $column => $cycle) {
                if ($plan->isDirty($column)) {
                    app(StripePricePlans::class)->rememberPlanPrice($plan, $plan->getOriginal($column), $cycle);
                }
            }
        });
    }

    protected $fillable = [
        'slug',
        'name',
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'price_cents',
        'discount_percentage',
        'discount_ends_at',
        'discount_duration',
        'discount_duration_months',
        'monthly_minutes',
        'monthly_chars',
        'premium_voice_minutes',
        'monthly_requests',
        'daily_requests',
        'max_chars_per_request',
        'allow_overage',
        'overage_price_per_million_cents',
        'topup_char_price_cents',
        'topup_minute_price_cents',
        'commercial_use_allowed',
        'processing_priority',
        'voice_tier',
        'features',
        'features_en',
        'features_ar',
        'button_text_en',
        'button_text_ar',
        'is_active',
        'is_featured',
        // Stripe integration
        'stripe_product_id',
        'stripe_price_monthly',
        'stripe_price_yearly',
        'stripe_coupon_id',
        // Hybrid TTS fields
        'tts_provider_policy',
        'dialogue_enabled',
        'custom_prompt_enabled',
        'sound_effects_enabled',
        'quality_tier',
        'queue_priority_weight',
        'recent_voices_days',
        // Voice cloning
        'voice_cloning_enabled',
        'video_dubbing_enabled',
        'video_dubs_per_month',
        'video_dub_max_seconds',
        'voice_cloning_max_voices',
        'download_formats',
        'kind',
        'pack_days',
        // Pricing page
        'pricing_lines',
        'pricing_extra_lines_en',
        'pricing_extra_lines_ar',
        'pricing_use_custom_text',
        // Developer API
        'api_access_enabled',
        'api_requests_per_minute',
        'api_concurrency',
    ];

    protected $casts = [
        'features' => 'array',
        'features_en' => 'array',
        'features_ar' => 'array',
        'allow_overage' => 'boolean',
        'commercial_use_allowed' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'dialogue_enabled' => 'boolean',
        'custom_prompt_enabled' => 'boolean',
        'sound_effects_enabled' => 'boolean',
        'queue_priority_weight' => 'integer',
        'discount_percentage' => 'integer',
        'discount_ends_at' => 'datetime',
        'discount_duration_months' => 'integer',
        'voice_cloning_enabled' => 'boolean',
        'video_dubbing_enabled' => 'boolean',
        'video_dubs_per_month' => 'integer',
        'video_dub_max_seconds' => 'integer',
        'voice_cloning_max_voices' => 'integer',
        'download_formats' => 'array',
        'pricing_lines' => 'array',
        'pricing_extra_lines_en' => 'array',
        'pricing_extra_lines_ar' => 'array',
        'pricing_use_custom_text' => 'boolean',
        'pack_days' => 'integer',
        'daily_requests' => 'integer',
        'api_access_enabled' => 'boolean',
        'api_requests_per_minute' => 'integer',
        'api_concurrency' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isPack(): bool
    {
        return $this->kind === self::KIND_PACK;
    }

    /** Whether members may write their own voice direction (the dashboard and the extension). */
    public function allowsCustomPrompt(): bool
    {
        $rawSlug = (string) ($this->slug ?? '');
        $slug = strtolower(trim((string) (preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $rawSlug) ?? $rawSlug)));

        return $slug !== 'starter' && (bool) ($this->custom_prompt_enabled ?? false);
    }

    /**
     * Monthly plans only: what members can subscribe to, trial, or be moved to.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Plan>  $query
     */
    public function scopeSubscriptionPlans($query)
    {
        return $query->where(fn ($q) => $q->where('kind', self::KIND_SUBSCRIPTION)->orWhereNull('kind'));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Plan>  $query
     */
    public function scopePacks($query)
    {
        return $query->where('kind', self::KIND_PACK);
    }

    /** How many days a pack lasts once bought. */
    public function packDays(): int
    {
        return max(1, (int) ($this->pack_days ?: 30));
    }

    /**
     * Get the Stripe price ID for the given billing cycle.
     *
     * @param string $cycle 'monthly' or 'yearly'
     * @return string|null
     */
    public function getStripePriceId(string $cycle): ?string
    {
        return $cycle === 'yearly'
            ? $this->stripe_price_yearly
            : $this->stripe_price_monthly;
    }

    /**
     * Check if Stripe is configured for this plan.
     */
    public function hasStripeConfig(): bool
    {
        return !empty($this->stripe_price_monthly) || !empty($this->stripe_price_yearly);
    }

    /**
     * Get localized plan name.
     * Priority: locale-specific field > translation file > fallback name
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        // Try locale-specific field (admin-configured)
        $field = "name_{$locale}";
        if (!empty($this->$field)) {
            return $this->$field;
        }

        // Try translation file (ui.pricing.plan_name_{slug})
        $transKey = "ui.pricing.plan_name_{$this->slug}";
        $translated = __($transKey, [], $locale);
        if ($translated !== $transKey) {
            return $translated;
        }

        // Fallback to default name
        return $this->name ?? $this->slug;
    }

    /**
     * Get localized plan description.
     * Priority: locale-specific field > translation file > empty
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        // Try locale-specific field (admin-configured)
        $field = "description_{$locale}";
        if (!empty($this->$field)) {
            return $this->$field;
        }

        // Try translation file
        $transKey = "ui.pricing.plan_desc_{$this->slug}";
        $translated = __($transKey);
        if ($translated !== $transKey) {
            return $translated;
        }

        return '';
    }

    /**
     * Get localized features array.
     * Priority: locale-specific features > empty (will trigger auto-generation)
     *
     * @return array<int, array{label: string, value: string, icon: string}>|null
     */
    public function getLocalizedFeatures(?string $locale = null): ?array
    {
        $locale = $locale ?? app()->getLocale();

        $field = "features_{$locale}";
        $features = $this->$field;

        if (is_array($features) && !empty($features)) {
            return $features;
        }

        // Return null to signal auto-generation should be used
        return null;
    }

    /**
     * Get localized button text.
     */
    public function getLocalizedButtonText(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        $field = "button_text_{$locale}";
        return !empty($this->$field) ? $this->$field : null;
    }

    /**
     * Check if this plan has an active (non-expired) discount.
     */
    public function hasActiveDiscount(): bool
    {
        if (empty($this->discount_percentage) || $this->discount_percentage <= 0) {
            return false;
        }

        if ($this->discount_ends_at === null) {
            return true; // No end date means indefinite discount
        }

        return $this->discount_ends_at->isFuture();
    }

    /**
     * How long each buyer keeps the discount: the first payment only (the
     * default), the first few months, or for as long as they stay subscribed.
     */
    public function discountDuration(): string
    {
        $duration = (string) ($this->discount_duration ?? '');

        if ($duration === self::DISCOUNT_FIRST_MONTHS && $this->discountMonths() !== null) {
            return self::DISCOUNT_FIRST_MONTHS;
        }

        return $duration === self::DISCOUNT_FOREVER ? self::DISCOUNT_FOREVER : self::DISCOUNT_FIRST_PAYMENT;
    }

    /**
     * For a "first few months" discount: how many monthly payments it covers (2 to 12).
     */
    public function discountMonths(): ?int
    {
        $months = (int) ($this->discount_duration_months ?? 0);

        return $months >= 2 && $months <= 12 ? $months : null;
    }

    /**
     * The duration fields of the Stripe coupon that gives this discount.
     *
     * @return array{duration: string, duration_in_months?: int}
     */
    public function stripeCouponDuration(): array
    {
        return match ($this->discountDuration()) {
            self::DISCOUNT_FIRST_MONTHS => ['duration' => 'repeating', 'duration_in_months' => (int) $this->discountMonths()],
            self::DISCOUNT_FOREVER => ['duration' => 'forever'],
            default => ['duration' => 'once'],
        };
    }

    /**
     * Get the discounted price in cents (monthly).
     */
    public function getDiscountedPriceCents(): int
    {
        if (! $this->hasActiveDiscount()) {
            return (int) $this->price_cents;
        }

        return (int) round($this->price_cents * (1 - $this->discount_percentage / 100));
    }

    /**
     * Get the effective monthly price in cents (respects active discounts).
     */
    public function getEffectivePriceCents(): int
    {
        return $this->hasActiveDiscount() ? $this->getDiscountedPriceCents() : (int) $this->price_cents;
    }

    /**
     * Get the effective yearly price in cents: twelve months less the yearly saving.
     * An offer is for monthly billing only, so a year never stacks one on top
     * (a deep offer on a whole year of minutes would cost more than it brings).
     */
    public function getEffectiveYearlyPriceCents(?int $yearlyDiscountPercent = null): int
    {
        $baseMonthlyCents = max(0, (int) $this->price_cents);
        $yearlyPercent = max(0, min(99, (int) ($yearlyDiscountPercent ?? SiteSetting::yearlyDiscountPercent())));

        return (int) round(($baseMonthlyCents * 12) * (1 - ($yearlyPercent / 100)));
    }
}
