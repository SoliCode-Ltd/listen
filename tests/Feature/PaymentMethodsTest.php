<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\BusinessPaymentMethods;
use App\Models\ManualPaymentRequest;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SavedPaymentMethod;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\ManualPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * How each member paid (card, Apple Pay, Google Pay, InstaPay, Vodafone Cash) is
 * kept for Billing → Payments, the Subscriptions list and the dashboard.
 */
class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => '',
            'cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake',
        ]);
        Cache::flush();
        Mail::fake();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        Plan::query()->create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 300, 'monthly_chars' => 30000,
            'monthly_requests' => 60, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->creator = Plan::query()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_minutes' => 5000, 'monthly_chars' => 500000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true, 'stripe_price_monthly' => 'price_creator_m',
        ]);
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $extra */
    private function charge(string $id, string $customer, ?string $wallet, array $extra = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'object' => 'charge', 'customer' => $customer, 'status' => 'succeeded', 'paid' => true,
            'amount' => 1540, 'amount_refunded' => 0, 'currency' => 'usd', 'description' => 'Subscription creation',
            'created' => Carbon::parse('2026-10-28 17:36:13', 'UTC')->timestamp,
            'payment_method' => 'pm_'.$id,
            'payment_method_details' => ['type' => 'card', 'card' => [
                'brand' => 'visa', 'last4' => '6753', 'country' => 'SA', 'exp_month' => 12, 'exp_year' => 2031,
                'funding' => 'debit', 'fingerprint' => '8xYmLXZzQb8QHP4v', 'checks' => ['cvc_check' => null],
                'wallet' => $wallet ? ['type' => $wallet] : null,
            ]],
        ], $extra);
    }

    /** @param  list<array<string, mixed>>  $charges */
    private function stripeCharges(array $charges): void
    {
        $this->stripe->answer('get', '/v1/charges', ['object' => 'list', 'data' => $charges, 'has_more' => false, 'url' => '/v1/charges']);
    }

    private function webhook(string $eventId, string $type, string $customer, string $object = 'invoice')
    {
        return $this->postJson('/api/stripe/webhook', [
            'id' => $eventId, 'type' => $type,
            'data' => ['object' => ['id' => 'obj_'.$eventId, 'object' => $object, 'customer' => $customer, 'currency' => 'usd', 'amount_paid' => 1540]],
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    public function test_a_paid_invoice_keeps_how_the_member_paid_once_and_a_refund_later(): void
    {
        $member = User::factory()->create(['stripe_id' => 'cus_apple']);
        $this->stripeCharges([
            $this->charge('ch_apple', 'cus_apple', 'apple_pay'),
            // A declined try before it is not a payment.
            $this->charge('ch_declined', 'cus_apple', null, ['status' => 'failed', 'paid' => false]),
        ]);

        $this->webhook('evt_paid', 'invoice.paid', 'cus_apple')->assertOk();
        // Stripe sends both for one payment.
        $this->webhook('evt_succeeded', 'invoice.payment_succeeded', 'cus_apple')->assertOk();

        $payment = Payment::query()->sole();
        $this->assertSame('charge:ch_apple', $payment->reference);
        $this->assertSame($member->id, $payment->user_id);
        $this->assertSame(Payment::METHOD_APPLE_PAY, $payment->method);
        $this->assertSame('Visa •••• 6753 · SA', $payment->cardLabel());
        $this->assertSame(1540, $payment->amount_minor);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('Subscription creation', $payment->description);
        $this->assertSame('2026-10-28 17:36:13', $payment->paid_at->utc()->format('Y-m-d H:i:s'));
        // What the old Saved cards page showed, kept with the payment.
        $this->assertSame(['cus_apple', 'pm_ch_apple', 12, 2031, 'debit', '8xYmLXZzQb8QHP4v', null], [$payment->stripe_customer_id,
            $payment->stripe_payment_method_id, $payment->card_exp_month, $payment->card_exp_year, $payment->card_funding, $payment->card_fingerprint, $payment->card_cvc_check]);

        // Its page shows the card and the card on file for renewals.
        SavedPaymentMethod::query()->create(['user_id' => $member->id, 'stripe_customer_id' => 'cus_apple', 'stripe_payment_method_id' => 'pm_ch_apple',
            'brand' => 'visa', 'last4' => '6753', 'purpose' => SavedPaymentMethod::PURPOSE_SUBSCRIPTION, 'is_default' => true, 'off_session_ready' => false]);
        $this->admin();
        Livewire::test(ViewPayment::class, ['record' => $payment->getKey()])->assertOk()
            ->assertSee('Apple Pay')->assertSee('15.40 USD')->assertSee('Last 4')->assertSee('6753')->assertSee('12 / 2031')
            ->assertSee('Debit')->assertSee('Saudi Arabia')->assertSee('8xYmLXZzQb8QHP4v')->assertSee('pm_ch_apple')->assertSee('cus_apple')
            ->assertSee('Card on file')->assertSee('Subscription')->assertSee('Default card');

        $this->stripeCharges([$this->charge('ch_apple', 'cus_apple', 'apple_pay', ['amount_refunded' => 1540, 'refunded' => true])]);
        $this->webhook('evt_refund', 'charge.refunded', 'cus_apple', 'charge')->assertOk();
        $this->assertSame(1540, $payment->fresh()->refunded_minor);
        $this->assertSame(0, $payment->fresh()->netMinor());
    }

    public function test_a_card_and_a_google_pay_payment_are_told_apart(): void
    {
        User::factory()->create(['stripe_id' => 'cus_two']);
        $this->stripeCharges([
            $this->charge('ch_card', 'cus_two', null, ['amount' => 2200]),
            $this->charge('ch_gpay', 'cus_two', 'google_pay', ['amount' => 900]),
        ]);

        $this->assertSame(2, app(PaymentLedger::class)->syncStripeCustomer('cus_two'));

        $this->assertSame(['ch_card' => 'card', 'ch_gpay' => 'google_pay'], Payment::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Payment $p) => [substr($p->reference, 7) => $p->method])->all());
    }

    public function test_another_businesss_customer_or_no_stripe_key_reads_nothing(): void
    {
        $this->stripeCharges([$this->charge('ch_other', 'cus_other_business', 'apple_pay')]);
        app(PaymentLedger::class)->syncStripeCustomer('cus_other_business');
        $this->assertFalse($this->stripe->sent('get', '/v1/charges'));

        User::factory()->create(['stripe_id' => 'cus_ours']);
        config(['cashier.secret' => '']);
        app(PaymentLedger::class)->syncStripeCustomer('cus_ours');
        $this->assertFalse($this->stripe->sent('get', '/v1/charges'));

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_payment_by_hand_is_kept_with_its_method_once_approved(): void
    {
        $service = app(ManualPaymentService::class);
        $quote = ['amount_minor' => 99500, 'currency' => 'EGP', 'country_code' => 'EG'];
        $request = $service->createRequest(User::factory()->create(), $this->creator, 'monthly', 'instapay', $quote, ['country_code' => 'EG', 'lines' => []]);
        $refused = $service->createRequest(User::factory()->create(), $this->creator, 'monthly', 'vodafone_cash', $quote, ['country_code' => 'EG', 'lines' => []]);
        $this->assertSame(0, Payment::query()->count());

        $service->approve($request, User::factory()->create(['is_admin' => true]));
        $service->reject($refused, User::factory()->create(['is_admin' => true]), 'No payment found.');

        $payment = Payment::query()->sole();
        $this->assertSame('manual:'.$request->id, $payment->reference);
        $this->assertSame(Payment::METHOD_INSTAPAY, $payment->method);
        $this->assertSame(99500, $payment->amount_minor);
        $this->assertSame('EGP', $payment->currency);
        $this->assertSame('Creator', $payment->description);
    }

    public function test_fetching_past_payments_keeps_only_our_members_and_nothing_twice(): void
    {
        $member = User::factory()->create(['stripe_id' => 'cus_ours', 'created_at' => now()->subMonths(3)]);
        $this->stripeCharges([
            $this->charge('ch_ours', 'cus_ours', 'google_pay'),
            $this->charge('ch_theirs', 'cus_other_business', null),
        ]);
        ManualPaymentRequest::query()->create([
            'user_id' => $member->id, 'plan_id' => $this->creator->id, 'billing_cycle' => 'monthly', 'payment_method' => 'vodafone_cash',
            'expected_amount_minor' => 99500, 'currency' => 'EGP', 'country_code' => 'EG', 'status' => ManualPaymentRequest::STATUS_APPROVED,
            'submitted_at' => now()->subDays(2), 'reviewed_at' => now()->subDay(),
        ]);

        $this->admin();
        Livewire::test(ListPayments::class)->callAction('importPayments')->assertNotified('2 payments added');
        Livewire::test(ListPayments::class)->callAction('importPayments')->assertNotified('0 payments added');

        $this->assertSame(['charge:ch_ours' => 'google_pay', 'manual:1' => 'vodafone_cash'], Payment::query()->orderBy('reference')->pluck('method', 'reference')->all());
        $this->assertTrue($this->stripe->sent('get', '/v1/charges'));
    }

    public function test_the_admin_shows_how_each_member_paid_and_the_dashboard_counts_it(): void
    {
        $apple = User::factory()->create(['name' => 'Apple Member']);
        $card = User::factory()->create(['name' => 'Card Member']);
        foreach ([$apple, $card] as $user) {
            Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $this->creator->id, 'status' => 'active', 'billing_payment_state' => 'paid']);
        }
        Payment::query()->create(['user_id' => $apple->id, 'reference' => 'charge:ch_1', 'method' => Payment::METHOD_APPLE_PAY, 'card_brand' => 'visa',
            'card_last4' => '6753', 'card_country' => 'SA', 'amount_minor' => 1540, 'currency' => 'USD', 'description' => 'Subscription creation', 'paid_at' => now()->subHour()]);
        Payment::query()->create(['user_id' => $card->id, 'reference' => 'charge:ch_2', 'method' => Payment::METHOD_CARD, 'card_brand' => 'mastercard',
            'card_last4' => '1996', 'card_country' => 'US', 'amount_minor' => 2200, 'currency' => 'USD', 'paid_at' => now()->subDays(3)]);
        $admin = $this->admin();
        // The team's own test payment is not counted.
        Payment::query()->create(['user_id' => $admin->id, 'reference' => 'charge:ch_admin', 'method' => Payment::METHOD_GOOGLE_PAY,
            'amount_minor' => 100, 'currency' => 'USD', 'paid_at' => now()]);

        Livewire::test(ListPayments::class)->assertOk()->assertSee('Apple Pay')->assertSee('Visa •••• 6753 · SA')->assertSee('15.40 USD');
        // Payments takes the place of the old "Saved cards" page (the cards stay kept for renewals).
        $this->get('/admin/payments')->assertOk();
        $this->get('/admin/saved-payment-methods')->assertNotFound();
        Livewire::test(ListSubscriptions::class)->assertOk()->assertSee('Paid with')->assertSee('Apple Pay')->assertSee('Card')
            ->filterTable('paid_with', Payment::METHOD_APPLE_PAY)->assertSee('Apple Member')->assertDontSee('Card Member');

        Livewire::test(BusinessPaymentMethods::class)->assertOk()
            ->assertSee('How members pay (last 12 months)')
            ->assertSee('USD 37.40 after refunds')
            ->assertSee('Apple Pay')->assertSee('50% of payments · USD 15.40')
            ->assertDontSee('Google Pay');
    }
}
