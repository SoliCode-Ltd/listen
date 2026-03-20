<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardUsageMeterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_usage_meters_render_remaining_quota(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);

        $plan = Plan::factory()->free()->create([
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        UsageCounter::create([
            'user_id' => $user->id,
            'period_ym' => now()->format('Y-m'),
            'total_minutes' => 8,
            'total_chars' => 20000,
            'total_requests' => 28,
        ]);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard');

        $response->assertOk();
        $response->assertSee('480,000 / 500,000 chars remaining', false);
        $response->assertSee('72 / 100 requests remaining', false);
        $response->assertSee('id="usageCharsMeter" style="width:96%"', false);
        $response->assertSee('id="usageRequestsMeter" style="width:72%"', false);
    }
}
