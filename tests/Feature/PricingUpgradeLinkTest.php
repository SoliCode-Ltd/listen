<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

class PricingUpgradeLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_page_exposes_monthly_and_yearly_billing_urls_for_upgrade_buttons(): void
    {
        $user = User::factory()->create();

        Plan::factory()->create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_chars' => 5000,
            'monthly_requests' => 60,
            'max_chars_per_request' => 1200,
            'voice_tier' => 'standard',
            'is_active' => true,
        ]);

        Plan::factory()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 565,
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
            'voice_tier' => 'hd',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
            ])
            ->get($this->localizedRoute('pricing'));

        $monthlyUrl = route('billing.page', ['plan' => 'starter', 'billing_cycle' => 'monthly']);
        $yearlyUrl = route('billing.page', ['plan' => 'starter', 'billing_cycle' => 'yearly']);
        $escapedMonthlyUrl = e($monthlyUrl);
        $escapedYearlyUrl = e($yearlyUrl);

        $response->assertOk()
            ->assertSee('data-upgrade-link="1"', false)
            ->assertSee('href="'.$escapedMonthlyUrl.'"', false)
            ->assertSee('data-monthly-href="'.$escapedMonthlyUrl.'"', false)
            ->assertSee('data-yearly-href="'.$escapedYearlyUrl.'"', false);
    }

    public function test_billing_page_honors_selected_plan_and_yearly_cycle_from_query_string(): void
    {
        $user = User::factory()->create();

        Plan::factory()->create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_chars' => 5000,
            'monthly_requests' => 60,
            'max_chars_per_request' => 1200,
            'voice_tier' => 'standard',
            'is_active' => true,
        ]);

        Plan::factory()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 565,
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
            'voice_tier' => 'hd',
            'is_active' => true,
        ]);

        $billingUrl = route('billing.page', [
            'plan' => 'starter',
            'billing_cycle' => 'yearly',
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
            ])
            ->get($billingUrl);

        $response->assertOk()
            ->assertViewHas('selectedPlan', 'starter')
            ->assertSee('name="billing_cycle" id="manualCycleField" value="yearly"', false)
            ->assertSee('value="yearly" selected', false);
    }
}
