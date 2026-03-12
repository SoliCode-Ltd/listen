<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAutoFreePlanAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_gets_active_free_subscription_automatically(): void
    {
        $freePlan = Plan::factory()->create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($freePlan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
    }

    public function test_new_user_creation_does_not_fail_when_no_plan_exists(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $user->id,
        ]);
    }
}

