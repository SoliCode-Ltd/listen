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
            ->assertJsonMissingPath('requires_action');

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
}

class ScriptedStripeClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,params:array}> */
    public array $requests = [];

    public bool $pendingAfterUpdate = false;

    public string $intentStatus = 'succeeded';

    public bool $failSubscriptionCreate = false;

    public array $setupIntentMetadata = [];

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
                    'id' => 'in_edge', 'object' => 'invoice',
                    'payments' => ['object' => 'list', 'data' => [['object' => 'invoice_payment', 'payment' => ['payment_intent' => 'pi_edge']]]],
                ],
            ]);
        }

        if ($path === '/v1/payment_intents/pi_edge') {
            return $this->json(['id' => 'pi_edge', 'object' => 'payment_intent', 'status' => $this->intentStatus, 'client_secret' => 'pi_edge_secret']);
        }

        if ($path === '/v1/setup_intents/seti_edge') {
            return $this->json(['id' => 'seti_edge', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_edge', 'payment_method' => 'pm_edge', 'metadata' => $this->setupIntentMetadata]);
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
            return [json_encode(['error' => ['type' => 'card_error', 'message' => 'Your card was declined.']]), 402, []];
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
