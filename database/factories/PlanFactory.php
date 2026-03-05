<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'price_cents' => $this->faker->randomElement([0, 999, 1999, 4999, 9999]),
            'monthly_chars' => $this->faker->randomElement([30000, 100000, 500000, 2000000]),
            'monthly_requests' => $this->faker->randomElement([0, 100, 500, 0]),
            'max_chars_per_request' => $this->faker->randomElement([1000, 5000, 10000, 50000]),
            'allow_overage' => $this->faker->boolean(30),
            'commercial_use_allowed' => $this->faker->boolean(50),
            'processing_priority' => $this->faker->randomElement(['standard', 'high', 'priority']),
            'voice_tier' => $this->faker->randomElement(['standard', 'hd']),
            'is_active' => true,
            'is_featured' => false,
            // Localized fields
            'name_en' => null,
            'name_ar' => null,
            'description_en' => null,
            'description_ar' => null,
            'features_en' => null,
            'features_ar' => null,
            'button_text_en' => null,
            'button_text_ar' => null,
            // Stripe fields
            'stripe_product_id' => null,
            'stripe_price_monthly' => null,
            'stripe_price_yearly' => null,
            // TTS fields
            'tts_provider_policy' => 'default',
            'dialogue_enabled' => false,
            'quality_tier' => 'standard',
            'queue_priority_weight' => 0,
            'recent_voices_days' => 7,
        ];
    }

    /**
     * Indicate the plan is free.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
        ]);
    }

    /**
     * Indicate the plan is a paid plan.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_cents' => $this->faker->randomElement([999, 1999, 4999]),
        ]);
    }

    /**
     * Indicate the plan is featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Set localized content.
     */
    public function localized(string $nameEn, string $nameAr, ?string $descEn = null, ?string $descAr = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'description_en' => $descEn,
            'description_ar' => $descAr,
        ]);
    }

    /**
     * Set Stripe configuration.
     */
    public function withStripe(string $productId, string $monthlyPriceId, ?string $yearlyPriceId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_product_id' => $productId,
            'stripe_price_monthly' => $monthlyPriceId,
            'stripe_price_yearly' => $yearlyPriceId,
        ]);
    }
}
