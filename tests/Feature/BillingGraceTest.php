<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingLifecycleService;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * When a grace period ends, Stripe is asked before anything is cancelled: a
 * member who has paid since keeps their plan, and staging never cancels.
 */
class BillingGraceTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $member;

    private int $syncs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);
        Cache::flush();

        $this->stripe = new FakeStripeHttpClient;
        $this->stripe->answer('delete', '/v1/subscriptions/sub_grace', ['id' => 'sub_grace', 'object' => 'subscription', 'status' => 'canceled']);
        ApiRequestor::setHttpClient($this->stripe);
        $this->mock(BillingSyncService::class, fn ($mock) => $mock->shouldReceive('syncIfStale')->andReturnUsing(function () {
            $this->syncs++;
        }));

        $starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
        ]);
        $this->member = User::factory()->create(['stripe_id' => 'cus_grace']);
        app(PlanAccessService::class)->assignPlan($this->member, $starter, 'active', now()->addDays(20), now()->subDays(10));
        $this->plan()->forceFill([
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => now()->subHour(),
            'billing_failed_notice_sent_at' => now()->subDays(7),
        ])->save();
        // Our copy can be behind: it still says active.
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_grace',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        // Only what the grace job sends counts (not the welcome email above).
        app('mail.manager')->mailer()->getSymfonyTransport()->flush();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function plan(): Subscription
    {
        return Subscription::query()->where('user_id', $this->member->id)->latest('id')->firstOrFail();
    }

    private function runGraceJob(): int
    {
        return app(BillingLifecycleService::class)->downgradeExpiredGraceSubscriptions();
    }

    /** @return list<string> */
    private function sentSubjects(): array
    {
        return app('mail.manager')->mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => (string) $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    public function test_a_member_who_has_paid_since_keeps_their_plan(): void
    {
        $this->stripe->subscription('sub_grace', 'active', 'cus_grace');

        $this->assertSame(0, $this->runGraceJob());

        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertNull($plan->billing_failed_notice_sent_at);
        $this->assertNull($plan->billing_downgraded_at);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_subscription_still_owed_after_the_grace_period_is_cancelled(): void
    {
        $this->stripe->subscription('sub_grace', 'past_due', 'cus_grace');

        $this->assertSame(1, $this->runGraceJob());

        $this->assertTrue($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        $this->assertSame('canceled', BillingSubscription::query()->where('stripe_id', 'sub_grace')->value('stripe_status'));
        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNotNull($plan->billing_downgraded_at);
        $this->assertSame(['Your subscription was canceled'], $this->sentSubjects());
        $this->assertStringContainsString('Grace Period Ended: '.now()->subHour()->format('M j, Y'), $this->sentBody());
        $this->assertSame(1, $this->syncs);
    }

    public function test_staging_never_cancels_or_emails_by_itself(): void
    {
        config(['listen.live_actions' => false]);
        $this->stripe->subscription('sub_grace', 'unpaid', 'cus_grace');

        $this->assertSame(0, $this->runGraceJob());

        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_subscription_that_already_ended_is_not_cancelled_again(): void
    {
        $this->stripe->subscription('sub_grace', 'canceled', 'cus_grace');

        $this->assertSame(0, $this->runGraceJob());

        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        $this->assertSame('ok', $this->plan()->billing_payment_state);
        $this->assertNull($this->plan()->billing_grace_ends_at);
        $this->assertSame([], $this->sentSubjects());
        // The sync moves the plan to what Stripe now says.
        $this->assertSame(1, $this->syncs);
    }

    public function test_when_stripe_cannot_be_read_nothing_changes_until_the_next_run(): void
    {
        $this->stripe->unreachable = true;

        $this->assertSame(0, $this->runGraceJob());

        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertNotNull($this->plan()->billing_grace_ends_at);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_plan_paid_by_hand_is_left_alone(): void
    {
        $this->plan()->forceFill(['billing_payment_state' => 'manual_override'])->save();
        $this->stripe->subscription('sub_grace', 'past_due', 'cus_grace');

        $this->assertSame(0, $this->runGraceJob());

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame('manual_override', $this->plan()->billing_payment_state);
    }

    private function sentBody(): string
    {
        $sent = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last();

        return (string) $sent?->getOriginalMessage()->getTextBody();
    }
}
