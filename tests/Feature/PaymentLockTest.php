<?php

namespace Tests\Feature;

use App\Models\CharPackPurchase;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripePackGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\FakeStripePackGateway;
use Tests\TestCase;

/**
 * One payment at a time for each member: a second click or tab while a payment
 * is going through is turned away, so nobody pays for two subscriptions or packs.
 */
class PaymentLockTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

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
        ApiRequestor::setHttpClient($this->stripe);

        Plan::create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_minutes' => 10, 'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        Plan::create([
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

    private function member(): User
    {
        return User::factory()->create(['subscription_status_checked_at' => now()]);
    }

    public function test_a_second_checkout_while_one_is_going_through_is_turned_away(): void
    {
        $member = $this->member();
        // The first click's request is still paying.
        $this->assertTrue(Cache::lock('billing-subscribe:'.$member->id, 60)->get());

        $this->actingAs($member)
            ->postJsonLocalizedRoute('billing.subscribe', ['plan' => 'starter', 'billing_cycle' => 'monthly', 'payment_method' => 'pm_card'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', __('ui.backend.billing_payment_in_progress'));

        $this->assertSame([], $this->stripe->requests, 'Stripe was asked to charge a second time.');
        $this->assertNotSame('ui.backend.billing_payment_in_progress', __('ui.backend.billing_payment_in_progress', [], 'ar'));
    }

    public function test_the_lock_is_let_go_once_the_checkout_is_answered(): void
    {
        $member = $this->member();

        // Stripe refuses (nothing is set up in the fake), and the member is told so.
        $this->actingAs($member)
            ->postJsonLocalizedRoute('billing.subscribe', ['plan' => 'starter', 'billing_cycle' => 'monthly', 'payment_method' => 'pm_card'])
            ->assertStatus(422);

        $this->assertTrue(Cache::lock('billing-subscribe:'.$member->id, 60)->get());
    }

    public function test_a_second_pack_payment_while_one_is_going_through_is_turned_away(): void
    {
        $gateway = new FakeStripePackGateway;
        $this->app->instance(StripePackGateway::class, $gateway);
        Plan::create([
            'slug' => 'pack', 'name' => 'Premium Pack', 'kind' => Plan::KIND_PACK, 'pack_days' => 30,
            'price_cents' => 200, 'monthly_chars' => 20000, 'monthly_requests' => 0, 'monthly_minutes' => 0,
            'max_chars_per_request' => 5000, 'voice_tier' => 'hd', 'is_active' => true,
        ]);
        $member = $this->member();
        $this->assertTrue(Cache::lock('billing-pack:'.$member->id, 60)->get());

        $this->actingAs($member)
            ->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('ui.backend.billing_payment_in_progress'));

        $this->assertSame([], $gateway->charges);
        $this->assertSame(0, CharPackPurchase::query()->count());

        // Once the first one is answered, paying works as before.
        Cache::lock('billing-pack:'.$member->id)->forceRelease();
        $this->actingAs($member)
            ->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertCount(1, $gateway->charges);
    }
}
