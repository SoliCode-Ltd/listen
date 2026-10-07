<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class TrialPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    private Plan $creator;

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
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();

        parent::tearDown();
    }

    private function trialingStarterUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_trial'])->save();
        app(PlanAccessService::class)->assignPlan($user, $this->starter, 'active', now()->addDays(7));

        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $this->starter->id,
            'quota_plan_id' => $this->starter->id,
            'billing_cycle' => 'monthly',
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_trial',
            'stripe_subscription_id' => 'sub_trial',
            'amount_cents' => (int) $this->starter->price_cents,
            'started_at' => now(),
            'trial_ends_at' => now()->addDays(7),
        ]);

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_trial',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_starter_m',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(7),
        ]);
        $subscription->items()->create([
            'stripe_id' => 'si_trial',
            'stripe_product' => 'prod_starter',
            'stripe_price' => 'price_starter_m',
            'quantity' => 1,
        ]);

        return $user;
    }

    public function test_paying_now_during_a_trial_ends_the_trial_and_invoices_the_new_plan(): void
    {
        $user = $this->trialingStarterUser();
        $stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($stripe);

        $creator = $this->creator;
        $this->mock(BillingSyncService::class, function ($mock) use ($user, $creator) {
            $mock->shouldReceive('syncIfStale')->andReturnUsing(function () use ($user, $creator) {
                app(PlanAccessService::class)->assignPlan($user, $creator, 'active', now()->addMonth());
            });
        });

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                ValidateCsrfToken::class,
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->postJson($this->localizedRoute('billing.subscribe'), [
                'plan' => 'creator',
                'billing_cycle' => 'monthly',
                'end_trial' => true,
            ]);

        $response->assertOk();

        $update = $stripe->lastRequest('post', '/v1/subscriptions/sub_trial');
        $this->assertNotNull($update, 'The trial subscription was not updated in Stripe.');
        $this->assertSame('now', $update['trial_end']);
        $this->assertSame('always_invoice', $update['proration_behavior']);
        $this->assertContains('price_creator_m', array_column($update['items'], 'price'));

        $trial = BillingTrial::query()->where('stripe_subscription_id', 'sub_trial')->firstOrFail();
        $this->assertSame($this->creator->id, (int) $trial->plan_id);
        $this->assertSame((int) $this->creator->price_cents, (int) $trial->amount_cents);
    }

    private function subscribeAs(User $user, string $plan)
    {
        return $this->actingAs($user)
            ->withoutMiddleware([
                ValidateCsrfToken::class,
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->postJson($this->localizedRoute('billing.subscribe'), [
                'plan' => $plan,
                'billing_cycle' => 'monthly',
            ]);
    }

    public function test_another_plan_during_a_trial_asks_before_ending_the_trial(): void
    {
        $user = $this->trialingStarterUser();
        $stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($stripe);
        $this->mock(BillingSyncService::class, fn ($mock) => $mock->shouldReceive('syncIfStale')->andReturnNull());

        $this->subscribeAs($user, 'creator')
            ->assertStatus(409)
            ->assertJsonPath('requires_trial_end_confirmation', true);

        $this->assertNull($stripe->lastRequest('post', '/v1/subscriptions/sub_trial'), 'The trial was ended without consent.');
        $this->assertSame(BillingTrial::STATUS_TRIALING, BillingTrial::query()->where('stripe_subscription_id', 'sub_trial')->value('status'));
    }

    public function test_the_same_plan_during_a_trial_keeps_the_trial_and_charges_nothing(): void
    {
        $user = $this->trialingStarterUser();
        $stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($stripe);
        $this->mock(BillingSyncService::class, fn ($mock) => $mock->shouldReceive('syncIfStale')->andReturnNull());

        $this->subscribeAs($user, 'starter')
            ->assertStatus(409)
            ->assertJsonPath('already_on_trial', true);

        $this->assertNull($stripe->lastRequest('post', '/v1/subscriptions/sub_trial'), 'The trial was ended for the same plan.');
        $this->assertSame('trialing', BillingSubscription::query()->where('stripe_id', 'sub_trial')->value('stripe_status'));
    }

    public function test_plan_change_webhook_moves_the_trial_record_to_the_charged_plan(): void
    {
        $this->trialingStarterUser();
        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });
        config(['services.stripe.webhook_secret' => null, 'cashier.webhook.secret' => null]);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_trial_swap',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_trial',
                'object' => 'subscription',
                'customer' => 'cus_trial',
                'status' => 'trialing',
                'cancel_at_period_end' => false,
                'items' => ['data' => [['id' => 'si_trial', 'price' => ['id' => 'price_creator_m']]]],
            ]],
        ])->assertOk();

        $trial = BillingTrial::query()->where('stripe_subscription_id', 'sub_trial')->firstOrFail();
        $this->assertSame($this->creator->id, (int) $trial->plan_id);
        $this->assertSame((int) $this->creator->price_cents, (int) $trial->amount_cents);
        $this->assertSame($this->starter->id, (int) $trial->quota_plan_id);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->status);
    }
}

class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,params:array}> */
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtolower($method), 'path' => $path, 'params' => (array) $params];

        if ($path === '/v1/subscriptions/sub_trial') {
            $updated = strtolower($method) === 'post';
            $price = $updated ? 'price_creator_m' : 'price_starter_m';

            return [json_encode([
                'id' => 'sub_trial',
                'object' => 'subscription',
                'customer' => 'cus_trial',
                'status' => $updated ? 'active' : 'trialing',
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_trial',
                        'object' => 'subscription_item',
                        'quantity' => 1,
                        'price' => [
                            'id' => $price,
                            'object' => 'price',
                            'product' => 'prod_x',
                            'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
                        ],
                    ]],
                ],
                'latest_invoice' => null,
            ]), 200, []];
        }

        return [json_encode(['id' => 'obj_fake', 'object' => 'unknown']), 200, []];
    }

    public function lastRequest(string $method, string $path): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === $method && $request['path'] === $path) {
                return $request['params'];
            }
        }

        return null;
    }
}
