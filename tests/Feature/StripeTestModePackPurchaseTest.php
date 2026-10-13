<?php

namespace Tests\Feature;

use App\Models\CharPackPurchase;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CashierStripePackGateway;
use App\Services\Billing\CharPackCardPayment;
use App\Services\Billing\CharPackService;
use App\Services\Billing\StripePackGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

/**
 * Buys the pack against Stripe's sandbox (test mode) with Stripe's test cards.
 * Skipped unless STRIPE_LIVE_TEST=1 and STRIPE_SECRET is an sk_test_ key, so
 * it can never charge a real card. Never put the secret in this file.
 */
class StripeTestModePackPurchaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $customerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $secret = (string) (getenv('STRIPE_SECRET') ?: '');
        if (getenv('STRIPE_LIVE_TEST') !== '1' || ! str_starts_with($secret, 'sk_test_')) {
            $this->markTestSkipped('Set STRIPE_LIVE_TEST=1 and an sk_test_ secret to buy the pack in Stripe test mode.');
        }

        config(['cashier.secret' => $secret, 'services.stripe.secret' => $secret]);
        $this->app->bind(StripePackGateway::class, CashierStripePackGateway::class);

        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => 'standard']);
        Plan::query()->where('slug', 'pack')->update(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->customerIds as $customerId) {
            try {
                Cashier::stripe()->customers->delete($customerId);
            } catch (\Throwable) {
                // Already gone.
            }
        }

        parent::tearDown();
    }

    public function test_a_test_card_buys_the_pack(): void
    {
        $user = $this->buyer();

        $result = app(CharPackCardPayment::class)->start($user, $this->pack(), 'pm_card_visa');

        $this->assertSame('paid', $result['status']);
        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
        $intent = Cashier::stripe()->paymentIntents->retrieve($result['purchase']->stripe_payment_intent_id);
        $this->assertSame('succeeded', $intent->status);
        $this->assertSame(200, $intent->amount);
        $this->assertSame('char_pack', $intent->metadata->listen_purpose);
    }

    public function test_a_declined_test_card_buys_nothing(): void
    {
        $user = $this->buyer();

        $result = app(CharPackCardPayment::class)->start($user, $this->pack(), 'pm_card_chargeDeclined');

        $this->assertSame('failed', $result['status']);
        $this->assertSame(CharPackPurchase::STATUS_FAILED, $result['purchase']->status);
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
    }

    public function test_a_card_that_needs_the_bank_check_waits_for_it(): void
    {
        $user = $this->buyer();

        $result = app(CharPackCardPayment::class)->start($user, $this->pack(), 'pm_card_authenticationRequired');

        $this->assertSame('requires_action', $result['status']);
        $this->assertNotEmpty($result['client_secret']);
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
    }

    private function buyer(): User
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->customerIds[] = app(StripePackGateway::class)->ensureCustomer($user);

        return $user->fresh();
    }

    private function pack(): Plan
    {
        return Plan::query()->where('slug', 'pack')->firstOrFail();
    }
}
