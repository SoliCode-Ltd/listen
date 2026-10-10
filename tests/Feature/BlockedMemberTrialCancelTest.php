<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeTrialGateway;
use Tests\TestCase;

/**
 * A blocked member is not charged when their free trial ends: blocking
 * cancels the unpaid trial, where switched on (live only). Stripe is faked.
 */
class BlockedMemberTrialCancelTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeTrialGateway $stripe;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeTrialGateway();
        $this->app->instance(StripeTrialGateway::class, $this->stripe);
        config(['listen.abuse.block_cancels_trial' => true]);

        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'monthly_minutes' => 10,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->creator = Plan::factory()->create(['slug' => 'creator-x', 'price_cents' => 1900]);
    }

    public function test_blocking_cancels_the_open_trial_so_the_card_is_not_charged(): void
    {
        $member = User::factory()->create();
        $trial = $this->openTrial($member, 'sub_trial_1');
        $this->billingSubscription($member, 'sub_trial_1');

        app(BlockedUserService::class)->block($member, 'Abusive language');

        $this->assertSame(['sub_trial_1'], $this->stripe->canceled);
        $trial->refresh();
        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->status);
        $this->assertNotNull($trial->canceled_at);
        $this->assertSame('account_blocked', $trial->blocked_reason);
        $this->assertSame('canceled', BillingSubscription::where('stripe_id', 'sub_trial_1')->value('stripe_status'));
        $this->assertTrue($member->fresh()->is_blocked);
    }

    public function test_switched_off_by_default_so_staging_never_touches_stripe(): void
    {
        config(['listen.abuse.block_cancels_trial' => false]);
        $member = User::factory()->create();
        $trial = $this->openTrial($member, 'sub_trial_2');

        app(BlockedUserService::class)->block($member);

        $this->assertSame([], $this->stripe->canceled);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);
        $this->assertTrue($member->fresh()->is_blocked);
    }

    public function test_the_default_setting_is_off(): void
    {
        // Without LISTEN_BLOCK_CANCELS_TRIAL in .env (staging), nothing is cancelled.
        $this->assertFalse((require base_path('config/listen.php'))['abuse']['block_cancels_trial']);
    }

    public function test_a_paid_subscription_is_never_touched(): void
    {
        $member = User::factory()->create();
        $paid = $this->openTrial($member, 'sub_paid_1', [
            'status' => BillingTrial::STATUS_PAID,
            'paid_at' => now()->subDay(),
        ]);

        app(BlockedUserService::class)->block($member);

        $this->assertSame([], $this->stripe->canceled);
        $this->assertSame(BillingTrial::STATUS_PAID, $paid->fresh()->status);
    }

    public function test_when_stripe_refuses_the_trial_stays_visible_and_the_block_still_holds(): void
    {
        $this->app->instance(StripeTrialGateway::class, new class extends FakeStripeTrialGateway
        {
            public function cancelSubscription(string $subscriptionId): void
            {
                throw new \RuntimeException('Stripe is down');
            }
        });
        $member = User::factory()->create();
        $trial = $this->openTrial($member, 'sub_trial_3');

        app(BlockedUserService::class)->block($member);

        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status, 'still shown as running so the admin can act');
        $this->assertTrue($member->fresh()->is_blocked);
    }

    public function test_other_members_trials_are_left_alone(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->openTrial($member, 'sub_mine');
        $otherTrial = $this->openTrial($other, 'sub_theirs');

        app(BlockedUserService::class)->block($member);

        $this->assertSame(['sub_mine'], $this->stripe->canceled);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $otherTrial->fresh()->status);
    }

    public function test_an_automatic_block_cancels_the_trial_too(): void
    {
        $device = str_repeat('d', 48);
        User::factory()->count(2)->create(['registration_device_id' => $device]);
        $member = User::factory()->create(['registration_device_id' => $device]);
        $trial = $this->openTrial($member, 'sub_trial_auto');

        app(MultiAccountEnforcementService::class)->enforceAfterAuth($member->fresh());

        $this->assertTrue($member->fresh()->is_blocked);
        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->fresh()->status);
        $this->assertContains('sub_trial_auto', $this->stripe->canceled);
    }

    public function test_unblocking_does_not_restart_the_trial(): void
    {
        $member = User::factory()->create();
        $trial = $this->openTrial($member, 'sub_trial_4');

        app(BlockedUserService::class)->block($member);
        app(BlockedUserService::class)->unblock($member->fresh());

        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->fresh()->status);
        $this->assertCount(1, $this->stripe->canceled);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function openTrial(User $user, string $subscriptionId, array $overrides = []): BillingTrial
    {
        return BillingTrial::query()->create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $this->creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'is_admin_test' => false,
            'stripe_customer_id' => 'cus_'.$user->id,
            'stripe_subscription_id' => $subscriptionId,
            'card_brand' => 'mastercard',
            'card_last4' => '7130',
            'amount_cents' => 1900,
            'started_at' => now()->subDays(3),
            'trial_ends_at' => now()->addDays(4),
        ], $overrides));
    }

    private function billingSubscription(User $user, string $subscriptionId): void
    {
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_creator_month',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(4),
        ]);
    }
}
