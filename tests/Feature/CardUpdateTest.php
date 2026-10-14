<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\SavedPaymentMethodRecorder;
use App\Services\Billing\StripeCardUpdateGateway;
use App\Services\BillingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private object $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);

        // No real Stripe: sync and card bookkeeping are not what these tests check.
        $this->app->instance(BillingSyncService::class, Mockery::mock(BillingSyncService::class)->shouldIgnoreMissing());
        $this->app->instance(SavedPaymentMethodRecorder::class, Mockery::mock(SavedPaymentMethodRecorder::class)->shouldIgnoreMissing());

        $this->stripe = new class implements StripeCardUpdateGateway
        {
            public array $intent = ['id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_me', 'payment_method' => 'pm_new', 'purpose' => 'card_update'];

            public array $renewals = [];

            public array $paid = [];

            public array $payResult = ['attempted' => 1, 'paid' => 1];

            public function ensureCustomer(User $user): string
            {
                return 'cus_me';
            }

            public function createSetupIntent(string $customerId, int $userId): array
            {
                return ['id' => 'seti_1', 'client_secret' => 'seti_1_secret'];
            }

            public function retrieveSetupIntent(string $setupIntentId): array
            {
                return $this->intent;
            }

            public function useForRenewals(string $customerId, ?string $subscriptionId, string $paymentMethodId): void
            {
                $this->renewals[] = [$customerId, $subscriptionId, $paymentMethodId];
            }

            public function payOpenInvoices(string $customerId, string $subscriptionId, string $paymentMethodId): array
            {
                $this->paid[] = [$subscriptionId, $paymentMethodId];

                return $this->payResult;
            }
        };
        $this->app->instance(StripeCardUpdateGateway::class, $this->stripe);
    }

    private function member(string $stripeStatus = 'active'): User
    {
        $user = User::factory()->create(['stripe_id' => 'cus_me']);
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_1',
            'stripe_status' => $stripeStatus, 'stripe_price' => 'price_starter', 'quantity' => 1,
        ]);

        return $user;
    }

    public function test_a_card_the_bank_approved_becomes_the_renewal_card(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method.intent')
            ->assertOk()->assertJsonPath('client_secret', 'seti_1_secret');

        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertOk()->assertJsonPath('message', __('ui.backend.billing_payment_method_updated'));

        // The customer's default and the subscription's own card both move to it.
        $this->assertSame([['cus_me', 'sub_1', 'pm_new']], $this->stripe->renewals);
        $this->assertSame([], $this->stripe->paid, 'nothing overdue, nothing charged');
    }

    public function test_a_card_the_bank_did_not_approve_keeps_the_current_card(): void
    {
        $user = $this->member();
        $this->stripe->intent['status'] = 'requires_payment_method';

        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertStatus(422)->assertJsonPath('message', __('ui.backend.billing_card_not_approved'));

        $this->assertSame([], $this->stripe->renewals);
    }

    public function test_another_customers_card_update_cannot_be_used(): void
    {
        $user = $this->member();
        $this->stripe->intent['customer'] = 'cus_someone_else';

        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertStatus(422);
        $this->assertSame([], $this->stripe->renewals);

        // Nor a SetupIntent made for something else (a trial, for example).
        $this->stripe->intent['customer'] = 'cus_me';
        $this->stripe->intent['purpose'] = 'card_trial';
        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertStatus(422);
        $this->assertSame([], $this->stripe->renewals);
    }

    public function test_a_member_behind_on_payment_is_charged_with_the_new_card_right_away(): void
    {
        $user = $this->member('past_due');

        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertOk()->assertJsonPath('message', __('ui.backend.billing_card_updated_and_paid'));
        $this->assertSame([['sub_1', 'pm_new']], $this->stripe->paid);

        // The bank declines the overdue charge: the card is still saved, and the member is told.
        $this->stripe->payResult = ['attempted' => 1, 'paid' => 0];
        $this->actingAs($user)->postJsonLocalizedRoute('billing.payment-method', ['setup_intent' => 'seti_1'])
            ->assertOk()->assertJsonPath('message', __('ui.backend.billing_card_updated_payment_failed'));
    }

    public function test_the_page_asks_the_bank_before_saving_a_card(): void
    {
        $user = $this->member();

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee('confirmCardSetup', false)
            ->assertSee(route('billing.payment-method.intent'), false);
    }
}
