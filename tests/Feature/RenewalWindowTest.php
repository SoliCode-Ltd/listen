<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A card plan renews a little after its period ends. In between, the member
 * stays on their paid plan (never Free with standard voices), and the renewal
 * is looked up at the next page view.
 */
class RenewalWindowTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);

        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m',
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** A card member whose month ended $minutesAgo minutes ago, last checked $checkedMinutesAgo minutes ago. */
    private function cardMember(int $minutesAgo, string $stripeStatus = 'active', int $checkedMinutesAgo = 1): User
    {
        $member = User::factory()->create([
            'stripe_id' => 'cus_renew',
            'subscription_status_checked_at' => now()->subMinutes($checkedMinutesAgo),
        ]);
        app(PlanAccessService::class)->assignPlan(
            $member,
            $this->starter,
            'active',
            now()->subMinutes($minutesAgo)->startOfSecond(),
            now()->subMinutes($minutesAgo)->subMonth()->startOfSecond(),
        );
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_renew_'.$member->id,
            'stripe_status' => $stripeStatus, 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);

        return $member;
    }

    private function activePlan(User $member): ?string
    {
        return app(PlanAccessService::class)->resolveActivePlanForUserId((int) $member->id)?->slug;
    }

    public function test_a_card_member_keeps_their_plan_while_the_renewal_comes_through(): void
    {
        $member = $this->cardMember(30);

        $this->assertSame('starter', $this->activePlan($member));

        $this->travel(2)->hours();
        $this->assertSame('free', $this->activePlan($member));
    }

    public function test_only_a_card_subscription_that_still_runs_keeps_the_plan(): void
    {
        $canceled = $this->cardMember(30, 'canceled');
        $this->assertSame('free', $this->activePlan($canceled));

        // Cancelled to end with this period.
        $ending = $this->cardMember(30);
        BillingSubscription::query()->where('user_id', $ending->id)->update(['ends_at' => now()->subMinutes(30)]);
        $this->assertSame('free', $this->activePlan($ending));

        // A plan given by hand ends on its date.
        $given = User::factory()->create();
        app(PlanAccessService::class)->assignPlan($given, $this->starter, 'active', now()->subMinutes(30));
        $this->assertSame('free', $this->activePlan($given));
    }

    public function test_signing_in_while_the_renewal_comes_through_keeps_the_paid_plan(): void
    {
        $member = $this->cardMember(30);

        app(PlanAccessService::class)->ensureFreePlanForUser($member);

        $this->assertSame((int) $this->starter->id, (int) Subscription::query()->where('user_id', $member->id)->latest('id')->value('plan_id'));
    }

    public function test_the_first_page_view_after_the_period_ended_looks_up_the_renewal(): void
    {
        // Checked two minutes ago, before the period ended a minute ago.
        $member = $this->cardMember(1, 'active', 2);
        $renewedUntil = now()->addMonth()->subMinute()->startOfSecond();
        $this->stripe->answer('get', '/v1/customers/cus_renew', ['id' => 'cus_renew', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]);
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'has_more' => false, 'url' => '/v1/subscriptions', 'data' => [[
            'id' => 'sub_renew_'.$member->id, 'object' => 'subscription', 'customer' => 'cus_renew', 'status' => 'active',
            'created' => time() - 40 * 86400, 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null, 'trial_end' => null,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_renew', 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => now()->subMinute()->getTimestamp(), 'current_period_end' => $renewedUntil->getTimestamp(),
                'price' => ['id' => 'price_starter_m', 'object' => 'price', 'product' => 'prod_x', 'recurring' => ['interval' => 'month']],
            ]]],
        ]]]);

        app(BillingSyncService::class)->syncIfStale($member);

        $this->assertTrue($this->stripe->sent('get', '/v1/subscriptions'));
        $this->assertEquals($renewedUntil, Subscription::query()->where('user_id', $member->id)->latest('id')->first()->current_period_end);
    }

    public function test_stripe_is_not_asked_again_and_again_once_it_was_asked_after_the_period_ended(): void
    {
        // Ended five minutes ago and already checked since.
        $member = $this->cardMember(5, 'active', 1);
        app(BillingSyncService::class)->syncIfStale($member);

        // Ended three days ago: the normal check every few minutes is enough.
        $old = $this->cardMember(3 * 24 * 60, 'active', 3 * 24 * 60 + 5);
        $old->forceFill(['subscription_status_checked_at' => now()->subMinute()])->save();
        app(BillingSyncService::class)->syncIfStale($old);

        $this->assertSame([], $this->stripe->requests);
    }
}
