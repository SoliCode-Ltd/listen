<?php

namespace Tests\Feature;

use App\Exceptions\TrialStartException;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CashierStripeTrialGateway;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * A plan's price changes while members keep paying the old one. They must keep
 * their plan, and checking out again must never start a second subscription.
 */
class BillingPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $lite;

    private PriceChangeStripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'cashier.key' => 'pk_test_fake',
            'cashier.secret' => 'sk_test_fake',
        ]);

        $this->free = Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200,
            'allow_overage' => false, 'is_active' => true,
        ]);
        $this->lite = Plan::create([
            'slug' => 'lite', 'name' => 'Lite', 'price_cents' => 300, 'monthly_chars' => 50000,
            'monthly_minutes' => 30, 'monthly_requests' => 300, 'max_chars_per_request' => 3000,
            'allow_overage' => false, 'is_active' => true,
            'stripe_product_id' => 'prod_lite',
            'stripe_price_monthly' => 'price_lite_3', 'stripe_price_yearly' => 'price_lite_y_old',
        ]);

        // The owner raises the price: the plan moves to new Stripe prices.
        $this->lite->update(['price_cents' => 400, 'stripe_price_monthly' => 'price_lite_4', 'stripe_price_yearly' => 'price_lite_y_new']);

        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->andReturnNull();
        $referrals->shouldReceive('isFirstPaidPlanTransition')->andReturnFalse();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->stripe = new PriceChangeStripeClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();

        parent::tearDown();
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['stripe_id' => 'cus_pc'])->save();

        return $user;
    }

    private function activePlan(User $user): ?string
    {
        return app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id)?->slug;
    }

    public function test_the_sync_keeps_a_member_on_an_old_price_on_their_plan(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->lite, 'active', now()->addMonth());
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_old', 'active', 'price_lite_3')];

        app(BillingSyncService::class)->syncUser($user);

        $this->assertSame('lite', $this->activePlan($user));
        $this->assertSame('active', BillingSubscription::query()->where('stripe_id', 'sub_old')->value('stripe_status'));
    }

    public function test_the_sync_never_takes_a_plan_away_while_stripe_charges_for_an_unknown_price(): void
    {
        $user = $this->member();
        $kept = app(PlanAccessService::class)->assignPlan($user, $this->lite, 'active', now()->addDays(20), now()->subDays(10));
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_mystery',
            'stripe_status' => 'active', 'stripe_price' => 'price_mystery', 'quantity' => 1,
        ]);
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_mystery', 'active', 'price_mystery', 'prod_unknown', [])];

        app(BillingSyncService::class)->syncUser($user);

        $this->assertSame('lite', $this->activePlan($user));
        $this->assertSame('active', BillingSubscription::query()->where('stripe_id', 'sub_mystery')->value('stripe_status'));
        // Its billing period is left exactly as it was.
        $this->assertEquals($kept->current_period_start, $kept->fresh()->current_period_start);
        $this->assertEquals($kept->current_period_end, $kept->fresh()->current_period_end);
    }

    public function test_buying_the_same_plan_again_uses_the_live_old_price_subscription_and_charges_nothing(): void
    {
        // What happened in production: the sync had marked the subscription
        // canceled and moved the member to Free, while Stripe still charged it.
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->free, 'active', now()->addMonth());
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_old',
            'stripe_status' => 'canceled', 'stripe_price' => 'price_lite_3', 'quantity' => 1, 'ends_at' => now()->subHour(),
        ]);
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_old', 'active', 'price_lite_3')];

        $response = $this->subscribe($user, ['plan' => 'lite', 'payment_method' => 'pm_pc']);

        $response->assertOk()->assertJson(['ok' => true, 'charged' => false]);
        $this->assertNull($this->stripe->last('post', '/v1/subscriptions'), 'A second subscription was created.');
        $this->assertNull($this->stripe->last('delete', '/v1/subscriptions/sub_old'), 'The paid subscription was canceled.');
        $this->assertArrayNotHasKey('items', $this->stripe->last('post', '/v1/subscriptions/sub_old') ?? [], 'The member was moved to the new price.');
        $this->assertSame('lite', $this->activePlan($user));
    }

    public function test_checkout_never_starts_a_second_subscription_while_one_is_still_charging(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->free, 'active', now()->addMonth());
        // Behind on payment, and Stripe refuses to cancel it right now.
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_behind', 'past_due', 'price_lite_3')];
        $this->stripe->failCancel = true;

        $response = $this->subscribe($user, ['plan' => 'lite', 'payment_method' => 'pm_pc']);

        $response->assertStatus(409)->assertJson(['ok' => false, 'message' => __('ui.backend.billing_already_subscribed')]);
        $this->assertNull($this->stripe->last('post', '/v1/subscriptions'), 'A second subscription was created.');
    }

    public function test_a_subscription_our_copy_calls_canceled_is_still_canceled_in_stripe_before_replacing_it(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->free, 'active', now()->addMonth());
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_behind',
            'stripe_status' => 'canceled', 'stripe_price' => 'price_lite_3', 'quantity' => 1, 'ends_at' => now()->subHour(),
        ]);
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_behind', 'past_due', 'price_lite_3')];

        $this->subscribe($user, ['plan' => 'lite', 'payment_method' => 'pm_pc']);

        $this->assertNotNull($this->stripe->last('delete', '/v1/subscriptions/sub_behind'), 'The unpaid subscription was left running next to the new one.');
    }

    public function test_a_trial_never_starts_next_to_a_subscription_stripe_still_charges(): void
    {
        $this->stripe->subscriptions = [$this->stripe->subscription('sub_old', 'active', 'price_lite_3')];

        try {
            app(CashierStripeTrialGateway::class)->createTrialSubscription('cus_pc', 'price_lite_4', 'pm_pc', 7, []);
            $this->fail('A trial was started next to a paid subscription.');
        } catch (TrialStartException $exception) {
            $this->assertSame('ui.backend.billing_already_subscribed', $exception->errorKey);
        }

        $this->assertNull($this->stripe->last('post', '/v1/subscriptions'), 'A second subscription was created.');
    }

    private function subscribe(User $user, array $body)
    {
        return $this->actingAs($user)
            ->withoutMiddleware([
                ValidateCsrfToken::class,
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
            ])
            ->postJson($this->localizedRoute('billing.subscribe'), $body + ['billing_cycle' => 'monthly']);
    }
}

class PriceChangeStripeClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,params:array}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $subscriptions = [];

    public bool $failCancel = false;

    public function subscription(string $id, string $status, string $price, string $product = 'prod_lite', ?array $metadata = null): array
    {
        $metadata ??= ['listen_plan_slug' => 'lite', 'listen_interval' => 'month'];

        return [
            'id' => $id, 'object' => 'subscription', 'customer' => 'cus_pc', 'status' => $status,
            'created' => time() - 86400, 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null, 'trial_end' => null,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_'.$id, 'object' => 'subscription_item', 'quantity' => 1,
                'current_period_start' => time() - 86400, 'current_period_end' => time() + 29 * 86400,
                'price' => [
                    'id' => $price, 'object' => 'price', 'product' => $product, 'metadata' => $metadata,
                    'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
                ],
            ]]],
        ];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params];

        if ($path === '/v1/subscriptions' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => $this->subscriptions, 'has_more' => false, 'url' => '/v1/subscriptions']);
        }

        if ($path === '/v1/subscriptions' && $method === 'post') {
            return $this->json($this->subscription('sub_new', 'active', (string) ($params['items'][0]['price'] ?? 'price_lite_4')));
        }

        if (preg_match('#^/v1/subscriptions/(sub_\w+)$#', $path, $match)) {
            $found = collect($this->subscriptions)->firstWhere('id', $match[1]);
            if ($method === 'delete') {
                if ($this->failCancel) {
                    return [json_encode(['error' => ['type' => 'api_error', 'message' => 'Try again later.']]), 500, []];
                }

                return $this->json(['status' => 'canceled'] + ($found ?? ['id' => $match[1], 'object' => 'subscription']));
            }

            return $this->json($found ?? ['id' => $match[1], 'object' => 'subscription', 'status' => 'canceled']);
        }

        if ($path === '/v1/customers/cus_pc') {
            return $this->json(['id' => 'cus_pc', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => 'pm_pc']]);
        }

        if ($path === '/v1/payment_methods/pm_pc') {
            return $this->json(['id' => 'pm_pc', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_pc', 'card' => ['brand' => 'visa', 'last4' => '4242']]);
        }

        if ($path === '/v1/invoices' && $method === 'get') {
            return $this->json(['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices']);
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
