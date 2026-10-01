<?php

namespace Tests\Feature;

use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionTrialingFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_trialing_filter_lists_users_with_an_open_trial(): void
    {
        $plan = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'allow_overage' => false, 'is_active' => true,
        ]);

        $trialUser = User::factory()->create();
        $paidUser = User::factory()->create();
        $paidTrialUser = User::factory()->create();

        $trialing = Subscription::create(['user_id' => $trialUser->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $paid = Subscription::create(['user_id' => $paidUser->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $converted = Subscription::create(['user_id' => $paidTrialUser->id, 'plan_id' => $plan->id, 'status' => 'active']);

        foreach ([[$trialUser, BillingTrial::STATUS_TRIALING, null], [$paidTrialUser, BillingTrial::STATUS_PAID, now()]] as [$user, $status, $paidAt]) {
            BillingTrial::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'quota_plan_id' => $plan->id,
                'status' => $status,
                'paid_at' => $paidAt,
                'stripe_subscription_id' => 'sub_'.$user->id,
                'amount_cents' => 900,
                'started_at' => now()->subDay(),
                'trial_ends_at' => now()->addDays(6),
            ]);
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSubscriptions::class)
            ->filterTable('status', 'trialing')
            ->assertCanSeeTableRecords([$trialing])
            ->assertCanNotSeeTableRecords([$paid, $converted])
            ->assertTableColumnStateSet('status', 'trialing', $trialing);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('status', 'active')
            ->assertCanSeeTableRecords([$paid, $converted])
            ->assertCanNotSeeTableRecords([$trialing]);
    }
}
