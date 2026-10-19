<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LiveServer;
use App\Support\PaidMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two shared answers several safety rules depend on: may this server act on
 * real customers by itself, and is a member someone who pays or has paid.
 */
class LiveServerAndPaidMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_actions_follow_the_explicit_setting(): void
    {
        config(['listen.live_actions' => false, 'app.url' => 'https://listenai.studio']);
        $this->assertFalse(LiveServer::allowsCustomerActions());

        config(['listen.live_actions' => true, 'app.url' => 'https://staging.example.test']);
        $this->assertTrue(LiveServer::allowsCustomerActions());

        config(['listen.live_actions' => 'false']);
        $this->assertFalse(LiveServer::allowsCustomerActions());
    }

    public function test_without_a_setting_only_the_live_address_acts_outside_the_test_suite(): void
    {
        config(['listen.live_actions' => null]);
        // runningUnitTests() reads the environment, so production here means "a real server".
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://listenai.studio']);
        $this->assertTrue(LiveServer::allowsCustomerActions());

        config(['app.url' => 'https://www.listenai.studio/']);
        $this->assertTrue(LiveServer::allowsCustomerActions());

        config(['app.url' => 'https://phplaravel-1516130-6707450.cloudwaysapps.com']);
        $this->assertFalse(LiveServer::allowsCustomerActions());

        config(['app.url' => 'http://localhost:8000']);
        $this->assertFalse(LiveServer::allowsCustomerActions());

        $this->app->detectEnvironment(fn () => 'testing');
        $this->assertTrue(LiveServer::allowsCustomerActions());
    }

    public function test_free_members_are_not_paid_members(): void
    {
        $this->plans();
        $user = User::factory()->create();

        $this->assertFalse(PaidMember::is($user));
        $this->assertFalse(PaidMember::is(null));
    }

    public function test_a_current_paid_plan_makes_a_paid_member(): void
    {
        [, $starter] = $this->plans();
        $user = User::factory()->create();
        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $starter->id, 'status' => 'active',
            'current_period_end' => now()->addDays(10),
        ]);

        $this->assertTrue(PaidMember::is($user));
    }

    public function test_past_payments_make_a_paid_member(): void
    {
        [, $starter] = $this->plans();

        $pack = User::factory()->create();
        CharPackPurchase::query()->forceCreate([
            'user_id' => $pack->id, 'method' => 'card', 'status' => CharPackPurchase::STATUS_PAID,
            'chars' => 1000, 'days' => 30, 'amount_minor' => 500, 'currency' => 'USD', 'paid_at' => now()->subMonths(3),
        ]);
        $this->assertTrue(PaidMember::is($pack));

        $manual = User::factory()->create();
        ManualPaymentRequest::query()->forceCreate([
            'user_id' => $manual->id, 'plan_id' => $starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'instapay', 'expected_amount_minor' => 50000, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_APPROVED,
        ]);
        $this->assertTrue(PaidMember::is($manual));

        $trial = User::factory()->create();
        BillingTrial::query()->forceCreate([
            'user_id' => $trial->id, 'plan_id' => $starter->id, 'status' => BillingTrial::STATUS_PAID,
            'trial_ends_at' => now()->subMonth(), 'paid_at' => now()->subMonth(),
        ]);
        $this->assertTrue(PaidMember::is($trial));

        $card = User::factory()->create();
        BillingSubscription::query()->create([
            'user_id' => $card->id, 'type' => 'default', 'stripe_id' => 'sub_paid_once',
            'stripe_status' => 'canceled', 'ends_at' => now()->subMonth(),
        ]);
        $this->assertTrue(PaidMember::is($card));
    }

    public function test_an_unpaid_trial_or_failed_payment_is_not_paid_history(): void
    {
        $this->plans();
        $user = User::factory()->create();
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_trial_cancelled',
            'stripe_status' => 'canceled', 'trial_ends_at' => now()->subWeek(), 'ends_at' => now()->subWeek(),
        ]);
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_never_paid',
            'stripe_status' => 'incomplete_expired',
        ]);
        CharPackPurchase::query()->forceCreate([
            'user_id' => $user->id, 'method' => 'card', 'status' => CharPackPurchase::STATUS_FAILED,
            'chars' => 1000, 'days' => 30, 'amount_minor' => 500, 'currency' => 'USD',
        ]);

        $this->assertFalse(PaidMember::is($user));
    }

    /**
     * @return array{0: Plan, 1: Plan}
     */
    private function plans(): array
    {
        $free = Plan::query()->where('slug', 'free')->first() ?? Plan::create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 5,
            'monthly_chars' => 4500, 'monthly_requests' => 50, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $starter = Plan::query()->where('slug', 'starter')->first() ?? Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250,
            'monthly_chars' => 225000, 'monthly_requests' => 3000, 'max_chars_per_request' => 3000, 'is_active' => true,
        ]);

        return [$free, $starter];
    }
}
