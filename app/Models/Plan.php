<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'price_cents',
        'monthly_minutes',
        'monthly_chars',
        'premium_voice_minutes',
        'monthly_requests',
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
        // Hybrid TTS fields
        'tts_provider_policy',
        'dialogue_enabled',
        'custom_prompt_enabled',
        'quality_tier',
        'queue_priority_weight',
        'recent_voices_days',
        // Voice cloning
        'voice_cloning_enabled',
        'voice_cloning_max_voices',
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
        'queue_priority_weight' => 'integer',
        'voice_cloning_enabled' => 'boolean',
        'voice_cloning_max_voices' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
        $translated = __($transKey);
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
}
