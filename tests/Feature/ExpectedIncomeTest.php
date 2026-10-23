<?php

namespace Tests\Feature;

use App\Filament\Widgets\ExpectedIncome;
use App\Models\User;
use App\Services\Analytics\BusinessStatsService;
use App\Services\Analytics\StripeRecurringRevenue;
use App\Services\BillingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * The income to expect from card subscriptions, read from Stripe: only the
 * ones that renew, at what each really charges after its discount, yearly
 * plans at one twelfth, after the fees Stripe really took. And who counts as
 * paying on the dashboard: card or local payment, never a trial or a plan
 * given free, never a plan whose time ran out.
 */
class ExpectedIncomeTest extends TestCase
{
    use RefreshDatabase;

    private RevenueStripeClient $stripe;

    private int $starter;

    private int $creator;

    private int $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        Cache::flush();
        $this->stripe = new RevenueStripeClient;
        ApiRequestor::setHttpClient($this->stripe);

        $plan = fn (string $slug, string $name, int $cents) => DB::table('plans')->insertGetId([
            'slug' => $slug, 'name' => $name, 'price_cents' => $cents, 'is_active' => true,
            'stripe_price_monthly' => "price_{$slug}_m", 'stripe_price_yearly' => "price_{$slug}_y",
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('plans')->insert(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->starter = $plan('starter', 'Starter', 900);
        $this->creator = $plan('creator', 'Creator', 2200);
        $this->pro = $plan('pro', 'Pro', 4900);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function scriptStripe(): void
    {
        $day = 86400;
        $now = time();
        $item = fn (string $price, int $cents, string $interval, int $periodEnd) => ['object' => 'list', 'data' => [[
            'id' => 'si_'.$price, 'object' => 'subscription_item', 'quantity' => 1, 'current_period_end' => $periodEnd,
            'price' => ['id' => $price, 'object' => 'price', 'currency' => 'usd', 'unit_amount' => $cents, 'product' => 'prod_x',
                'recurring' => ['interval' => $interval, 'interval_count' => 1]],
        ]]];
        $sub = fn (string $id, string $status, array $items, array $extra = []) => array_merge([
            'id' => $id, 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_'.$id,
            'cancel_at_period_end' => false, 'cancel_at' => null, 'pause_collection' => null, 'discounts' => [], 'items' => $items,
        ], $extra);

        User::factory()->create(['is_admin' => true, 'stripe_id' => 'cus_sub_admin']);

        $this->stripe->subscriptions = [
            'active' => [
                // Starter monthly at half price for good (coupon given only by its id): $4.50, due in 10 days.
                $sub('sub_half', 'active', $item('price_starter_m', 900, 'month', $now + 10 * $day), [
                    'discounts' => [['object' => 'discount', 'end' => null, 'source' => ['type' => 'coupon', 'coupon' => 'half_forever']]],
                ]),
                // Creator yearly at $211.20: $17.60 a month, renews in 200 days.
                $sub('sub_yearly', 'active', $item('price_creator_y', 21120, 'year', $now + 200 * $day)),
                // Pro on a 3-month discount that ends before the next bill: the full $49 (older API: discount.coupon).
                $sub('sub_discount_over', 'active', $item('price_pro_m', 4900, 'month', $now + 20 * $day), [
                    'discounts' => [['object' => 'discount', 'end' => $now + 5 * $day,
                        'coupon' => ['id' => 'three_months', 'duration' => 'repeating', 'percent_off' => 50]]],
                ]),
                // Set to cancel at the end of the paid month, and with a cancel date (today's Stripe).
                $sub('sub_leaving', 'active', $item('price_starter_m', 900, 'month', $now + 3 * $day), ['cancel_at_period_end' => true]),
                $sub('sub_leaving_date', 'active', $item('price_creator_m', 2200, 'month', $now + 4 * $day), ['cancel_at' => $now + 4 * $day]),
                // An old price no plan has now, with its name in Stripe.
                $sub('sub_old_price', 'active', ['object' => 'list', 'data' => [[
                    'id' => 'si_old', 'object' => 'subscription_item', 'quantity' => 1, 'current_period_end' => $now + 40 * $day,
                    'price' => ['id' => 'price_retired', 'object' => 'price', 'currency' => 'usd', 'unit_amount' => 400, 'nickname' => 'Launch price',
                        'product' => 'prod_old', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
                ]]]),
                // An admin's own subscription: never counted.
                $sub('sub_admin', 'active', $item('price_pro_m', 4900, 'month', $now + 9 * $day)),
            ],
            'trialing' => [
                // A trial with 20% off its first bill: $17.60 a month when it pays.
                $sub('sub_trial', 'trialing', $item('price_creator_m', 2200, 'month', $now + 6 * $day), [
                    'trial_end' => $now + 6 * $day,
                    'discounts' => [['object' => 'discount', 'end' => null, 'source' => ['type' => 'coupon',
                        'coupon' => ['id' => 'first_bill', 'duration' => 'once', 'percent_off' => 20]]]],
                ]),
                $sub('sub_trial_cancelled', 'trialing', $item('price_creator_m', 2200, 'month', $now + 2 * $day), [
                    'trial_end' => $now + 2 * $day, 'cancel_at_period_end' => true,
                ]),
            ],
            'past_due' => [
                $sub('sub_overdue', 'past_due', $item('price_starter_m', 900, 'month', $now + 1 * $day)),
            ],
        ];
        $this->stripe->coupons = ['half_forever' => ['id' => 'half_forever', 'object' => 'coupon', 'duration' => 'forever', 'percent_off' => 50]];
        // $100 charged in the last 90 days, $5 of it in fees: 5%.
        $this->stripe->transactions = [
            'charge' => [['amount' => 6000, 'fee' => 300], ['amount' => 3000, 'fee' => 150]],
            'payment' => [['amount' => 1000, 'fee' => 50]],
        ];
    }

    public function test_expected_income_counts_only_renewing_subscriptions_at_what_they_charge_after_fees(): void
    {
        $this->scriptStripe();

        $r = app(StripeRecurringRevenue::class)->build();

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        // Renewing: $4.50 + $17.60 + $49.00 + $4.00 (old price) = $75.10 a month before fees.
        $this->assertSame(4, $r['expected']['count']);
        $this->assertSame(['usd' => 7510], $r['expected']['gross_cents']);
        $this->assertSame(0.05, $r['fees']['rate']);
        $this->assertSame(3, $r['fees']['charges']);
        $this->assertSame(['usd' => 7135], $r['expected']['net_cents'], '$75.10 less 5% card fees');

        $this->assertSame(['count' => 2, 'monthly_cents' => ['usd' => 3100]], $r['kinds']['cancelling'], 'both ways of cancelling');
        $this->assertSame(['count' => 1, 'monthly_cents' => ['usd' => 1760]], $r['kinds']['trialing'], 'a "once" discount is on the bill a trial ends with');
        $this->assertSame(1, $r['kinds']['ended_trial']['count']);
        $this->assertSame(['count' => 1, 'monthly_cents' => ['usd' => 900]], $r['kinds']['past_due']);

        // Due in 30 days: Starter $4.50 (day 10) and Pro $49 (day 20); the yearly plan and the old price are later.
        $this->assertSame(2, $r['due_30d']['count']);
        $this->assertSame(['usd' => 5350], $r['due_30d']['cents']);
        $this->assertSame(['usd' => 5083], $r['due_30d']['net_cents']);

        $plans = collect($r['by_plan'])->keyBy(fn ($p) => $p['plan'].' '.$p['cycle']);
        $this->assertSame(1, $plans['Creator yearly']['renewing']);
        $this->assertSame(['usd' => 1760], $plans['Creator yearly']['monthly_cents']);
        $this->assertSame(1, $plans['Starter monthly']['cancelling']);
        $this->assertTrue($plans->has('Launch price monthly'), 'a price no plan knows is listed, not dropped');
        $this->assertSame(1, $plans['Pro monthly']['renewing'], "the admin's own Pro subscription is left out");
    }

    public function test_the_card_shows_the_expected_income_and_what_is_not_counted(): void
    {
        $this->scriptStripe();
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test(ExpectedIncome::class, ['filters' => ['months' => 6]])
            ->assertSee('Expected monthly income (after fees)')
            ->assertSee('$71.35')
            ->assertSee('4 renewing subscriptions · $75.10 before card fees of 5.0%')
            ->assertSee('$50.83')
            ->assertSee('Cancelled, still in paid time')
            ->assertSee('$31.00 a month stops when their paid time ends')
            ->assertSee('1 in trial · 1 overdue')
            ->assertSee('$17.60 a month if the trials pay · $9.00 a month unpaid');
    }

    public function test_the_card_says_when_stripe_cannot_be_read(): void
    {
        $this->stripe->unreachable = true;
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test(ExpectedIncome::class, ['filters' => ['months' => 6]])
            ->assertSee('Stripe could not be read just now')
            ->assertSee('Waiting for Stripe');

        // The MRR beside it falls back to list prices, and says so.
        $revenue = app(BusinessStatsService::class)->build(6)['revenue'];
        $this->assertSame('list_price', $revenue['mrr_source']);
    }

    public function test_mrr_is_what_the_card_subscriptions_charge_including_cancelled_ones_until_they_end(): void
    {
        $this->scriptStripe();

        $revenue = app(BusinessStatsService::class)->build(6)['revenue'];

        $this->assertSame('stripe', $revenue['mrr_source']);
        // Renewing $75.10 + cancelled but still paid $31.00; trials and overdue not counted.
        $this->assertSame(10610, $revenue['mrr_cents']);
        $this->assertSame(6, $revenue['paying_stripe']);
        $this->assertSame((int) round(10610 / 6), $revenue['arpu_cents']);
    }

    public function test_a_subscription_change_from_stripe_refreshes_the_income(): void
    {
        $this->scriptStripe();
        config(['services.stripe.webhook_secret' => '']);
        $this->mock(BillingLifecycleService::class, fn ($mock) => $mock->shouldReceive('syncUserByStripeCustomerId')->andReturnNull());

        app(StripeRecurringRevenue::class)->summary();
        app(BusinessStatsService::class)->snapshot(6);
        $this->assertTrue(Cache::has(StripeRecurringRevenue::CACHE_KEY));

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_cancel', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_half', 'object' => 'subscription', 'customer' => 'cus_sub_half', 'status' => 'active']],
        ])->assertOk();

        $this->assertFalse(Cache::has(StripeRecurringRevenue::CACHE_KEY), 'the income is read again from Stripe');
        $this->assertFalse(Cache::has('business_stats:v3:6'));
    }

    public function test_paying_members_are_paid_by_card_or_locally_never_trials_gifts_or_expired_plans(): void
    {
        $member = function (int $planId, array $plan = []) {
            $user = User::factory()->create();
            DB::table('subscriptions')->insert(array_merge([
                'user_id' => $user->id, 'plan_id' => $planId, 'status' => 'active',
                'current_period_end' => now()->addDays(20), 'created_at' => now(), 'updated_at' => now(),
            ], $plan));

            return $user;
        };

        $card = $member($this->starter);
        DB::table('billing_subscriptions')->insert(['user_id' => $card->id, 'type' => 'default', 'stripe_id' => 'sub_card', 'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'created_at' => now(), 'updated_at' => now()]);

        $local = $member($this->creator);
        DB::table('manual_payment_requests')->insert(['user_id' => $local->id, 'plan_id' => $this->creator, 'billing_cycle' => 'monthly', 'payment_method' => 'instapay', 'status' => 'approved',
            'currency' => 'EGP', 'expected_amount_minor' => 110000, 'created_at' => now(), 'updated_at' => now()]);

        $trial = $member($this->creator);
        DB::table('billing_subscriptions')->insert(['user_id' => $trial->id, 'type' => 'default', 'stripe_id' => 'sub_trial', 'stripe_status' => 'trialing', 'stripe_price' => 'price_creator_m', 'created_at' => now(), 'updated_at' => now()]);

        $member($this->starter);                                                    // given by an admin
        $member($this->pro, ['current_period_end' => now()->subDays(3)]);          // ran out
        $grace = $member($this->starter, ['current_period_end' => now()->subDays(2), // refused renewal, in its grace days
            'billing_payment_state' => 'past_due', 'billing_grace_ends_at' => now()->addDays(4)]);
        DB::table('billing_subscriptions')->insert(['user_id' => $grace->id, 'type' => 'default', 'stripe_id' => 'sub_grace', 'stripe_status' => 'past_due', 'stripe_price' => 'price_starter_m', 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->count(4)->create();                                         // on Free

        $revenue = app(BusinessStatsService::class)->build(6)['revenue'];

        $this->assertSame(['card' => 2, 'manual' => 1, 'trial' => 1, 'granted' => 1], $revenue['plan_holders']);
        $this->assertSame(3, $revenue['members_on_paid_plans'], 'card (one in its grace days) + InstaPay only');
        $this->assertSame(0.3, $revenue['paid_conversion'], '3 of 10 members');
        $this->assertSame(['Free' => 5, 'Starter' => 3, 'Creator' => 2], $revenue['members_by_plan'], 'the plan that ran out counts as Free');
    }

    public function test_ended_subscriptions_are_only_paid_ones(): void
    {
        $user = User::factory()->create();
        $row = fn (string $id, string $status, $ends) => DB::table('billing_subscriptions')->insert([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => $id, 'stripe_status' => $status,
            'stripe_price' => 'price_starter_m', 'ends_at' => $ends, 'created_at' => now()->subDays(40), 'updated_at' => now(),
        ]);
        $row('sub_paid_then_cancelled', 'canceled', now()->subDays(3));
        $row('sub_checkout_failed', 'incomplete_expired', now()->subDays(5));
        $row('sub_trial_never_paid', 'canceled', now()->subDays(6));
        $row('sub_trial_paid_then_cancelled', 'canceled', now()->subDays(7));
        $row('sub_leaving_later', 'active', now()->addDays(9));
        $trial = fn (string $sub, $paidAt) => DB::table('billing_trials')->insert([
            'user_id' => $user->id, 'plan_id' => $this->creator, 'quota_plan_id' => $this->starter, 'status' => $paidAt ? 'paid' : 'canceled',
            'stripe_subscription_id' => $sub, 'amount_cents' => 2200, 'paid_at' => $paidAt, 'started_at' => now()->subDays(20),
            'trial_ends_at' => now()->subDays(13), 'created_at' => now()->subDays(20), 'updated_at' => now(),
        ]);
        $trial('sub_trial_never_paid', null);
        $trial('sub_trial_paid_then_cancelled', now()->subDays(13));

        $s = app(BusinessStatsService::class)->build(3);

        $this->assertSame(2, $s['revenue']['canceled_30d'], 'a failed checkout and a trial that never paid did not end anything paid');
        $this->assertSame(2, array_sum(array_column($s['monthly'], 'stripe_ended')));
        $this->assertSame(2, array_sum(array_column($s['monthly'], 'stripe_started')), 'bought straight away; trials are counted as trials, failed checkouts not at all');
    }
}

/**
 * Stripe for these tests: subscriptions by status, coupons, balance transactions.
 */
class RevenueStripeClient implements ClientInterface
{
    /** @var array<string, list<array>> */
    public array $subscriptions = [];

    /** @var array<string, array> */
    public array $coupons = [];

    /** @var array<string, list<array{amount:int, fee:int}>> */
    public array $transactions = [];

    public bool $unreachable = false;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if ($this->unreachable) {
            throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe (test).');
        }

        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
        $params = array_merge($query, (array) $params);

        if ($path === '/v1/subscriptions') {
            $data = $this->subscriptions[$params['status'] ?? ''] ?? [];

            return $this->json(['object' => 'list', 'data' => $data, 'has_more' => false, 'url' => '/v1/subscriptions']);
        }
        if (str_starts_with($path, '/v1/coupons/')) {
            return $this->json($this->coupons[substr($path, 12)] ?? []);
        }
        if ($path === '/v1/balance_transactions') {
            $data = array_map(fn ($t) => $t + ['object' => 'balance_transaction', 'currency' => 'usd'], $this->transactions[$params['type'] ?? ''] ?? []);

            return $this->json(['object' => 'list', 'data' => $data, 'has_more' => false, 'url' => '/v1/balance_transactions']);
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Not set up: '.$path]]), 404, []];
    }

    private function json(array $body): array
    {
        return [json_encode($body), 200, []];
    }
}
