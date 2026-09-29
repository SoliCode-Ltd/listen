<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use App\Services\Billing\TrialOutcomeService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Stripe\Exception\CardException;
use Tests\TestCase;

/**
 * Talks to Stripe in test mode. Skipped unless STRIPE_LIVE_TEST=1 and STRIPE_SECRET is an sk_test_ key.
 * Never put the secret in this file.
 */
class StripeTestModePurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE_CENTS = 2200;

    private static ?string $stripePriceId = null;

    /** @var list<string> */
    private array $customerIds = [];

    /** @var list<string> */
    private array $clockIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $secret = (string) (getenv('STRIPE_SECRET') ?: '');
        $key = (string) (getenv('STRIPE_KEY') ?: '');
        if (getenv('STRIPE_LIVE_TEST') !== '1' || ! str_starts_with($secret, 'sk_test_')) {
            $this->markTestSkipped('Set STRIPE_LIVE_TEST=1 and an sk_test_ secret to run Stripe test-mode purchases.');
        }

        config([
            'cashier.secret' => $secret,
            'cashier.key' => $key,
            'services.stripe.secret' => $secret,
            'services.stripe.key' => $key,
        ]);

        $this->seedPlans($this->ensureStripePrice());
    }

    protected function tearDown(): void
    {
        foreach ($this->customerIds as $customerId) {
            try {
                $subscriptions = Cashier::stripe()->subscriptions->all([
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 20,
                ]);
                foreach ($subscriptions->data as $subscription) {
                    if (! in_array((string) $subscription->status, ['canceled', 'incomplete_expired'], true)) {
                        Cashier::stripe()->subscriptions->cancel((string) $subscription->id, []);
                    }
                }
                Cashier::stripe()->customers->delete($customerId, []);
            } catch (\Throwable) {
                // The test result matters more than a leftover test-mode customer.
            }
        }

        foreach ($this->clockIds as $clockId) {
            try {
                Cashier::stripe()->testHelpers->testClocks->delete($clockId, []);
            } catch (\Throwable) {
                // Already deleted with its customers.
            }
        }

        parent::tearDown();
    }

    public function test_visa_trial_is_trialing_on_stripe_and_does_not_charge(): void
    {
        $user = $this->makeUser('visa-trial');
        $confirmed = $this->startTrial($user, 'pm_card_visa');

        $subscriptionId = (string) $confirmed['subscription_id'];
        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($subscriptionId, []);
        $this->assertSame('trialing', (string) $stripeSubscription->status);
        $this->assertNull($stripeSubscription->cancel_at);
        $trialEnd = (int) $stripeSubscription->trial_end;
        $this->assertGreaterThan(now()->addDays(6)->getTimestamp(), $trialEnd);
        $this->assertLessThan(now()->addDays(8)->getTimestamp(), $trialEnd);

        $invoices = Cashier::stripe()->invoices->all([
            'subscription' => $subscriptionId,
            'limit' => 10,
        ]);
        $this->assertNotEmpty($invoices->data);
        foreach ($invoices->data as $invoice) {
            $this->assertSame(0, (int) $invoice->amount_paid, 'A trial must not collect money on day one.');
        }

        $preview = Cashier::stripe()->invoices->createPreview([
            'customer' => (string) $user->fresh()->stripe_id,
            'subscription' => $subscriptionId,
        ]);
        $this->assertSame(self::PRICE_CENTS, (int) $preview->amount_due);

        $trial = BillingTrial::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->status);
        $this->assertSame('4242', $trial->card_last4);
        $this->assertSame('visa', $trial->card_brand);
        $this->assertNotSame('', (string) $trial->card_fingerprint);
        $this->assertTrue((bool) $trial->off_session_ready);
        $this->assertNull($trial->paid_at);

        $card = SavedPaymentMethod::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('4242', $card->last4);
        $this->assertSame('visa', $card->brand);
        $this->assertMatchesRegularExpression('/^pm_[A-Za-z0-9]+$/', (string) $card->stripe_payment_method_id);
        $this->assertDoesNotMatchRegularExpression('/\d{12,}/', (string) $card->stripe_payment_method_id);

        $access = app(PlanAccessService::class);
        $this->assertSame('creator', $access->resolveActivePlanForUserId($user->id)?->slug);
        $quota = $access->resolveQuotaPlanForUserId($user->id);
        $this->assertSame(500000, (int) $quota->monthly_chars);
        $this->assertSame(5000, (int) $quota->max_chars_per_request);
        $this->assertFalse((bool) $quota->allow_overage);

        $zeroInvoice = $invoices->data[0];
        app(TrialOutcomeService::class)->handleStripeEvent((object) [
            'type' => 'invoice.paid',
            'data' => (object) ['object' => $zeroInvoice],
        ]);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);

        $this->recordResult('visa_trial', [
            'subscription' => $subscriptionId,
            'status' => (string) $stripeSubscription->status,
            'amount_paid' => 0,
            'upcoming_amount_due' => (int) $preview->amount_due,
            'trial_end' => $trialEnd,
        ]);
    }

    public function test_advancing_seven_days_charges_the_saved_card_and_marks_the_trial_paid(): void
    {
        $clock = Cashier::stripe()->testHelpers->testClocks->create([
            'frozen_time' => time(),
            'name' => 'creator-trial-paid',
        ]);
        $this->clockIds[] = (string) $clock->id;

        $customer = Cashier::stripe()->customers->create([
            'email' => 'clock-paid-'.uniqid('', true).'@example.test',
            'test_clock' => $clock->id,
        ]);
        $this->customerIds[] = (string) $customer->id;

        $user = $this->makeUser('clock-paid', [
            'stripe_id' => $customer->id,
            'email' => (string) $customer->email,
        ]);
        $confirmed = $this->startTrial($user, 'pm_card_visa');
        $this->advanceClockPastTrialCharge((string) $clock->id, (int) $clock->frozen_time, (string) $confirmed['subscription_id']);

        $stripeSubscription = $this->waitForSubscriptionStatus((string) $confirmed['subscription_id'], ['active']);
        $this->assertSame('active', (string) $stripeSubscription->status);

        $paidInvoice = $this->paidInvoice((string) $confirmed['subscription_id']);
        $this->assertNotNull($paidInvoice);
        $this->assertGreaterThan(0, (int) $paidInvoice->amount_paid);
        $this->assertSame(self::PRICE_CENTS, (int) $paidInvoice->amount_paid);

        $outcome = app(TrialOutcomeService::class);
        $outcome->handleStripeEvent((object) [
            'type' => 'invoice.paid',
            'data' => (object) ['object' => $paidInvoice],
        ]);

        $trial = BillingTrial::query()->where('stripe_subscription_id', $confirmed['subscription_id'])->firstOrFail();
        $this->assertSame(BillingTrial::STATUS_PAID, $trial->status);
        $this->assertSame(self::PRICE_CENTS, (int) $trial->amount_paid_cents);
        $this->assertNotNull($trial->paid_at);

        $this->recordResult('trial_converted', [
            'subscription' => (string) $confirmed['subscription_id'],
            'status' => (string) $stripeSubscription->status,
            'amount_paid' => (int) $paidInvoice->amount_paid,
            'invoice' => (string) $paidInvoice->id,
        ]);
    }

    public function test_same_visa_cannot_start_a_second_trial_and_admin_retests_still_can(): void
    {
        $first = $this->makeUser('first-trial');
        $this->startTrial($first, 'pm_card_visa');
        $fingerprint = (string) BillingTrial::query()->where('user_id', $first->id)->value('card_fingerprint');
        $this->assertNotSame('', $fingerprint);

        $second = $this->makeUser('second-trial');
        $setup = $this->setupIntent($second);
        $card = $this->confirmCard($setup['setup_intent_id'], 'pm_card_visa');
        $this->assertSame('succeeded', $card['status']);

        $blocked = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $setup['setup_intent_id'],
        ]);
        $blocked->assertStatus(422);
        $blockedTrial = BillingTrial::query()->where('user_id', $second->id)->firstOrFail();
        $this->assertSame(BillingTrial::STATUS_BLOCKED, $blockedTrial->status);
        $this->assertSame('already_used_card', $blockedTrial->blocked_reason);
        $this->assertSame(
            0,
            BillingSubscription::query()->where('user_id', $second->id)->count()
        );

        $admin = $this->makeUser('admin-retest', ['is_admin' => true]);
        $firstAdmin = $this->startTrial($admin, 'pm_card_visa');
        $secondAdmin = $this->startTrial($admin, 'pm_card_visa');
        $this->assertNotSame($firstAdmin['subscription_id'], $secondAdmin['subscription_id']);

        $firstStripe = Cashier::stripe()->subscriptions->retrieve((string) $firstAdmin['subscription_id'], []);
        $secondStripe = Cashier::stripe()->subscriptions->retrieve((string) $secondAdmin['subscription_id'], []);
        $this->assertSame('canceled', (string) $firstStripe->status);
        $this->assertSame('trialing', (string) $secondStripe->status);

        $open = BillingTrial::query()
            ->where('user_id', $admin->id)
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->get();
        $this->assertCount(1, $open);
        $this->assertTrue((bool) $open->first()->is_admin_test);
    }

    public function test_declined_card_and_unconfirmed_setup_do_not_start_a_trial(): void
    {
        $declinedUser = $this->makeUser('declined');
        $declinedSetup = $this->setupIntent($declinedUser);
        $declined = $this->confirmCard($declinedSetup['setup_intent_id'], 'pm_card_chargeDeclined');
        $this->assertSame('card_error', $declined['status']);

        $response = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $declinedSetup['setup_intent_id'],
        ]);
        $response->assertStatus(422);
        $this->assertSame(
            0,
            BillingTrial::query()->where('user_id', $declinedUser->id)->where('status', BillingTrial::STATUS_TRIALING)->count()
        );
        $this->assertSame(
            0,
            BillingSubscription::query()->where('user_id', $declinedUser->id)->count()
        );

        $cvcUser = $this->makeUser('cvc-fail');
        $cvcSetup = $this->setupIntent($cvcUser);
        $cvc = $this->confirmCard($cvcSetup['setup_intent_id'], 'pm_card_cvcCheckFail');
        $this->assertSame('succeeded', $cvc['status']);
        $cvcResponse = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $cvcSetup['setup_intent_id'],
        ]);
        $cvcResponse->assertStatus(422);
        $this->assertSame(0, BillingSubscription::query()->where('user_id', $cvcUser->id)->count());

        $actionUser = $this->makeUser('needs-action');
        $actionSetup = $this->setupIntent($actionUser);
        $action = $this->confirmCard($actionSetup['setup_intent_id'], 'pm_card_authenticationRequiredOnSetup');
        $this->assertSame('requires_action', $action['status']);
        $actionResponse = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $actionSetup['setup_intent_id'],
        ]);
        $actionResponse->assertStatus(422);
        $this->assertSame(0, BillingSubscription::query()->where('user_id', $actionUser->id)->count());

        $pendingUser = $this->makeUser('pending-setup');
        $pending = $this->setupIntent($pendingUser);
        $unconfirmed = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $pending['setup_intent_id'],
        ]);
        $unconfirmed->assertStatus(422);
        $this->assertSame(0, BillingSubscription::query()->where('user_id', $pendingUser->id)->count());
    }

    public function test_pay_now_checkout_charges_immediately_and_does_not_open_a_trial(): void
    {
        $user = $this->makeUser('pay-now');
        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('billing.subscribe', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
            'payment_method' => 'pm_card_visa',
            'save_card' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('ok') === true, (string) $response->getContent());
        $this->assertSame('creator', $response->json('plan.slug'));

        $user->refresh();
        $this->customerIds[] = (string) $user->stripe_id;

        $subscriptions = Cashier::stripe()->subscriptions->all([
            'customer' => $user->stripe_id,
            'status' => 'all',
            'limit' => 5,
        ]);
        $this->assertCount(1, $subscriptions->data);
        $subscription = $subscriptions->data[0];
        $this->assertSame('active', (string) $subscription->status);
        $this->assertNull($subscription->trial_end);
        $this->assertSame(0, BillingTrial::query()->where('user_id', $user->id)->count());

        $paidInvoice = $this->paidInvoice((string) $subscription->id);
        $this->assertNotNull($paidInvoice);
        $this->assertSame(self::PRICE_CENTS, (int) $paidInvoice->amount_paid);

        $saved = SavedPaymentMethod::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame('4242', $saved->last4);
        $this->assertSame('subscription', $saved->purpose);

        $local = BillingSubscription::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($local);
        $this->assertSame('active', (string) $local->stripe_status);
        $this->assertSame('creator', app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)?->slug);

        $this->recordResult('pay_now', [
            'subscription' => (string) $subscription->id,
            'status' => (string) $subscription->status,
            'trial_end' => $subscription->trial_end,
            'amount_paid' => (int) $paidInvoice->amount_paid,
            'invoice' => (string) $paidInvoice->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(string $label, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => 'Stripe '.$label,
            'email' => $label.'-'.uniqid('', true).'@example.test',
            'is_admin' => false,
        ], $attributes));

        if (! empty($attributes['stripe_id'])) {
            $this->customerIds[] = (string) $attributes['stripe_id'];
        }

        return $user;
    }

    private function seedPlans(string $priceId): void
    {
        Plan::create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_chars' => 10000,
            'monthly_minutes' => 10,
            'monthly_requests' => 60,
            'max_chars_per_request' => 1200,
            'allow_overage' => false,
            'is_active' => true,
        ]);

        Plan::create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 900,
            'monthly_chars' => 500000,
            'monthly_minutes' => 120,
            'premium_voice_minutes' => 60,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'allow_overage' => true,
            'is_active' => true,
        ]);

        Plan::create([
            'slug' => 'creator',
            'name' => 'Creator',
            'price_cents' => self::PRICE_CENTS,
            'monthly_chars' => 1500000,
            'monthly_minutes' => 360,
            'premium_voice_minutes' => 180,
            'monthly_requests' => 0,
            'max_chars_per_request' => 20000,
            'allow_overage' => true,
            'stripe_price_monthly' => $priceId,
            'is_active' => true,
        ]);
    }

    private function ensureStripePrice(): string
    {
        if (self::$stripePriceId !== null) {
            return self::$stripePriceId;
        }

        $product = Cashier::stripe()->products->create([
            'name' => 'Listen Creator PHPUnit trial',
        ]);
        $price = Cashier::stripe()->prices->create([
            'product' => $product->id,
            'unit_amount' => self::PRICE_CENTS,
            'currency' => 'usd',
            'recurring' => ['interval' => 'month'],
        ]);

        self::$stripePriceId = (string) $price->id;

        return self::$stripePriceId;
    }

    /**
     * @return array{subscription_id:string,trial_ends_at:string}
     */
    private function startTrial(User $user, string $paymentMethod): array
    {
        $setup = $this->setupIntent($user);
        $card = $this->confirmCard($setup['setup_intent_id'], $paymentMethod);
        $this->assertSame('succeeded', $card['status'], (string) ($card['error'] ?? ''));

        $response = $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $setup['setup_intent_id'],
        ]);
        $this->assertTrue($response->json('ok') === true, (string) $response->getContent());
        $response->assertOk();

        $user->refresh();
        if ($user->stripe_id) {
            $this->customerIds[] = (string) $user->stripe_id;
        }

        $trial = BillingTrial::query()->where('user_id', $user->id)->where('status', BillingTrial::STATUS_TRIALING)->latest('id')->first();
        $this->assertNotNull($trial, (string) $response->getContent());

        return [
            'subscription_id' => (string) $trial->stripe_subscription_id,
            'trial_ends_at' => (string) $response->json('trial_ends_at'),
        ];
    }

    /**
     * @return array{setup_intent_id:string,client_secret:string}
     */
    private function setupIntent(User $user): array
    {
        $response = $this->actingAs($user)->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ]);
        $this->assertTrue($response->json('ok') === true, (string) $response->getContent());

        $user->refresh();
        if ($user->stripe_id) {
            $this->customerIds[] = (string) $user->stripe_id;
        }

        return [
            'setup_intent_id' => (string) $response->json('setup_intent_id'),
            'client_secret' => (string) $response->json('client_secret'),
        ];
    }

    /**
     * @return array{status:string,payment_method:string,error:?string}
     */
    private function confirmCard(string $setupIntentId, string $paymentMethod): array
    {
        try {
            $intent = Cashier::stripe()->setupIntents->confirm($setupIntentId, [
                'payment_method' => $paymentMethod,
            ]);
        } catch (CardException $exception) {
            return [
                'status' => 'card_error',
                'payment_method' => $paymentMethod,
                'error' => $exception->getMessage(),
            ];
        }

        $attached = $intent->payment_method;
        $paymentMethodId = is_object($attached) ? (string) ($attached->id ?? '') : (string) $attached;

        return [
            'status' => (string) $intent->status,
            'payment_method' => $paymentMethodId,
            'error' => null,
        ];
    }

    private function advanceClockPastTrialCharge(string $clockId, int $frozenTime, string $subscriptionId): void
    {
        Cashier::stripe()->testHelpers->testClocks->advance($clockId, [
            'frozen_time' => $frozenTime + (7 * 24 * 60 * 60) + 60,
        ]);
        $this->waitUntilReady($clockId);

        $finalizeAt = 0;
        $invoices = Cashier::stripe()->invoices->all([
            'subscription' => $subscriptionId,
            'limit' => 10,
        ]);
        foreach ($invoices->data as $invoice) {
            $finalizeAt = max($finalizeAt, (int) ($invoice->automatically_finalizes_at ?? 0));
        }

        $clock = Cashier::stripe()->testHelpers->testClocks->retrieve($clockId, []);
        $target = max(((int) $clock->frozen_time) + 3600, $finalizeAt + 120);
        Cashier::stripe()->testHelpers->testClocks->advance($clockId, [
            'frozen_time' => $target,
        ]);
        $this->waitUntilReady($clockId);
    }

    private function waitUntilReady(string $clockId): void
    {
        $deadline = time() + 90;
        do {
            $clock = Cashier::stripe()->testHelpers->testClocks->retrieve($clockId, []);
            if ((string) $clock->status === 'ready') {
                return;
            }
            usleep(500000);
        } while (time() < $deadline);

        $this->fail('Stripe test clock did not finish advancing.');
    }

    private function waitForSubscriptionStatus(string $subscriptionId, array $wanted): object
    {
        $deadline = time() + 30;
        $latest = null;
        do {
            $latest = Cashier::stripe()->subscriptions->retrieve($subscriptionId, []);
            if (in_array((string) $latest->status, $wanted, true)) {
                return $latest;
            }
            usleep(500000);
        } while (time() < $deadline);

        $this->fail('Subscription stayed in status '.($latest->status ?? 'unknown'));
    }

    private function paidInvoice(string $subscriptionId): ?object
    {
        $invoices = Cashier::stripe()->invoices->all([
            'subscription' => $subscriptionId,
            'limit' => 10,
        ]);

        foreach ($invoices->data as $invoice) {
            if ((int) $invoice->amount_paid > 0 && (string) $invoice->status === 'paid') {
                return $invoice;
            }
        }

        $summary = [];
        foreach ($invoices->data as $invoice) {
            $summary[] = (string) $invoice->id.' status='.$invoice->status.' paid='.$invoice->amount_paid;
        }
        $this->fail('No paid invoice for '.$subscriptionId.' ['.implode('; ', $summary).']');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordResult(string $name, array $payload): void
    {
        $path = '/tmp/stripe-live-results.json';
        $existing = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $existing = is_array($decoded) ? $decoded : [];
        }
        $existing[$name] = $payload;
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT));
    }
}
