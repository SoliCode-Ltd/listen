<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSyncManualOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_override_subscription_skips_stripe_sync_and_keeps_admin_plan(): void
    {
        $free = Plan::create([
            'slug' => 'free',
            'name' => 'FREE',
            'price_cents' => 0,
            'monthly_minutes' => 300,
            'monthly_chars' => 30000,
            'monthly_requests' => 60,
            'max_chars_per_request' => 600,
            'is_active' => true,
        ]);

        $creator = Plan::create([
            'slug' => 'creator',
            'name' => 'CREATOR',
            'price_cents' => 1900,
            'monthly_minutes' => 5000,
            'monthly_chars' => 500000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'stripe_id' => 'cus_fake_should_not_be_called',
            'subscription_status_checked_at' => null,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => 'active',
            'billing_payment_state' => 'manual_override',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        app(BillingSyncService::class)->syncIfStale($user, true);

        $user->refresh();

        $activePlan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);

        $this->assertNotNull($activePlan);
        $this->assertSame($creator->id, $activePlan->id);
        $this->assertNotNull($user->subscription_status_checked_at);
        $this->assertNotSame($free->id, $activePlan->id);
    }
}
