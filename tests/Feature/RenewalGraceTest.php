<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\RenewalGrace;
use App\Services\BillingLifecycleService;
use App\Services\BillingSyncService;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A failed renewal never costs a paying member their plan at once: they keep
 * it, with every paid feature and the credits of the month they paid for,
 * until the grace days run out. Paid in time, the new month starts with a
 * fresh allowance; unpaid, the subscription is cancelled and they are on
 * Free. A member who never paid (a free trial's first charge) gets no grace.
 */
class RenewalGraceTest extends TestCase
{
    use RefreshDatabase;

    /** The month the member paid for, and the renewal Stripe could not charge. */
    private const PAID_FROM = '2026-09-14 10:00:00';

    private const RENEWS_AT = '2026-10-14 10:00:00';

    private const NEXT_RENEWAL = '2026-11-14 10:00:00';

    /** The key of the paid month's credits. */
    private const PAID_KEY = '2026-09-14 10:00';

    private FakeStripeHttpClient $stripe;

    private Plan $free;

    private Plan $starter;

    private User $member;

    /** The body of the last email sentEmails() saw. */
    private ?string $lastBody = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => '',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);
        Cache::flush();

        $this->stripe = new class extends FakeStripeHttpClient
        {
            /** @var array{0: string, 1: \Closure}|null */
            private ?array $meanwhile = null;

            /** Runs $then the first time Stripe is asked for $path: an event arriving while a sync reads Stripe. */
            public function meanwhile(string $path, \Closure $then): void
            {
                $this->meanwhile = [$path, $then];
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                if ($this->meanwhile !== null && (string) parse_url($absUrl, PHP_URL_PATH) === $this->meanwhile[0]) {
                    $then = $this->meanwhile[1];
                    $this->meanwhile = null;
                    $then();
                }

                return parent::request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
            }
        };
        ApiRequestor::setHttpClient($this->stripe);
        // As on the servers: paid plans' credits follow the plan's own dates.
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-09-01 00:00:00', 'billing');
        // Only the billing emails these tests are about.
        app(PaidWelcomeEmail::class)->save(['enabled' => false]);

        $this->free = Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'voice_tier' => 'standard',
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m',
        ]);

        // A card member on Starter since August, in the month from 14 September.
        $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
        $this->member = User::factory()->create([
            'name' => 'Mona', 'email' => 'mona@example.test', 'stripe_id' => 'cus_grace', 'subscription_status_checked_at' => now(),
        ]);
        app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', Carbon::parse(self::RENEWS_AT), Carbon::parse(self::PAID_FROM));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_grace',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        $this->spend(4000);
        $this->sentEmails();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_failed_renewal_keeps_the_plan_its_premium_voices_and_the_credits_of_the_month_paid_for(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        // Stripe has moved its own dates on to the new, unpaid month.
        $this->stripeHas('past_due');

        $this->paymentFailed()->assertOk();

        $plan = $this->plan();
        $this->assertSame('past_due', $plan->billing_payment_state);
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $plan->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);

        // Billing syncs during the grace (Stripe still past due, with its new month) change nothing.
        foreach (['2026-10-14 11:20:00', '2026-10-16 09:00:00', '2026-10-21 10:00:00'] as $moment) {
            $this->travelTo(Carbon::parse($moment));
            $this->sync();
            $this->assertKeptOnThePaidMonth(4000);
            $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        }

        // Credits used go on counting where they were.
        $this->spend(1500);
        $this->assertSame(5500, $this->used());
        $this->assertSame(self::PAID_KEY, $this->creditKey());
    }

    public function test_the_warning_says_the_plan_stays_on_until_the_grace_ends_and_what_happens_then(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');

        $this->paymentFailed()->assertOk();

        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());
        $body = $this->lastBody();
        $this->assertStringContainsString('We could not take your latest payment for Starter.', $body);
        $this->assertStringContainsString('Your plan stays on until Oct 21, 2026, with all its paid features.', $body);
        $this->assertStringContainsString('If it is still unpaid on Oct 21, 2026, your subscription will be canceled and your account will move to the free plan.', $body);
        $this->assertStringContainsString('- Plan Active Until: Oct 21, 2026', $body);
        $this->assertStringNotContainsString('paused', $body);
        $this->assertNotNull($this->plan()->billing_failed_notice_sent_at);
    }

    public function test_the_grace_starts_once_when_a_billing_sync_sees_the_missed_payment_first(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');

        // Stripe's "now past due" reaches us before the failed invoice does.
        $this->webhook('evt_now_past_due', 'customer.subscription.updated', $this->stripeSubscription('past_due'), ['status' => 'active'])->assertOk();

        $graceEnds = $this->plan()->billing_grace_ends_at;
        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $graceEnds);
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());

        // Then the failed invoice, and Stripe's retries two and four days later: nothing moves.
        $this->travel(10)->minutes();
        $this->paymentFailed('evt_failed')->assertOk();
        $this->travelTo(Carbon::parse('2026-10-16 11:00:00'));
        $this->paymentFailed('evt_failed_again')->assertOk();
        $this->travelTo(Carbon::parse('2026-10-18 11:00:00'));
        $this->sync();
        $this->paymentFailed('evt_failed_third')->assertOk();

        $this->assertEquals($graceEnds, $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame([], $this->sentSubjects(), 'The member is warned once.');
    }

    public function test_the_grace_starts_once_when_the_failed_invoice_comes_first(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');

        $this->paymentFailed('evt_failed')->assertOk();
        $graceEnds = $this->plan()->billing_grace_ends_at;
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());

        $this->travel(5)->minutes();
        $this->webhook('evt_now_past_due', 'customer.subscription.updated', $this->stripeSubscription('past_due'), ['status' => 'active'])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-17 11:05:00'));
        $this->paymentFailed('evt_failed_again')->assertOk();

        $this->assertEquals($graceEnds, $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame([], $this->sentSubjects(), 'The member is warned once.');
    }

    /**
     * Stripe moves a subscription on to its new month when it renews, while it
     * still charges the card (about an hour later), so a billing sync in that
     * hour gives the plan the unpaid month's dates. When the charge fails, the
     * plan goes back to the month that was paid for, with its usage.
     */
    public function test_dates_a_sync_took_from_the_unpaid_month_go_back_when_the_failed_invoice_arrives(): void
    {
        $this->renewalUnderWay();

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();

        $this->assertKeptOnThePaidMonth(4000);
        // The period the failed invoice looks back on was in the event itself.
        $this->assertFalse($this->stripe->sent('get', '/v1/invoices/in_renewal'));
    }

    public function test_dates_a_sync_took_from_the_unpaid_month_go_back_when_a_sync_sees_the_missed_payment_first(): void
    {
        $this->renewalUnderWay();

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->sync();

        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertKeptOnThePaidMonth(4000);
        // Read from the failed renewal invoice, once.
        $this->assertCount(1, $this->paramsSent('get', '/v1/invoices/in_renewal', 'expand'));

        // The failed invoice that comes after changes nothing.
        $this->paymentFailed()->assertOk();
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());
    }

    public function test_a_sync_that_read_stripe_just_before_the_charge_failed_never_undoes_the_grace(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        // The sync reads Stripe a moment before the charge fails: still active, in the new month...
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'data' => [$this->stripeSubscription('active')], 'has_more' => false, 'url' => '/v1/subscriptions']);
        // ...and the failed invoice is handled while it does.
        $this->stripe->meanwhile('/v1/subscriptions', fn () => app(BillingLifecycleService::class)->handlePaymentFailed(
            json_decode((string) json_encode($this->renewalInvoice()))
        ));

        $this->sync();

        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());

        // The next sync reads Stripe as it is now: nothing moves, and no second warning.
        $this->stripeHas('past_due');
        $this->sync();
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_sync_that_read_stripe_just_before_the_member_paid_never_records_a_missed_payment_again(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->sentEmails();

        // The sync reads Stripe (still past due) while the payment goes through and is taken.
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        $this->stripe->meanwhile('/v1/subscriptions', function (): void {
            app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', Carbon::parse(self::NEXT_RENEWAL), Carbon::parse(self::RENEWS_AT));
            app(RenewalGrace::class)->settle($this->member);
        });

        $this->sync();

        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertEquals(Carbon::parse(self::NEXT_RENEWAL), $plan->current_period_end);
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
        $this->assertSame([], $this->sentSubjects(), 'A member who has just paid is never told the payment failed.');
    }

    public function test_a_sync_that_read_past_due_before_the_first_failure_was_even_recorded_never_warns_a_member_who_paid(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        // The sync reads Stripe (past due) while the member pays straight away, before any
        // failed-payment event was handled: the payment's own sync takes the month just paid for.
        $this->stripe->meanwhile('/v1/subscriptions', function (): void {
            app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', Carbon::parse(self::NEXT_RENEWAL), Carbon::parse(self::RENEWS_AT));
        });

        $this->sync();

        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $plan->current_period_start);
        $this->assertEquals(Carbon::parse(self::NEXT_RENEWAL), $plan->current_period_end);
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
        $this->assertSame([], $this->sentSubjects(), 'A member who has just paid is never told the payment failed.');
    }

    public function test_a_grace_whose_paid_month_cannot_be_told_pauses_at_once_instead_of_giving_unpaid_credits(): void
    {
        $this->renewalUnderWay();

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        // An invoice that does not look back on the month before the unpaid one, and no paid invoice to tell it.
        $this->stripe->answer('get', '/v1/invoices/in_renewal', ['period_start' => Carbon::parse('2026-10-14 10:00:00')->getTimestamp()] + $this->renewalInvoice());
        $this->stripe->answer('get', '/v1/invoices', ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices']);
        $this->sync();

        $this->assertSame('past_due', $this->plan()->billing_payment_state);
        $this->assertNull($this->plan()->billing_grace_ends_at);
        $this->assertSame('free', $this->activePlanSlug());
    }

    public function test_the_last_paid_invoice_tells_the_month_paid_for_when_the_failed_one_cannot(): void
    {
        $this->renewalUnderWay();

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->stripe->answer('get', '/v1/invoices/in_renewal', ['period_start' => Carbon::parse('2026-10-14 10:00:00')->getTimestamp()] + $this->renewalInvoice());
        // The renewal paid in September, for the month from 14 September.
        $this->stripe->answer('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices', 'data' => [[
            'id' => 'in_september', 'object' => 'invoice', 'customer' => 'cus_grace', 'status' => 'paid',
            'billing_reason' => 'subscription_cycle', 'amount_paid' => 900, 'currency' => 'usd',
            'lines' => ['object' => 'list', 'data' => [[
                'id' => 'il_september', 'object' => 'line_item',
                'period' => ['start' => Carbon::parse(self::PAID_FROM)->getTimestamp(), 'end' => Carbon::parse(self::RENEWS_AT)->getTimestamp()],
            ]]],
        ]]]);

        $this->sync();

        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
    }

    public function test_a_billing_sync_that_cannot_read_the_failed_invoice_records_nothing_yet(): void
    {
        $this->renewalUnderWay();

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->stripe->answer('get', '/v1/invoices/in_renewal', ['error' => ['type' => 'api_error', 'message' => 'Something went wrong on our end.']], 500);
        $this->sync();

        // Nothing was decided on half the facts: the next event or sync does it.
        $this->assertSame('ok', $this->plan()->billing_payment_state);
        $this->assertNull($this->plan()->billing_grace_ends_at);
        $this->assertSame([], $this->sentSubjects());

        $this->stripe->answer('get', '/v1/invoices/in_renewal', $this->renewalInvoice());
        $this->sync();
        $this->assertKeptOnThePaidMonth(4000);
    }

    public function test_paying_during_the_grace_starts_the_new_month_with_a_fresh_allowance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->spend(500);

        // The card is fixed and the open invoice paid: Stripe is active again, in the month from the renewal date.
        $this->stripeHas('active');
        $this->webhook('evt_paid', 'invoice.paid', ['status' => 'paid', 'amount_paid' => 900, 'amount_due' => 900] + $this->renewalInvoice())->assertOk();

        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertNull($plan->billing_failed_notice_sent_at);
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $plan->current_period_start);
        $this->assertEquals(Carbon::parse(self::NEXT_RENEWAL), $plan->current_period_end);
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
        $this->assertSame(0, $this->used());
        // The month paid before keeps its own record.
        $this->assertSame(4500, (int) UsageCounter::query()->where('user_id', $this->member->id)->where('period_ym', self::PAID_KEY)->value('total_chars'));

        // A later failure is a new missed payment, with its own warning.
        $this->sentEmails();
        $this->travelTo(Carbon::parse('2026-11-14 11:05:00'));
        $this->stripeHas('past_due', self::NEXT_RENEWAL, '2026-12-14 10:00:00');
        $this->paymentFailed('evt_failed_november', ['period_start' => Carbon::parse(self::RENEWS_AT)->getTimestamp(), 'period_end' => Carbon::parse(self::NEXT_RENEWAL)->getTimestamp()])->assertOk();
        $this->assertEquals(Carbon::parse('2026-11-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
    }

    public function test_paying_during_the_grace_takes_the_new_month_even_when_the_paid_invoice_comes_before_stripes_update(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();

        // Paid, but the billing sync after the payment cannot reach Stripe: the plan stays on.
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        $this->stripe->unreachable = true;
        $this->webhook('evt_paid', 'invoice.paid', ['status' => 'paid', 'amount_paid' => 900] + $this->renewalInvoice());
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertSame(self::PAID_KEY, $this->creditKey());

        // The next sync takes the month just paid for.
        $this->stripe->unreachable = false;
        $this->stripeHas('active');
        $this->sync();
        $this->assertSame('ok', $this->plan()->billing_payment_state);
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
    }

    public function test_when_the_grace_runs_out_unpaid_the_member_is_on_free_and_the_subscription_is_cancelled(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->sentEmails();

        // The moment the grace ends, before the hourly job runs: Free, with the free plan's credits.
        $this->travelTo(Carbon::parse('2026-10-21 11:06:00'));
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertSame(VoiceTier::STANDARD, $this->tier());
        $this->assertSame('2026-10', $this->creditKey());

        // Signing in or a billing sync meanwhile leaves the plan for the job to cancel.
        app(PlanAccessService::class)->ensureFreePlanForUser($this->member->fresh());
        $this->sync();
        $this->assertSame((int) $this->starter->id, (int) $this->plan()->plan_id);
        $this->assertSame('free', $this->activePlanSlug());

        $this->stripe->answer('delete', '/v1/subscriptions/sub_grace', ['id' => 'sub_grace', 'object' => 'subscription', 'status' => 'canceled']);
        $this->stripeHasTheOpenRenewal();
        $this->assertSame(1, $this->runGraceJob());

        $this->assertTrue($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        // Cancelled without proration: the month it cut short was never paid, so it leaves no credit behind.
        $this->assertSame(['false'], $this->paramsSent('delete', '/v1/subscriptions/sub_grace', 'prorate'));
        // And its renewal can no longer be paid for a month the member does not get.
        $this->assertSame(1, $this->timesSent('post', '/v1/invoices/in_renewal/void'));
        $plan = $this->plan();
        $this->assertSame((int) $this->free->id, (int) $plan->plan_id);
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNotNull($plan->billing_downgraded_at);
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertSame(['Your subscription was canceled'], $this->sentSubjects());
        $this->assertStringContainsString('Your account is on the free plan now.', $this->lastBody());

        // Done once: the next run finds nothing.
        $this->assertSame(0, $this->runGraceJob());
    }

    public function test_staging_never_cancels_or_emails_but_its_copy_of_the_member_ends_on_free_too(): void
    {
        config(['listen.live_actions' => false]);
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');

        // Staging shows the grace as the live site does.
        $this->paymentFailed()->assertOk();
        $this->assertKeptOnThePaidMonth(4000);

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $this->assertSame(0, $this->runGraceJob());

        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
        $this->assertSame([], $this->sentSubjects());
        $plan = $this->plan();
        $this->assertSame((int) $this->free->id, (int) $plan->plan_id);
        $this->assertSame('free', $this->activePlanSlug());
        // Stripe still has the payment missing: left for the live site to cancel.
        $this->assertSame('past_due', $plan->billing_payment_state);
        $this->assertNull($plan->billing_downgraded_at);

        // A billing sync there keeps it on Free and starts no second grace.
        $this->sync();
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertSame(0, $this->runGraceJob());
        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
    }

    public function test_a_free_trials_failed_first_charge_pauses_the_paid_features_at_once(): void
    {
        [$member, $trial] = $this->trialEndingAt(self::RENEWS_AT);

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHasTrial('past_due');
        $this->webhook('evt_trial_charge_failed', 'invoice.payment_failed', $this->trialInvoice())->assertOk();

        $plan = Subscription::query()->where('user_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('past_due', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $member->id)?->slug);
        $this->assertSame(BillingTrial::STATUS_CHARGE_FAILED, $trial->fresh()->status);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());
        $this->assertStringContainsString('We could not take your latest payment, so your paid features are paused.', $this->lastBody());

        // A billing sync keeps it so, and Stripe's retry warns no one again.
        $this->syncMember($member);
        $this->webhook('evt_trial_charge_failed_again', 'invoice.payment_failed', $this->trialInvoice())->assertOk();
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $member->id)?->slug);
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_free_trials_failed_first_charge_seen_by_a_sync_first_pauses_at_once_too(): void
    {
        [$member] = $this->trialEndingAt(self::RENEWS_AT);

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHasTrial('past_due');
        $this->syncMember($member);

        $plan = Subscription::query()->where('user_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('past_due', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $member->id)?->slug);
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());

        $this->webhook('evt_trial_charge_failed', 'invoice.payment_failed', $this->trialInvoice())->assertOk();
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_stripes_own_trial_is_a_first_charge_too_without_a_trial_record(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        // A trial set up in Stripe itself: it ended where the unpaid month starts.
        $this->stripeHas('past_due', self::RENEWS_AT, self::NEXT_RENEWAL, ['trial_start' => Carbon::parse(self::PAID_FROM)->getTimestamp(), 'trial_end' => Carbon::parse(self::RENEWS_AT)->getTimestamp()]);

        $this->paymentFailed()->assertOk();

        $this->assertNull($this->plan()->billing_grace_ends_at);
        $this->assertSame('free', $this->activePlanSlug());
    }

    public function test_a_member_who_paid_after_their_trial_gets_the_grace_at_a_later_renewal(): void
    {
        BillingTrial::query()->create([
            'user_id' => $this->member->id, 'plan_id' => $this->starter->id, 'status' => BillingTrial::STATUS_PAID,
            'stripe_customer_id' => 'cus_grace', 'stripe_subscription_id' => 'sub_grace', 'amount_cents' => 900, 'amount_paid_cents' => 900,
            'started_at' => Carbon::parse('2026-08-07 10:00:00'), 'trial_ends_at' => Carbon::parse('2026-08-14 10:00:00'), 'paid_at' => Carbon::parse('2026-08-14 11:00:00'),
        ]);
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due', self::RENEWS_AT, self::NEXT_RENEWAL, ['trial_end' => Carbon::parse('2026-08-14 10:00:00')->getTimestamp()]);

        $this->paymentFailed()->assertOk();

        $this->assertEquals(Carbon::parse('2026-10-21 11:05:00'), $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);
    }

    public function test_a_declined_plan_change_or_first_payment_starts_no_grace_even_through_a_sync(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        // A declined upgrade is voided: the subscription is still active, in the month paid for.
        $this->stripeHas('active', self::PAID_FROM, self::RENEWS_AT);
        $this->paymentFailed('evt_upgrade_declined', ['billing_reason' => 'subscription_update'])->assertOk();
        $this->sync();
        $this->assertNoGrace();

        // A declined first payment of a new subscription leaves it incomplete.
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_new',
            'stripe_status' => 'incomplete', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        $this->stripe->subscription('sub_new', 'incomplete', 'cus_grace', 'price_starter_m');
        $this->webhook('evt_first_declined', 'invoice.payment_failed', [
            'id' => 'in_first', 'object' => 'invoice', 'customer' => 'cus_grace', 'billing_reason' => 'subscription_create',
            'subscription' => 'sub_new', 'amount_due' => 900,
        ])->assertOk();
        $this->sync();
        $this->assertNoGrace();
    }

    public function test_a_failed_invoice_that_is_not_a_renewal_leaves_the_plan_as_it_is_through_a_sync(): void
    {
        // In the month paid for, a plan change made in Stripe charged the difference at once and
        // the card was declined: Stripe says past due, with that change's invoice open.
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        $this->stripeHas('past_due', self::PAID_FROM, self::RENEWS_AT, ['latest_invoice' => 'in_change']);
        $this->stripe->answer('get', '/v1/invoices/in_change', [
            'id' => 'in_change', 'object' => 'invoice', 'customer' => 'cus_grace', 'currency' => 'usd', 'status' => 'open',
            'billing_reason' => 'subscription_update', 'amount_due' => 450, 'amount_paid' => 0,
            'period_start' => Carbon::parse('2026-10-05 11:55:00')->getTimestamp(), 'period_end' => Carbon::parse('2026-10-05 11:55:00')->getTimestamp(),
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_grace']],
        ]);
        $this->stripe->answer('get', '/v1/invoices', ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices']);

        $this->sync();
        $this->paymentFailed('evt_change_declined', ['id' => 'in_change', 'billing_reason' => 'subscription_update'])->assertOk();
        $this->sync();

        // As the failed-payment webhook does: no grace, no warning, and the plan, its dates and its credits as they were.
        $plan = $this->plan();
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertSame([], $this->sentSubjects());
        $this->assertKeptOnThePaidMonth(4000);
    }

    public function test_a_plan_paid_by_hand_is_never_touched(): void
    {
        $this->plan()->forceFill(['billing_payment_state' => 'manual_override'])->save();
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');

        $this->paymentFailed()->assertOk();
        $this->sync();
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
        $this->assertSame(0, $this->runGraceJob());

        $plan = $this->plan();
        $this->assertSame('manual_override', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $plan->current_period_end);
        $this->assertSame([], $this->sentSubjects());
        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'));
    }

    public function test_the_manage_subscription_page_says_until_when_the_plan_stays_on(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.payment_grace_notice', ['plan' => 'Starter', 'date' => '21 Oct 2026']))
            ->assertSee(__('ui.manage_subscription.status_past_due'))
            ->assertDontSee(__('ui.manage_subscription.status_expired'))
            ->assertDontSee(__('ui.manage_subscription.manual_expired_notice', ['date' => '14 Oct 2026']));

        app()->setLocale('ar');
        $arabicDate = Carbon::parse('2026-10-21 11:05:00')->locale('ar')->translatedFormat('j M Y');
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.payment_grace_notice', [
                'plan' => $this->starter->getLocalizedName('ar') ?: 'Starter',
                'date' => $arabicDate,
            ], 'ar'));

        // Paid: no notice any more.
        app()->setLocale('en');
        $this->stripeHas('active');
        $this->sync();
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertDontSee('id="paymentGraceNotice"', false);
    }

    public function test_checkout_during_the_grace_never_replaces_the_subscription_and_sends_the_member_to_pay_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->sync();
        $graceEnds = $this->plan()->billing_grace_ends_at;
        // What checkout would use to replace the subscription: a new card, and the open renewal invoice to void.
        $this->stripe->answer('get', '/v1/payment_methods/pm_new', ['id' => 'pm_new', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_grace', 'card' => ['brand' => 'visa', 'last4' => '4242']]);
        $this->stripe->answer('delete', '/v1/subscriptions/sub_grace', ['id' => 'sub_grace', 'object' => 'subscription', 'status' => 'canceled']);
        $this->stripe->answer('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices', 'data' => [$this->renewalInvoice()]]);
        $this->stripe->answer('post', '/v1/invoices/in_renewal/void', ['status' => 'void'] + $this->renewalInvoice());
        $this->stripe->requests = [];

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $notice = __('ui.billing.pay_overdue_first', ['date' => '21 Oct 2026']);
        // The checkout page says so before any card is typed in.
        $this->actingAs($this->member)->getLocalizedRoute('billing.page')->assertOk()->assertSee($notice);

        $response = $this->actingAs($this->member)->postJsonLocalizedRoute('billing.subscribe', [
            'plan' => 'starter', 'billing_cycle' => 'monthly', 'payment_method' => 'pm_new', 'save_card' => false,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('payment_overdue', true)
            ->assertJsonPath('message', $notice);
        $this->assertStringEndsWith('/manage-subscription', (string) $response->json('manage_url'));
        // Nothing was cancelled, voided or charged: the subscription and its open invoice are as they were.
        $this->assertSame([], array_values(array_filter($this->stripe->requests, fn (array $request): bool => $request['method'] !== 'get')));
        $this->assertSame('past_due', BillingSubscription::query()->where('stripe_id', 'sub_grace')->value('stripe_status'));
        $this->assertEquals($graceEnds, $this->plan()->billing_grace_ends_at);
        $this->assertKeptOnThePaidMonth(4000);

        // In Arabic too.
        app()->setLocale('ar');
        $this->actingAs($this->member)->getLocalizedRoute('billing.page')->assertOk()->assertSee(__('ui.billing.pay_overdue_first', [
            'date' => Carbon::parse('2026-10-21 11:05:00')->locale('ar')->translatedFormat('j M Y'),
        ], 'ar'));
    }

    public function test_newsletters_count_a_member_in_their_grace_days_as_paid(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->assertSame(['mona@example.test'], $this->newsletterAudience(Newsletter::AUDIENCE_PAID));
        $this->assertNotContains('mona@example.test', $this->newsletterAudience(Newsletter::AUDIENCE_FREE));

        // Once the grace ran out unpaid, they are on Free.
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $this->assertSame([], $this->newsletterAudience(Newsletter::AUDIENCE_PAID));
    }

    public function test_with_calendar_month_credits_a_grace_running_into_the_next_month_gets_no_fresh_allowance(): void
    {
        // Credits by calendar month for everyone (the switch off).
        SiteSetting::query()->where('key', CreditPeriod::SWITCH_SETTING)->delete();
        Cache::forget('site_setting.'.CreditPeriod::SWITCH_SETTING);
        // The plan renews on 28 October.
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', Carbon::parse('2026-10-28 10:00:00'), Carbon::parse('2026-09-28 10:00:00'));
        $this->spend(2500);
        $this->assertSame('2026-10', $this->creditKey());

        $this->travelTo(Carbon::parse('2026-10-28 11:05:00'));
        $this->stripeHas('past_due', '2026-10-28 10:00:00', '2026-11-28 10:00:00');
        $this->paymentFailed('evt_failed', [
            'period_start' => Carbon::parse('2026-09-28 10:00:00')->getTimestamp(),
            'period_end' => Carbon::parse('2026-10-28 10:00:00')->getTimestamp(),
        ])->assertOk();

        // The 1st of November comes during the grace: still October's credits.
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
        $this->sync();
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertSame('2026-10', $this->creditKey());
        $this->assertSame(2500, $this->used());

        // Paid: November's allowance.
        $this->stripeHas('active', '2026-10-28 10:00:00', '2026-11-28 10:00:00');
        $this->sync();
        $this->assertSame('2026-11', $this->creditKey());
        $this->assertSame(0, $this->used());
    }

    public function test_a_yearly_plan_keeps_the_last_credit_month_of_the_year_paid_for(): void
    {
        $this->starter->update(['stripe_price_yearly' => 'price_starter_y']);
        // A year from 14 October 2025; its last credit month is the one from 14 September.
        app(PlanAccessService::class)->assignPlan($this->member, $this->starter, 'active', Carbon::parse(self::RENEWS_AT), Carbon::parse('2025-10-14 10:00:00'));
        $this->assertSame(self::PAID_KEY, $this->creditKey());
        $this->assertSame(4000, $this->used());

        // Stripe starts the next year and a sync takes its dates; the charge then fails.
        $this->travelTo(Carbon::parse('2026-10-14 10:05:00'));
        $this->stripeHas('active', self::RENEWS_AT, '2027-10-14 10:00:00', [], 'price_starter_y');
        $this->sync();
        $this->assertSame('2026-10-14 10:00', $this->creditKey());

        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due', self::RENEWS_AT, '2027-10-14 10:00:00', [], 'price_starter_y');
        $this->paymentFailed('evt_failed', ['period_start' => Carbon::parse('2025-10-14 10:00:00')->getTimestamp()])->assertOk();

        $plan = $this->plan();
        $this->assertEquals(Carbon::parse('2025-10-14 10:00:00'), $plan->current_period_start);
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $plan->current_period_end);
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertSame(self::PAID_KEY, $this->creditKey());
        $this->assertSame(4000, $this->used());

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00'));
        $this->sync();
        $this->assertSame(self::PAID_KEY, $this->creditKey());
    }

    public function test_stripe_ending_the_subscription_during_the_grace_keeps_the_plan_until_the_date_the_member_was_told(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->assertSame(['Payment failed - action required'], $this->sentSubjects());
        $this->assertStringContainsString('Your plan stays on until Oct 21, 2026', $this->lastBody());

        // Day 3 of the 7 grace days: Stripe's own retry settings gave up and it cancelled the subscription.
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00'));
        $this->stripeEndedIt();

        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame([], $this->sentSubjects(), 'Told when the plan ends, not before.');

        // Billing syncs and the grace job meanwhile change nothing.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->sync();
        $this->assertKeptOnThePaidMonth(4000);
        $this->assertSame(0, $this->runGraceJob());

        // On the date told: Free, and one email that says so.
        $this->travelTo(Carbon::parse('2026-10-21 11:06:00'));
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertSame(1, $this->runGraceJob());

        $this->assertFalse($this->stripe->sent('delete', '/v1/subscriptions/sub_grace'), 'Nothing was left to cancel.');
        $plan = $this->plan();
        $this->assertSame((int) $this->free->id, (int) $plan->plan_id);
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNotNull($plan->billing_downgraded_at);
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertSame(['Your subscription was canceled'], $this->sentSubjects());
        $this->assertStringContainsString('Your account is on the free plan now.', $this->lastBody());
        $this->assertStringContainsString('- Grace Period Ended: Oct 21, 2026', $this->lastBody());

        // Done once.
        $this->assertSame(0, $this->runGraceJob());
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_once_stripe_has_ended_it_the_page_says_the_plan_stays_on_and_checkout_is_open_again(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00'));
        $this->stripeEndedIt();

        // A card update would pay nothing now: the page says the plan stays on, then ends.
        $this->travelTo(Carbon::parse('2026-10-18 09:00:00'));
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.payment_grace_ended_notice', ['plan' => 'Starter', 'date' => '21 Oct 2026']))
            ->assertDontSee(__('ui.manage_subscription.payment_grace_notice', ['plan' => 'Starter', 'date' => '21 Oct 2026']))
            ->assertDontSee('id="cancelBtn"', false);
        // Nothing is left to pay first, so checkout no longer sends the member to pay it.
        $this->actingAs($this->member)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertDontSee(__('ui.billing.pay_overdue_first', ['date' => '21 Oct 2026']));

        app()->setLocale('ar');
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.payment_grace_ended_notice', [
                'plan' => $this->starter->getLocalizedName('ar') ?: 'Starter',
                'date' => Carbon::parse('2026-10-21 11:05:00')->locale('ar')->translatedFormat('j M Y'),
            ], 'ar'));
    }

    public function test_a_member_can_cancel_during_the_grace_and_the_unpaid_plan_ends_at_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->sentEmails();

        // The page offers it and says what it does, before and when it is pressed.
        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee('id="cancelBtn"', false)
            ->assertSee(__('ui.manage_subscription.cancel_unpaid_note'))
            ->assertSee('data-confirm="'.e(__('ui.manage_subscription.confirm_cancel_unpaid_prompt')).'"', false);
        app()->setLocale('ar');
        $this->actingAs($this->member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.cancel_unpaid_note', [], 'ar'))
            ->assertSee(__('ui.manage_subscription.confirm_cancel_unpaid_prompt', [], 'ar'));
        app()->setLocale('en');

        $this->stripe->answer('delete', '/v1/subscriptions/sub_grace', ['id' => 'sub_grace', 'object' => 'subscription', 'status' => 'canceled']);
        $this->stripeHasTheOpenRenewal();
        $this->actingAs($this->member)->postJsonLocalizedRoute('billing.cancel')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ended', true)
            ->assertJsonPath('message', __('ui.backend.billing_canceled_unpaid'));

        // Ended in Stripe now, without proration, and the unpaid renewal voided: once each.
        $this->assertSame(['false'], $this->paramsSent('delete', '/v1/subscriptions/sub_grace', 'prorate'));
        $this->assertSame(1, $this->timesSent('post', '/v1/invoices/in_renewal/void'));
        // The new month was never paid: on Free now.
        $plan = $this->plan();
        $this->assertSame((int) $this->free->id, (int) $plan->plan_id);
        $this->assertSame('ok', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertSame('free', $this->activePlanSlug());
        $this->assertSame(['Your subscription was canceled'], $this->sentSubjects());
        $this->assertStringContainsString('You canceled your subscription while its latest payment was still unpaid', $this->lastBody());
        $this->assertStringNotContainsString('Grace Period Ended', $this->lastBody());

        // Stripe's word that it ended sends nothing more, and the grace job finds nothing left.
        $ended = ['ended_at' => now()->getTimestamp(), 'canceled_at' => now()->getTimestamp()];
        $this->stripeHas('canceled', extra: $ended);
        $this->webhook('evt_sub_deleted', 'customer.subscription.deleted', $ended + $this->stripeSubscription('canceled'))->assertOk();
        $this->assertSame([], $this->sentSubjects());
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $this->assertSame(0, $this->runGraceJob());
        $this->assertSame(1, $this->timesSent('delete', '/v1/subscriptions/sub_grace'));
    }

    public function test_on_staging_cancelling_during_the_grace_never_writes_to_stripe_or_emails(): void
    {
        config(['listen.live_actions' => false]);
        $this->travelTo(Carbon::parse('2026-10-14 11:05:00'));
        $this->stripeHas('past_due');
        $this->paymentFailed()->assertOk();
        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->stripe->requests = [];

        $this->actingAs($this->member)->postJsonLocalizedRoute('billing.cancel')
            ->assertOk()
            ->assertJsonPath('ended', true);

        $this->assertSame([], array_values(array_filter($this->stripe->requests, fn (array $request): bool => $request['method'] !== 'get')));
        $this->assertSame([], $this->sentSubjects());
        // Its own copy of the member ends on Free, as on the live site.
        $this->assertSame('free', $this->activePlanSlug());
    }

    /** Stripe cancels the subscription now, and says so (customer.subscription.deleted). */
    private function stripeEndedIt(): void
    {
        $ended = ['ended_at' => now()->getTimestamp(), 'canceled_at' => now()->getTimestamp()];
        $this->stripeHas('canceled', extra: $ended);
        $this->webhook('evt_sub_deleted', 'customer.subscription.deleted', $ended + $this->stripeSubscription('canceled'))->assertOk();
    }

    /** The unpaid renewal is the subscription's open invoice, and Stripe can void it. */
    private function stripeHasTheOpenRenewal(): void
    {
        $this->stripe->answer('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices', 'data' => [$this->renewalInvoice()]]);
        $this->stripe->answer('post', '/v1/invoices/in_renewal/void', ['status' => 'void'] + $this->renewalInvoice());
    }

    private function timesSent(string $method, string $path): int
    {
        return count(array_filter($this->stripe->requests, fn (array $request): bool => $request['method'] === $method && $request['path'] === $path));
    }

    /**
     * The renewal day: Stripe has started the new month (still active, the
     * invoice is charged about an hour later) and a billing sync took its
     * dates, so the member had a fresh, unpaid allowance for a while.
     */
    private function renewalUnderWay(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 10:05:00'));
        $this->stripeHas('active');
        $this->sync();
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $this->plan()->current_period_start);
        $this->assertSame('2026-10-14 10:00', $this->creditKey());
        $this->spend(700);
    }

    private function assertKeptOnThePaidMonth(int $used): void
    {
        $plan = $this->plan();
        $this->assertSame((int) $this->starter->id, (int) $plan->plan_id);
        $this->assertEquals(Carbon::parse(self::PAID_FROM), $plan->current_period_start, 'Never the unpaid month\'s dates.');
        $this->assertEquals(Carbon::parse(self::RENEWS_AT), $plan->current_period_end, 'Never the unpaid month\'s dates.');
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertSame(VoiceTier::HD, $this->tier());
        $this->assertSame(500000, (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId((int) $this->member->id)?->monthly_chars);
        $this->assertSame(self::PAID_KEY, $this->creditKey());
        $this->assertSame($used, $this->used());
    }

    private function assertNoGrace(): void
    {
        $plan = $this->plan();
        $this->assertNotSame('past_due', $plan->billing_payment_state);
        $this->assertNull($plan->billing_grace_ends_at);
        $this->assertSame('starter', $this->activePlanSlug());
        $this->assertNotContains('Payment failed - action required', $this->sentSubjects());
    }

    private function plan(): Subscription
    {
        return Subscription::query()->where('user_id', $this->member->id)->latest('id')->firstOrFail();
    }

    private function activePlanSlug(): ?string
    {
        return app(PlanAccessService::class)->resolveActivePlanForUserId((int) $this->member->id)?->slug;
    }

    private function tier(): string
    {
        $access = app(PlanAccessService::class);

        return $access->resolveTierForPlan($access->resolveAccessPlanForUserId((int) $this->member->id));
    }

    private function creditKey(): string
    {
        return CreditPeriod::current((int) $this->member->id)['key'];
    }

    private function used(): int
    {
        return (int) CreditPeriod::counter((int) $this->member->id)->total_chars;
    }

    /** One generation of $chars credits (15 credits a second of audio). */
    private function spend(int $chars): void
    {
        $counter = CreditPeriod::counter((int) $this->member->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $chars);
        $counter->addSeconds(intdiv($chars, 15));
    }

    private function sync(): void
    {
        $this->syncMember($this->member);
    }

    private function syncMember(User $member): void
    {
        app(BillingSyncService::class)->syncIfStale($member->fresh(), true);
    }

    private function runGraceJob(): int
    {
        return app(BillingLifecycleService::class)->downgradeExpiredGraceSubscriptions();
    }

    /**
     * The value of $param in each request sent to Stripe as $method $path, as sent.
     *
     * @return list<mixed>
     */
    private function paramsSent(string $method, string $path, string $param): array
    {
        return array_values(array_map(
            fn (array $request): mixed => $request['params'][$param] ?? null,
            array_filter($this->stripe->requests, fn (array $request): bool => $request['method'] === $method && $request['path'] === $path)
        ));
    }

    /** @return list<string> */
    private function newsletterAudience(string $audience): array
    {
        $newsletter = Newsletter::query()->create(array_merge(Newsletter::defaultAttributes(), ['audience' => $audience]));
        $emails = [];
        app(NewsletterAudience::class)->each($newsletter, function (array $recipient) use (&$emails): void {
            $emails[] = $recipient['email'];
        });
        sort($emails);

        return $emails;
    }

    /**
     * Stripe's copy of the member's subscription, in the month from $periodStart.
     *
     * @param  array<string, mixed>  $extra
     */
    private function stripeHas(string $status, string $periodStart = self::RENEWS_AT, string $periodEnd = self::NEXT_RENEWAL, array $extra = [], string $price = 'price_starter_m'): void
    {
        $subscription = $extra + $this->stripeSubscription($status, $periodStart, $periodEnd, $price);
        $this->stripe->answer('get', '/v1/customers/cus_grace', ['id' => 'cus_grace', 'object' => 'customer', 'default_source' => null, 'invoice_settings' => ['default_payment_method' => null]]);
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'data' => [$subscription], 'has_more' => false, 'url' => '/v1/subscriptions']);
        $this->stripe->answer('get', '/v1/subscriptions/sub_grace', $subscription);
        $this->stripe->answer('get', '/v1/invoices/in_renewal', $this->renewalInvoice());
    }

    /** @return array<string, mixed> */
    private function stripeSubscription(string $status, string $periodStart = self::RENEWS_AT, string $periodEnd = self::NEXT_RENEWAL, string $price = 'price_starter_m'): array
    {
        return [
            'id' => 'sub_grace', 'object' => 'subscription', 'customer' => 'cus_grace', 'status' => $status,
            'created' => Carbon::parse('2026-08-14 10:00:00')->getTimestamp(), 'cancel_at_period_end' => false,
            'cancel_at' => null, 'ended_at' => null, 'trial_end' => null, 'latest_invoice' => 'in_renewal',
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_grace', 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => Carbon::parse($periodStart)->getTimestamp(),
                'current_period_end' => Carbon::parse($periodEnd)->getTimestamp(),
                'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_starter', 'recurring' => ['interval' => $price === 'price_starter_y' ? 'year' : 'month']],
            ]]],
        ];
    }

    /**
     * The renewal invoice Stripe could not charge: for the new month, looking
     * back (period_start, period_end) on the month before it.
     *
     * @return array<string, mixed>
     */
    private function renewalInvoice(): array
    {
        return [
            'id' => 'in_renewal', 'object' => 'invoice', 'customer' => 'cus_grace', 'currency' => 'usd', 'status' => 'open',
            'billing_reason' => 'subscription_cycle', 'amount_due' => 900, 'amount_paid' => 0,
            'period_start' => Carbon::parse(self::PAID_FROM)->getTimestamp(),
            'period_end' => Carbon::parse(self::RENEWS_AT)->getTimestamp(),
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_grace']],
            'lines' => ['object' => 'list', 'data' => [[
                'id' => 'il_renewal', 'object' => 'line_item', 'description' => 'Starter',
                'period' => ['start' => Carbon::parse(self::RENEWS_AT)->getTimestamp(), 'end' => Carbon::parse(self::NEXT_RENEWAL)->getTimestamp()],
            ]]],
        ];
    }

    /** @param  array<string, mixed>  $changes */
    private function paymentFailed(string $eventId = 'evt_renewal_failed', array $changes = [])
    {
        return $this->webhook($eventId, 'invoice.payment_failed', $changes + $this->renewalInvoice());
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>|null  $previous
     */
    private function webhook(string $eventId, string $type, array $object, ?array $previous = null)
    {
        $data = ['object' => $object];
        if ($previous !== null) {
            $data['previous_attributes'] = $previous;
        }

        return $this->postJson('/api/stripe/webhook', ['id' => $eventId, 'type' => $type, 'data' => $data]);
    }

    /**
     * A member in a free Creator trial that ends at $endsAt, never paid.
     *
     * @return array{0: User, 1: BillingTrial}
     */
    private function trialEndingAt(string $endsAt): array
    {
        $creator = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 1500000,
            'monthly_minutes' => 360, 'monthly_requests' => 0, 'max_chars_per_request' => 20000, 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m',
        ]);
        $this->travelTo(Carbon::parse($endsAt)->subDays(7));
        $member = User::factory()->create(['name' => 'Tamer', 'email' => 'tamer@example.test', 'stripe_id' => 'cus_trial', 'subscription_status_checked_at' => now()]);
        $trial = BillingTrial::query()->create([
            'user_id' => $member->id, 'plan_id' => $creator->id, 'quota_plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'status' => BillingTrial::STATUS_TRIALING, 'stripe_customer_id' => 'cus_trial', 'stripe_subscription_id' => 'sub_trial',
            'amount_cents' => 2200, 'started_at' => now(), 'trial_ends_at' => Carbon::parse($endsAt),
        ]);
        app(PlanAccessService::class)->assignPlan($member, $creator, 'active', Carbon::parse($endsAt));
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_trial', 'stripe_status' => 'trialing',
            'stripe_price' => 'price_creator_m', 'quantity' => 1, 'trial_ends_at' => Carbon::parse($endsAt),
        ]);
        $this->sentEmails();

        return [$member, $trial];
    }

    private function stripeHasTrial(string $status): void
    {
        $subscription = [
            'id' => 'sub_trial', 'object' => 'subscription', 'customer' => 'cus_trial', 'status' => $status,
            'created' => Carbon::parse('2026-10-07 10:00:00')->getTimestamp(), 'cancel_at_period_end' => false,
            'cancel_at' => null, 'ended_at' => null, 'latest_invoice' => 'in_trial_charge',
            'trial_start' => Carbon::parse('2026-10-07 10:00:00')->getTimestamp(), 'trial_end' => Carbon::parse(self::RENEWS_AT)->getTimestamp(),
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_trial', 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => Carbon::parse(self::RENEWS_AT)->getTimestamp(),
                'current_period_end' => Carbon::parse(self::NEXT_RENEWAL)->getTimestamp(),
                'price' => ['id' => 'price_creator_m', 'object' => 'price', 'product' => 'prod_creator', 'recurring' => ['interval' => 'month']],
            ]]],
        ];
        $this->stripe->answer('get', '/v1/customers/cus_trial', ['id' => 'cus_trial', 'object' => 'customer', 'default_source' => null, 'invoice_settings' => ['default_payment_method' => null]]);
        $this->stripe->answer('get', '/v1/subscriptions', ['object' => 'list', 'data' => [$subscription], 'has_more' => false, 'url' => '/v1/subscriptions']);
        $this->stripe->answer('get', '/v1/subscriptions/sub_trial', $subscription);
        $this->stripe->answer('get', '/v1/invoices/in_trial_charge', $this->trialInvoice());
    }

    /** @return array<string, mixed> */
    private function trialInvoice(): array
    {
        return [
            'id' => 'in_trial_charge', 'object' => 'invoice', 'customer' => 'cus_trial', 'currency' => 'usd', 'status' => 'open',
            'billing_reason' => 'subscription_cycle', 'amount_due' => 2200, 'amount_paid' => 0,
            'period_start' => Carbon::parse('2026-10-07 10:00:00')->getTimestamp(),
            'period_end' => Carbon::parse(self::RENEWS_AT)->getTimestamp(),
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_trial']],
        ];
    }

    /** @return list<string> The subjects of the emails sent since the last call. */
    private function sentSubjects(): array
    {
        return array_column($this->sentEmails(), 'subject');
    }

    private function lastBody(): string
    {
        return (string) $this->lastBody;
    }

    /** @return list<array{subject: string, body: string}> */
    private function sentEmails(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $sent = $transport->messages()
            ->map(fn ($message) => [
                'subject' => (string) $message->getOriginalMessage()->getSubject(),
                'body' => (string) ($message->getOriginalMessage()->getTextBody() ?? $message->getOriginalMessage()->getHtmlBody()),
            ])
            ->values()
            ->all();
        $transport->flush();
        if ($sent !== []) {
            $this->lastBody = end($sent)['body'];
        }

        return $sent;
    }
}
