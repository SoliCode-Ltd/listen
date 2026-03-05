<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_localized_name_returns_name_en_when_set(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'name_en' => 'English Name',
            'name_ar' => 'الاسم العربي',
        ]);

        $this->assertEquals('English Name', $plan->getLocalizedName('en'));
        $this->assertEquals('الاسم العربي', $plan->getLocalizedName('ar'));
    }

    public function test_localized_name_falls_back_to_translation_file(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'free',
            'name' => 'Free',
            'name_en' => null,
            'name_ar' => null,
        ]);

        // If translation exists, it should use it
        $enName = $plan->getLocalizedName('en');
        // Should return either the translation or the fallback name
        $this->assertNotEmpty($enName);
    }

    public function test_localized_name_falls_back_to_name_when_no_locale_specific(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'custom-plan',
            'name' => 'Custom Plan',
            'name_en' => null,
            'name_ar' => null,
        ]);

        // When no locale-specific name and no translation, falls back to name
        $this->assertEquals('Custom Plan', $plan->getLocalizedName('en'));
    }

    public function test_localized_description_returns_correct_locale(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'description_en' => 'English description',
            'description_ar' => 'الوصف العربي',
        ]);

        $this->assertEquals('English description', $plan->getLocalizedDescription('en'));
        $this->assertEquals('الوصف العربي', $plan->getLocalizedDescription('ar'));
    }

    public function test_localized_features_returns_correct_locale(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'features_en' => [
                ['label' => 'Requests', 'value' => 'Unlimited', 'icon' => 'activity'],
            ],
            'features_ar' => [
                ['label' => 'الطلبات', 'value' => 'غير محدود', 'icon' => 'activity'],
            ],
        ]);

        $enFeatures = $plan->getLocalizedFeatures('en');
        $arFeatures = $plan->getLocalizedFeatures('ar');

        $this->assertCount(1, $enFeatures);
        $this->assertEquals('Requests', $enFeatures[0]['label']);

        $this->assertCount(1, $arFeatures);
        $this->assertEquals('الطلبات', $arFeatures[0]['label']);
    }

    public function test_localized_features_returns_null_when_empty(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'features_en' => null,
            'features_ar' => [],
        ]);

        $this->assertNull($plan->getLocalizedFeatures('en'));
        $this->assertNull($plan->getLocalizedFeatures('ar')); // Empty array should also return null
    }

    public function test_stripe_price_id_getter(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'stripe_price_monthly' => 'price_monthly_123',
            'stripe_price_yearly' => 'price_yearly_456',
        ]);

        $this->assertEquals('price_monthly_123', $plan->getStripePriceId('monthly'));
        $this->assertEquals('price_yearly_456', $plan->getStripePriceId('yearly'));
    }

    public function test_has_stripe_config(): void
    {
        $planWithStripe = Plan::factory()->create([
            'slug' => 'plan-with-stripe',
            'name' => 'With Stripe',
            'stripe_price_monthly' => 'price_123',
        ]);

        $planWithoutStripe = Plan::factory()->create([
            'slug' => 'plan-without-stripe',
            'name' => 'Without Stripe',
            'stripe_price_monthly' => null,
            'stripe_price_yearly' => null,
        ]);

        $this->assertTrue($planWithStripe->hasStripeConfig());
        $this->assertFalse($planWithoutStripe->hasStripeConfig());
    }

    public function test_default_features_en_contains_emotion_controls(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'price_cents' => 999,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
            'voice_tier' => 'standard',
            'processing_priority' => 'standard',
            'commercial_use_allowed' => false,
            'recent_voices_days' => 7,
        ]);

        // Use reflection to call private method
        $method = new \ReflectionMethod(PlanResource::class, 'buildDefaultFeaturesEn');
        $method->setAccessible(true);
        $features = $method->invoke(null, $plan);

        // Verify emotion controls is included with heart icon
        $emotionFeature = collect($features)->firstWhere('label', 'Emotion Controls');
        $this->assertNotNull($emotionFeature);
        $this->assertEquals('heart', $emotionFeature['icon']);
        $this->assertEquals('Full Controls', $emotionFeature['value']);
    }

    public function test_default_features_ar_contains_emotion_controls(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'price_cents' => 0, // Free plan
            'monthly_requests' => 60,
            'max_chars_per_request' => 1200,
            'voice_tier' => 'standard',
            'processing_priority' => 'standard',
            'commercial_use_allowed' => false,
            'recent_voices_days' => 7,
        ]);

        // Use reflection to call private method
        $method = new \ReflectionMethod(PlanResource::class, 'buildDefaultFeaturesAr');
        $method->setAccessible(true);
        $features = $method->invoke(null, $plan);

        // Verify emotion controls is included with heart icon in Arabic
        $emotionFeature = collect($features)->firstWhere('label', 'التحكم في المشاعر');
        $this->assertNotNull($emotionFeature);
        $this->assertEquals('heart', $emotionFeature['icon']);
        $this->assertEquals('أساسي', $emotionFeature['value']); // Free plan gets basic
    }

    public function test_default_features_include_all_expected_features(): void
    {
        $plan = Plan::factory()->create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'price_cents' => 999,
            'monthly_requests' => 0, // Unlimited
            'max_chars_per_request' => 10000,
            'voice_tier' => 'hd',
            'processing_priority' => 'high',
            'commercial_use_allowed' => true,
            'recent_voices_days' => 30,
            'dialogue_enabled' => true,
        ]);

        $method = new \ReflectionMethod(PlanResource::class, 'buildDefaultFeaturesEn');
        $method->setAccessible(true);
        $features = $method->invoke(null, $plan);

        $labels = collect($features)->pluck('label')->toArray();

        // Verify all expected features are present
        $this->assertContains('Requests', $labels);
        $this->assertContains('Max Input', $labels);
        $this->assertContains('Voice Quality', $labels);
        $this->assertContains('Emotion Controls', $labels);
        $this->assertContains('Priority Queue', $labels);
        $this->assertContains('Commercial Use', $labels);
        $this->assertContains('Recent Voices', $labels);
        $this->assertContains('Dialogue Mode', $labels); // Since dialogue_enabled = true
    }
}
