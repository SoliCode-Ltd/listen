<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CashierStripeTrialGateway;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class BillingEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    private Plan $creator;

    private ScriptedStripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200,
            'allow_overage' => false, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'allow_overage' => false, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y',
        ]);
        $this->creator = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 1500000,
            'monthly_minutes' => 300, 'monthly_requests' => 3000, 'max_chars_per_request' => 10000,
            'allow_overage' => true, 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m', 'stripe_price_yearly' => 'price_creator_y',
        ]);

        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->andReturnNull();
        $referrals->shouldReceive('isFirstPaidPlanTransition')->andReturnFalse();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->stripe = new ScriptedStripeClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->mock(BillingSyncService::class, fn ($mock) => $mock->shouldReceive('syncIfStale')->andReturnNull());
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();

        parent::tearDown();
    }

    private function userWithSubscription(string $status, string $price, ?\DateTimeInterface $trialEnds = null, ?\DateTimeInterface $endsAt = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_edge'])->save();
        app(PlanAccessService::class)->assignPlan($user, $price === 'price_creator_m' ? $this->creator : $this->starter, 'active', now()->addDays(7));

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_edge',
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'trial_ends_at' => $trialEnds,
            'ends_at' => $endsAt,
        ]);
        $subscription->items()->create([
            'stripe_id' => 'si_edge',
            'stripe_product' => 'prod_x',
            'stripe_price' => $price,
            'quantity' => 1,
        ]);

        return $user;
    }

    private function trialRecord(User $user): BillingTrial
    {
        return BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $this->starter->id,
            'quota_plan_id' => $this->starter->id,
            'billing_cycle' => 'monthly',
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_edge',
            'stripe_subscription_id' => 'sub_edge',
            'amount_cents' => 900,
            'started_at' => now(),
            'trial_ends_at' => now()->addDays(7),
        ]);
    }

    private function subscribe(User $user, array $body)
    {
        return $this->actingAs($user)
            ->withoutMiddleware([
                ValidateCsrfToken::class,
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->postJson($this->localizedRoute('billing.subscribe'), $body + ['billing_cycle' => 'monthly']);
    }

    public function test_a_yearly_trial_keeps_its_cycle_through_the_real_stripe_gateway(): void
    {
        $this->stripe->setupIntentMetadata = ['listen_plan_slug' => 'creator', 'listen_billing_cycle' => 'yearly'];

        $intent = app(CashierStripeTrialGateway::class)->retrieveSetupIntent('seti_edge');

        $this->assertSame('yearly', $intent['metadata']['listen_billing_cycle']);
        $this->assertSame('creator', $intent['metadata']['listen_plan_slug']);
    }

    public function test_a_declined_card_on_pay_now_keeps_the_trial_and_voids_the_invoice(): void
    {
        $user = $this->userWithSubscription('trialing', 'price_starter_m', now()->addDays(7));
        $this->trialRecord($user);
        $this->stripe->pendingAfterUpdate = true;
        $this->stripe->intentStatus = 'requires_payment_method';

        $this->subscribe($user, ['plan' => 'creator', 'end_trial' => true])
            ->assertStatus(422)
            ->assertJsonMissingPath('requires_action')
            // No reason from the bank: a refusal, and the trial goes on.
            ->assertJsonPath('decline', 'refused')
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, "Your bank didn't allow this payment, so nothing was charged.")
                && str_contains($message, 'Your free trial continues until'));

        $update = $this->stripe->last('post', '/v1/subscriptions/sub_edge');
        $this->assertSame('pending_if_incomplete', $update['payment_behavior']);
        $this->assertSame('now', $update['trial_end']);
        $this->assertNotNull($this->stripe->last('post', '/v1/invoices/in_edge/void'), 'The unpaid invoice was not voided.');

        $trial = BillingTrial::query()->where('stripe_subscription_id', 'sub_edge')->firstOrFail();
        $this->assertSame($this->starter->id, (int) $trial->plan_id);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->status);
    }

    public function test_pay_now_that_needs_3d_secure_hands_the_secret_to_the_browser(): void
    {
        $user = $this->userWithSubscription('trialing', 'price_starter_m', now()->addDays(7));
        $this->trialRecord($user);
        $this->stripe->pendingAfterUpdate = true;
        $this->stripe->intentStatus = 'requires_action';

        $this->subscribe($user, ['plan' => 'creator', 'end_trial' => true])
            ->assertStatus(402)
            ->assertJsonPath('requires_action', true)
            ->assertJsonPath('client_secret', 'pi_edge_secret');

        $this->assertNull($this->stripe->last('post', '/v1/invoices/in_edge/void'));
    }

    public function test_choosing_the_same_plan_on_a_canceled_trial_resumes_it_without_charging(): void
    {
        $user = $this->userWithSubscription('trialing', 'price_starter_m', now()->addDays(7), now()->addDays(7));
        $this->trialRecord($user);

        $this->subscribe($user, ['plan' => 'starter'])
            ->assertStatus(409)
            ->assertJsonPath('already_on_trial', true);

        $resume = $this->stripe->last('post', '/v1/subscriptions/sub_edge');
        $this->assertNotNull($resume, 'The canceled trial was not resumed.');
        $this->assertContains($resume['cancel_at_period_end'], [false, 'false']);
        $this->assertArrayNotHasKey('proration_behavior', $resume);
    }

    public function test_a_plan_change_applies_the_discount_with_the_current_api(): void
    {
        $this->creator->forceFill(['discount_percentage' => 20, 'stripe_coupon_id' => 'coupon_edge'])->save();
        $user = $this->userWithSubscription('active', 'price_starter_m');

        $this->subscribe($user, ['plan' => 'creator']);

        $update = $this->stripe->last('post', '/v1/subscriptions/sub_edge');
        $this->assertSame([['coupon' => 'coupon_edge']], $update['discounts']);
        $this->assertArrayNotHasKey('coupon', $update);
    }

    public function test_replacing_a_past_due_subscription_voids_its_open_invoice(): void
    {
        $user = $this->userWithSubscription('past_due', 'price_starter_m');
        $this->stripe->failSubscriptionCreate = true;

        $this->subscribe($user, ['plan' => 'starter', 'payment_method' => 'pm_edge']);

        $this->assertNotNull($this->stripe->last('delete', '/v1/subscriptions/sub_edge'), 'The past due subscription was not canceled.');
        $this->assertNotNull($this->stripe->last('post', '/v1/invoices/in_old/void'), 'Its open invoice could still be paid.');
    }

    public function test_a_new_plan_the_bank_refuses_says_why_and_what_to_do(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_edge'])->save();
        $this->stripe->failSubscriptionCreate = true;

        $cases = [
            'insufficient_funds' => ['no_funds', "Your card doesn't have enough money for this payment"],
            'do_not_honor' => ['refused', "Your bank didn't allow this payment"],
            'expired_card' => ['card_details', "The card details don't match your bank's records"],
            'try_again_later' => ['try_again', "Your bank couldn't confirm the payment just now"],
        ];
        foreach ($cases as $code => [$kind, $start]) {
            $this->stripe->cardError = ['code' => 'card_declined', 'decline_code' => $code];

            $this->subscribe($user, ['plan' => 'starter', 'payment_method' => 'pm_edge'])
                ->assertStatus(422)
                ->assertJsonPath('ok', false)
                ->assertJsonPath('decline', $kind)
                ->assertJsonPath('message', fn (string $message) => str_starts_with($message, $start.', so nothing was charged.'));
        }

        // In Arabic, in the member's own words.
        app()->setLocale('ar');
        $this->stripe->cardError = ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];
        $this->subscribe($user, ['plan' => 'starter', 'payment_method' => 'pm_edge'])
            ->assertJsonPath('decline', 'no_funds')
            ->assertJsonPath('message', __('ui.billing.decline_no_funds', [], 'ar'));
    }

    public function test_a_declined_first_payment_kept_out_of_date_by_cashier_is_looked_up(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_edge'])->save();
        $this->stripe->cardError = ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];

        // Cashier keeps the payment as it was before its confirmation failed: no reason on it.
        $stale = \Stripe\PaymentIntent::constructFrom(['id' => 'pi_edge', 'object' => 'payment_intent', 'customer' => 'cus_edge', 'status' => 'requires_confirmation']);
        $exception = new \Laravel\Cashier\Exceptions\IncompletePayment(new \Laravel\Cashier\Payment($stale), 'declined');

        $kind = (new \ReflectionMethod(\App\Http\Controllers\BillingController::class, 'declineKind'))
            ->invoke(app(\App\Http\Controllers\BillingController::class), $exception);

        $this->assertSame('no_funds', $kind);
        $this->assertNotNull($this->stripe->last('get', '/v1/payment_intents/pi_edge'), 'Stripe was not asked why the card was declined.');
    }

    /** Moving from Starter to Creator: the plan shows as Creator once Stripe has it. */
    private function planFollowsStripe(User $user): void
    {
        $creator = $this->creator;
        $this->mock(BillingSyncService::class, function ($mock) use ($user, $creator) {
            $mock->shouldReceive('syncIfStale')->andReturnUsing(function () use ($user, $creator) {
                if ($this->stripe->last('post', '/v1/subscriptions/sub_edge') !== null && ! $this->stripe->pendingAfterUpdate) {
                    app(PlanAccessService::class)->assignPlan($user, $creator, 'active', now()->addMonth());
                }
            });
        });
    }

    public function test_a_paid_plan_change_charges_the_difference_today_and_applies_only_when_paid(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->planFollowsStripe($user);
        $previewedAt = time() - 60;

        $this->subscribe($user, ['plan' => 'creator', 'proration_date' => $previewedAt])
            ->assertOk()
            ->assertJsonPath('charged', true)
            ->assertJsonPath('plan.slug', 'creator');

        $update = $this->stripe->last('post', '/v1/subscriptions/sub_edge');
        $this->assertSame([['id' => 'si_edge', 'price' => 'price_creator_m']], $update['items']);
        // Charged now, not added to the next renewal, and applied only if the payment goes through.
        $this->assertSame('always_invoice', $update['proration_behavior']);
        $this->assertSame('pending_if_incomplete', $update['payment_behavior']);
        $this->assertSame('now', $update['billing_cycle_anchor']);
        $this->assertSame($previewedAt, (int) $update['proration_date']);
        $this->assertArrayNotHasKey('trial_end', $update);
    }

    public function test_a_declined_paid_plan_change_keeps_the_plan_and_voids_the_invoice(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->stripe->pendingAfterUpdate = true;
        $this->stripe->intentStatus = 'requires_payment_method';

        $this->stripe->cardError = ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];

        $this->subscribe($user, ['plan' => 'creator'])
            ->assertStatus(422)
            ->assertJsonMissingPath('requires_action')
            ->assertJsonPath('decline', 'no_funds')
            ->assertJsonPath('message', "Your card doesn't have enough money for this payment, so nothing was charged. Top up the card and try again, or choose another option below. You're still on Starter.");

        $this->assertNotNull($this->stripe->last('post', '/v1/invoices/in_edge/void'), 'The unpaid plan change could still go through.');
        $this->assertSame('starter', app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id)?->slug);
    }

    public function test_a_paid_plan_change_that_needs_3d_secure_hands_the_secret_to_the_browser(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->stripe->pendingAfterUpdate = true;
        $this->stripe->intentStatus = 'requires_action';

        $this->subscribe($user, ['plan' => 'creator'])
            ->assertStatus(402)
            ->assertJsonPath('requires_action', true)
            ->assertJsonPath('client_secret', 'pi_edge_secret');

        $this->assertNull($this->stripe->last('post', '/v1/invoices/in_edge/void'));
    }

    public function test_a_move_to_a_cheaper_plan_paid_by_its_credit_is_not_counted_as_a_charge(): void
    {
        $user = $this->userWithSubscription('active', 'price_creator_m');
        $this->stripe->amountPaid = 0;
        $starter = $this->starter;
        $this->mock(BillingSyncService::class, fn ($mock) => $mock->shouldReceive('syncIfStale')->andReturnUsing(
            fn () => app(PlanAccessService::class)->assignPlan($user, $starter, 'active', now()->addMonth())
        ));

        $this->subscribe($user, ['plan' => 'starter'])
            ->assertOk()
            ->assertJsonPath('charged', false);
    }

    public function test_only_a_recent_proration_date_inside_the_period_is_used(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');

        foreach ([time() + 600, time() - 7200] as $date) {
            $this->subscribe($user, ['plan' => 'creator', 'proration_date' => $date]);
            $this->assertArrayNotHasKey('proration_date', $this->stripe->last('post', '/v1/subscriptions/sub_edge'));
        }
    }

    private function preview(User $user, string $plan, string $cycle = 'monthly')
    {
        return $this->actingAs($user)
            ->withoutMiddleware([
                ValidateCsrfToken::class,
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->postJson($this->localizedRoute('billing.plan-change.preview'), ['plan' => $plan, 'billing_cycle' => $cycle]);
    }

    public function test_order_details_show_the_new_plan_the_credit_and_the_total_due_today(): void
    {
        \App\Models\SiteSetting::set('pricing_forced_country_code', 'US', 'billing');
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $renews = strtotime('2026-11-18 10:00:00 UTC');
        $this->stripe->preview = [
            'total' => 1750, 'amount_due' => 1750,
            'lines' => ['object' => 'list', 'data' => [
                ['object' => 'line_item', 'amount' => -450, 'period' => ['start' => time(), 'end' => strtotime('2026-11-02 UTC')]],
                ['object' => 'line_item', 'amount' => 2200, 'period' => ['start' => time(), 'end' => $renews]],
            ]],
        ];

        $response = $this->preview($user, 'creator')->assertOk()
            ->assertJsonPath('change', true)
            ->assertJsonPath('lines.0.kind', 'plan')
            ->assertJsonPath('lines.0.label', 'Creator, monthly')
            ->assertJsonPath('lines.1.kind', 'credit')
            ->assertJsonPath('lines.1.label', 'Credit for the unused time on Starter')
            ->assertJsonPath('total_cents', 1750)
            ->assertJsonPath('credit_left', null)
            ->assertJsonPath('renews_on', '18 Nov 2026')
            ->assertJsonPath('lines.0.amount', '$22.00')
            ->assertJsonPath('lines.1.amount', '$4.50')
            ->assertJsonPath('total', '$17.50')
            ->assertJsonPath('charged', null);

        // The same change the payment makes, at the same moment.
        $request = $this->stripe->last('post', '/v1/invoices/create_preview');
        $this->assertSame('sub_edge', $request['subscription']);
        $this->assertSame([['id' => 'si_edge', 'price' => 'price_creator_m']], $request['subscription_details']['items']);
        $this->assertSame('always_invoice', $request['subscription_details']['proration_behavior']);
        $this->assertSame('now', $request['subscription_details']['billing_cycle_anchor']);
        $this->assertSame($response->json('proration_date'), (int) $request['subscription_details']['proration_date']);
        $this->assertNull($this->stripe->last('post', '/v1/subscriptions/sub_edge'), 'A preview changed the subscription.');
    }

    public function test_a_past_payment_reads_in_local_money_with_the_dollars_in_brackets(): void
    {
        \App\Models\SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        \App\Models\SiteSetting::set('pricing_exchange_rate_EGP', '50', 'billing');
        $controller = app(\App\Http\Controllers\BillingController::class);
        $context = app(\App\Services\LocalizedPricingService::class)->resolveCountryContext(request());
        $format = new \ReflectionMethod($controller, 'formatChargedAmount');

        $shown = str_replace(["\u{2066}", "\u{2069}", "\u{a0}"], ['', '', ' '], $format->invoke($controller, 1980, 'USD', $context, 'en'));
        $this->assertSame('EGP 990 ($19.80)', $shown);
    }

    public function test_order_details_are_in_local_money_only(): void
    {
        \App\Models\SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->stripe->preview = [
            'total' => 1750, 'amount_due' => 1750,
            'lines' => ['object' => 'list', 'data' => [
                ['object' => 'line_item', 'amount' => 2200, 'period' => ['start' => time(), 'end' => time() + 86400 * 30]],
                ['object' => 'line_item', 'amount' => -450, 'period' => ['start' => time(), 'end' => time() + 86400 * 30]],
            ]],
        ];

        // The total in the visitor's own money, and no dollar amount beside it.
        $response = $this->preview($user, 'creator')->assertOk()->assertJsonMissingPath('charged');
        $this->assertStringContainsString('EGP', $response->json('total'));
    }

    public function test_order_details_for_a_cheaper_plan_say_the_credit_pays_the_next_renewals(): void
    {
        \App\Models\SiteSetting::set('pricing_forced_country_code', 'US', 'billing');
        $user = $this->userWithSubscription('active', 'price_creator_m');
        $this->stripe->preview = [
            'total' => -1300, 'amount_due' => 0,
            'lines' => ['object' => 'list', 'data' => [
                ['object' => 'line_item', 'amount' => -2200, 'period' => ['start' => time(), 'end' => time() + 86400 * 30]],
                ['object' => 'line_item', 'amount' => 900, 'period' => ['start' => time(), 'end' => time() + 86400 * 30]],
            ]],
        ];

        $this->preview($user, 'starter')->assertOk()
            ->assertJsonPath('total_cents', 0)
            ->assertJsonPath('total', '$0.00')
            ->assertJsonPath('credit_left', '$13.00')
            ->assertJsonPath('charged', null);
    }

    public function test_order_details_say_credits_used_this_month_count_on_the_new_plan(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');

        $page = $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk();
        $this->assertMatchesRegularExpression(
            '#<section class="order-details"[^>]*>.*'.preg_quote(e(__('ui.billing.order_usage_note')), '#').'.*</section>#s',
            $page->getContent()
        );

        app()->setLocale('ar');
        $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk()
            ->assertSee(__('ui.billing.order_usage_note', [], 'ar'));
    }

    public function test_a_running_plan_is_changed_with_the_card_boxes_only(): void
    {
        // A plan change, a resumed plan or a trial's end can ask first: the phone wallet
        // starts a new plan only.
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk()
            ->assertViewHas('walletPay', false)
            ->assertDontSee('id="wallet-button"', false);

        BillingSubscription::query()->where('stripe_id', 'sub_edge')->update(['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(7)]);
        $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk()
            ->assertViewHas('walletPay', false);
    }

    public function test_no_order_details_on_a_trial_or_the_same_plan(): void
    {
        $user = $this->userWithSubscription('active', 'price_starter_m');
        $this->preview($user, 'starter')->assertOk()->assertJsonPath('change', false);

        BillingSubscription::query()->where('stripe_id', 'sub_edge')->update(['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(7)]);
        $this->preview($user, 'creator')->assertOk()->assertJsonPath('change', false);

        $this->assertNull($this->stripe->last('post', '/v1/invoices/create_preview'));
    }
}

class ScriptedStripeClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,params:array}> */
    public array $requests = [];

    public bool $pendingAfterUpdate = false;

    public string $intentStatus = 'succeeded';

    public bool $failSubscriptionCreate = false;

    /** Why the bank refused the card, as Stripe says it (code, decline_code). */
    public array $cardError = [];

    public array $setupIntentMetadata = [];

    /** Subscriptions Stripe lists for the customer. */
    public array $listedSubscriptions = [];

    /** What the plan change invoice took from the card. */
    public int $amountPaid = 1750;

    /** The invoice Stripe previews for a plan change. */
    public array $preview = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params];

        if ($path === '/v1/subscriptions/sub_edge') {
            $updated = $method === 'post' && isset($params['items']) && ! $this->pendingAfterUpdate;
            $price = $updated ? (string) ($params['items'][0]['price'] ?? 'price_creator_m') : 'price_starter_m';

            return $this->json([
                'id' => 'sub_edge', 'object' => 'subscription', 'customer' => 'cus_edge',
                'status' => $updated ? 'active' : 'trialing',
                'cancel_at_period_end' => false,
                'pending_update' => $method === 'post' && $this->pendingAfterUpdate ? ['expires_at' => time() + 3600] : null,
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_edge', 'object' => 'subscription_item', 'quantity' => 1,
                    'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_x', 'recurring' => ['interval' => 'month', 'usage_type' => 'licensed']],
                ]]],
                'latest_invoice' => [
                    'id' => 'in_edge', 'object' => 'invoice', 'amount_paid' => $this->pendingAfterUpdate ? 0 : $this->amountPaid,
                    'payments' => ['object' => 'list', 'data' => [['object' => 'invoice_payment', 'payment' => ['payment_intent' => 'pi_edge']]]],
                ],
            ]);
        }

        if ($path === '/v1/invoices/create_preview' && $method === 'post') {
            return $this->json(['object' => 'invoice', 'currency' => 'usd', 'total_discount_amounts' => []] + $this->preview);
        }

        if ($path === '/v1/payment_intents/pi_edge') {
            return $this->json(['id' => 'pi_edge', 'object' => 'payment_intent', 'status' => $this->intentStatus, 'client_secret' => 'pi_edge_secret']
                + ($this->cardError !== [] ? ['last_payment_error' => ['type' => 'card_error'] + $this->cardError] : []));
        }

        if ($path === '/v1/setup_intents/seti_edge') {
            return $this->json(['id' => 'seti_edge', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_edge', 'payment_method' => 'pm_edge', 'metadata' => $this->setupIntentMetadata]);
        }

        if ($path === '/v1/subscriptions' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => $this->listedSubscriptions, 'has_more' => false, 'url' => '/v1/subscriptions']);
        }

        if ($path === '/v1/invoices' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => [['id' => 'in_old', 'object' => 'invoice', 'status' => 'open']], 'has_more' => false]);
        }

        if ($path === '/v1/customers/cus_edge') {
            return $this->json(['id' => 'cus_edge', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => 'pm_edge']]);
        }

        if ($path === '/v1/payment_methods/pm_edge') {
            return $this->json(['id' => 'pm_edge', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_edge', 'card' => ['brand' => 'visa', 'last4' => '4242']]);
        }

        if ($path === '/v1/subscriptions' && $method === 'post' && $this->failSubscriptionCreate) {
            return [json_encode(['error' => ['type' => 'card_error', 'message' => 'Your card was declined.'] + $this->cardError]), 402, []];
        }

        return $this->json(['id' => 'obj_fake', 'object' => 'unknown']);
    }

    public function last(string $method, string $path): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                return $request['params'];
            }
        }

        return null;
    }

    private function json(array $body): array
    {
        return [json_encode($body), 200, []];
    }
}
