<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SavedPaymentMethod;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use App\Support\PlanUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\FakeStripeTrialGateway;
use Tests\TestCase;

class CreatorTrialFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeTrialGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeTrialGateway();
        $this->app->instance(StripeTrialGateway::class, $this->stripe);

        config([
            'services.stripe.key' => 'pk_test_creator_trial',
            'services.stripe.secret' => 'sk_test_creator_trial',
            'services.stripe.prices.creator.monthly' => 'price_creator_month',
        ]);

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
            'price_cents' => 2200,
            'monthly_chars' => 1500000,
            'monthly_minutes' => 360,
            'premium_voice_minutes' => 180,
            'monthly_requests' => 0,
            'max_chars_per_request' => 20000,
            'allow_overage' => true,
            'stripe_price_monthly' => 'price_creator_month',
            'is_active' => true,
        ]);
    }

    public function test_verified_visa_starts_creator_trial_without_charging_or_qualifying_a_referral(): void
    {
        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('isFirstPaidPlanTransition')->never();
        $referrals->shouldReceive('evaluate')->never();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        $response = $this->startTrial($user, 'pm_visa_4242', '4242', 'fp_visa_4242');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('trial', true)
            ->assertJsonPath('plan', 'creator')
            ->assertJsonPath('amount_cents', 2200);

        $this->assertCount(1, $this->stripe->created);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $this->stripe->created[0]['trial_end'], 120);
        $this->assertSame('1', $this->stripe->created[0]['metadata']['listen_trial']);
        $this->assertSame([], $this->stripe->canceled);

        $trial = BillingTrial::query()->firstOrFail();
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->status);
        $this->assertFalse($trial->is_admin_test);
        $this->assertSame('4242', $trial->card_last4);
        $this->assertSame('fp_visa_4242', $trial->card_fingerprint);
        $this->assertTrue($trial->off_session_ready);
        $this->assertSame(500000, $trial->chars_limit);
        $this->assertSame(5000, $trial->max_chars_per_request);
        $this->assertSame(120, $trial->minutes_limit);
        $this->assertSame(60, $trial->premium_minutes_limit);
        $this->assertNull($trial->paid_at);
        $this->assertTrue($trial->trial_ends_at->greaterThan(now()->addDays(6)));

        $this->assertSame('creator', app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)?->slug);
        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_creator_month',
        ]);
        $this->assertDatabaseHas('saved_payment_methods', [
            'user_id' => $user->id,
            'last4' => '4242',
            'purpose' => SavedPaymentMethod::PURPOSE_TRIAL,
            'off_session_ready' => true,
            'is_default' => true,
        ]);
    }

    public function test_unverified_expired_and_failed_cards_do_not_start_a_trial(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);

        $this->actingAs($user);
        $setup = $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertOk();
        $intentId = (string) $setup->json('setup_intent_id');
        $this->stripe->putCard([
            'id' => 'pm_unverified',
            'customer' => $user->fresh()->stripe_id,
        ]);

        $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $intentId,
        ])->assertStatus(422);

        $this->stripe->markSucceeded($intentId, 'pm_unverified');
        $this->stripe->cards['pm_unverified']['cvc_check'] = 'fail';
        $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $intentId,
        ])->assertStatus(422);

        $this->stripe->cards['pm_unverified']['cvc_check'] = 'pass';
        $this->stripe->cards['pm_unverified']['exp_year'] = 2020;
        $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $intentId,
        ])->assertStatus(422);

        $this->stripe->cards['pm_unverified']['exp_year'] = (int) now()->addYears(2)->year;
        $this->stripe->subscriptionStatus = 'incomplete';
        $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $intentId,
        ])->assertStatus(422);

        $this->assertSame([], BillingTrial::query()->where('status', BillingTrial::STATUS_TRIALING)->pluck('id')->all());
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)?->slug);
        $this->assertNotEmpty($this->stripe->canceled);
        $this->assertSame($this->stripe->created[0]['id'], $this->stripe->canceled[0]);
    }

    public function test_yearly_and_non_creator_plans_never_open_a_setup_intent(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'yearly',
        ])->assertStatus(422);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'starter',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame([], $this->stripe->setupIntents);
        $this->assertSame(0, BillingTrial::query()->count());
    }

    public function test_same_person_cannot_take_a_second_trial_with_another_card(): void
    {
        $first = User::factory()->create([
            'email' => 'first@example.com',
            'phone_number' => '+1 555 0100',
            'registration_device_id' => 'device-shared',
            'subscription_status_checked_at' => now(),
        ]);
        $this->startTrial($first, 'pm_visa_a', '4242', 'fp_card_a')->assertOk();

        $sameAccount = $first->fresh();
        $sameAccount->forceFill(['subscription_status_checked_at' => now()])->save();
        $this->actingAs($sameAccount);
        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $otherCard = User::factory()->create([
            'email' => 'other@example.com',
            'phone_number' => '+1 555 0199',
            'registration_device_id' => 'device-other',
            'subscription_status_checked_at' => now(),
        ]);
        $blocked = $this->startTrial($otherCard, 'pm_visa_b', '5556', 'fp_card_a');
        $blocked->assertStatus(422);
        $this->assertSame(1, count($this->stripe->created));
        $this->assertDatabaseHas('billing_trials', [
            'user_id' => $otherCard->id,
            'status' => BillingTrial::STATUS_BLOCKED,
            'blocked_reason' => 'already_used_card',
            'card_last4' => '5556',
        ]);

        $sameDevice = User::factory()->create([
            'email' => 'device@example.com',
            'registration_device_id' => 'device-shared',
            'subscription_status_checked_at' => now(),
        ]);
        $this->actingAs($sameDevice);
        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);
        $this->assertDatabaseHas('billing_trials', [
            'user_id' => $sameDevice->id,
            'status' => BillingTrial::STATUS_BLOCKED,
            'blocked_reason' => 'already_used_device',
        ]);

        $sameEmail = User::factory()->create([
            'email' => 'FIRST@example.com',
            'subscription_status_checked_at' => now(),
        ]);
        $this->actingAs($sameEmail);
        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);
        $this->assertDatabaseHas('billing_trials', [
            'user_id' => $sameEmail->id,
            'blocked_reason' => 'already_used_email',
        ]);
    }

    public function test_admin_can_repeat_the_trial_with_any_test_visa_without_blocking_customers(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'subscription_status_checked_at' => now(),
        ]);

        $this->startTrial($admin, 'pm_test_4242', '4242', 'fp_test_4242')->assertOk();
        $firstSubscription = $this->stripe->created[0]['id'];

        $admin->forceFill(['subscription_status_checked_at' => now()])->save();
        $this->startTrial($admin, 'pm_test_5556', '5556', 'fp_test_5556')->assertOk();

        $this->assertCount(2, $this->stripe->created);
        $this->assertSame([$firstSubscription], $this->stripe->canceled);
        $this->assertSame(1, BillingTrial::query()->where('status', BillingTrial::STATUS_TRIALING)->where('is_admin_test', true)->count());
        $this->assertDatabaseHas('billing_trials', [
            'user_id' => $admin->id,
            'status' => BillingTrial::STATUS_CANCELED,
            'blocked_reason' => 'replaced_by_admin_retest',
            'card_last4' => '4242',
        ]);
        $this->assertSame(2, SavedPaymentMethod::query()->where('user_id', $admin->id)->count());

        $customer = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->startTrial($customer, 'pm_customer_4242', '4242', 'fp_test_4242')->assertOk();
        $this->assertSame(3, count($this->stripe->created));
    }

    public function test_confirming_the_same_setup_intent_twice_creates_one_subscription(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);
        $setup = $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertOk();
        $intentId = (string) $setup->json('setup_intent_id');
        $this->stripe->putCard([
            'id' => 'pm_once',
            'customer' => $user->fresh()->stripe_id,
            'fingerprint' => 'fp_once',
        ]);
        $this->stripe->markSucceeded($intentId, 'pm_once');
        $user->forceFill(['subscription_status_checked_at' => now()])->save();

        $this->postJsonLocalizedRoute('billing.trial.confirm', ['setup_intent_id' => $intentId])->assertOk();
        $this->postJsonLocalizedRoute('billing.trial.confirm', ['setup_intent_id' => $intentId])->assertOk();

        $this->assertCount(1, $this->stripe->created);
        $this->assertSame(1, BillingTrial::query()->where('status', BillingTrial::STATUS_TRIALING)->count());
    }

    public function test_trial_usage_is_capped_at_starter_defaults_while_features_stay_on_creator(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->startTrial($user, 'pm_cap', '4242', 'fp_cap')->assertOk();

        $active = app(PlanAccessService::class)->resolveActivePlanForUserId($user->id);
        $quota = app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id, $active);

        $this->assertSame('creator', $active?->slug);
        $this->assertSame(500000, PlanUsageGuard::planChars($quota));
        $this->assertSame(5000, PlanUsageGuard::maxCharsPerRequest($quota));
        $this->assertFalse(PlanUsageGuard::allowsOverage($quota));

        $counter = UsageCounter::create([
            'user_id' => $user->id,
            'period_ym' => now()->format('Y-m'),
            'total_chars' => 500000,
            'total_minutes' => 0,
            'total_requests' => 1,
        ]);

        try {
            PlanUsageGuard::assertWithinLimits($quota, $counter, ['chars' => 1, 'minutes' => 1]);
            $this->fail('Starter character cap was not enforced.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }

        $counter->forceFill(['total_chars' => 100, 'total_minutes' => 120])->save();
        try {
            PlanUsageGuard::assertWithinLimits($quota, $counter, ['chars' => 10, 'minutes' => 1]);
            $this->fail('Starter minute cap was not enforced.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_zero_dollar_invoice_does_not_count_as_paid_and_a_real_charge_does(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_outcome']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_outcome',
            'stripe_subscription_id' => 'sub_outcome',
            'amount_cents' => 2200,
            'started_at' => now()->subDays(7),
            'trial_ends_at' => now(),
        ]);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });

        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->once()->andReturnNull();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_zero',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_zero',
                    'customer' => 'cus_outcome',
                    'subscription' => 'sub_outcome',
                    'amount_paid' => 0,
                    'currency' => 'usd',
                ],
            ],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_paid',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_paid',
                    'customer' => 'cus_outcome',
                    'subscription' => 'sub_outcome',
                    'amount_paid' => 2200,
                    'currency' => 'usd',
                ],
            ],
        ])->assertOk();

        $paid = $trial->fresh();
        $this->assertSame(BillingTrial::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame(2200, $paid->amount_paid_cents);
        $this->assertSame(100.0, BillingTrial::outcomeCounts()['conversion_rate']);
    }

    public function test_paid_invoice_without_a_top_level_subscription_id_still_converts_the_trial(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_basil']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_basil',
            'stripe_subscription_id' => 'sub_basil',
            'amount_cents' => 2200,
            'started_at' => now()->subDays(7),
            'trial_ends_at' => now(),
        ]);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });

        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->once()->andReturnNull();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_basil_paid',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_basil',
                    'customer' => 'cus_basil',
                    'amount_paid' => 2200,
                    'currency' => 'usd',
                    'parent' => [
                        'type' => 'subscription_details',
                        'subscription_details' => [
                            'subscription' => 'sub_basil',
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $paid = $trial->fresh();
        $this->assertSame(BillingTrial::STATUS_PAID, $paid->status);
        $this->assertSame(2200, $paid->amount_paid_cents);
    }

    public function test_cancel_and_failed_charge_are_separate_outcomes(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_end']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $canceled = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_cancel',
            'amount_cents' => 2200,
            'trial_ends_at' => now()->addDays(3),
        ]);
        $failed = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_fail',
            'amount_cents' => 2200,
            'trial_ends_at' => now(),
        ]);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_cancel',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel',
                    'customer' => 'cus_end',
                    'status' => 'trialing',
                    'cancel_at_period_end' => true,
                ],
            ],
        ])->assertOk();

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_fail',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_fail',
                    'customer' => 'cus_end',
                    'subscription' => 'sub_fail',
                    'amount_due' => 2200,
                    'currency' => 'usd',
                ],
            ],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_CANCELED, $canceled->fresh()->status);
        $this->assertNotNull($canceled->fresh()->canceled_at);
        $this->assertSame(BillingTrial::STATUS_CHARGE_FAILED, $failed->fresh()->status);
        $this->assertNotNull($failed->fresh()->charge_failed_at);

        $counts = BillingTrial::outcomeCounts();
        $this->assertSame(1, $counts['canceled']);
        $this->assertSame(1, $counts['charge_failed']);
        $this->assertSame(0.0, $counts['conversion_rate']);
    }

    public function test_an_active_subscription_update_without_a_charge_does_not_count_as_paid(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_active_unpaid']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_active_unpaid',
            'amount_cents' => 2200,
            'trial_ends_at' => now(),
        ]);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_active_unpaid',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_active_unpaid',
                    'customer' => 'cus_active_unpaid',
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                ],
            ],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);
        $this->assertNull($trial->fresh()->paid_at);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_active_then_failed',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_active_then_failed',
                    'customer' => 'cus_active_unpaid',
                    'subscription' => 'sub_active_unpaid',
                    'amount_due' => 2200,
                    'currency' => 'usd',
                ],
            ],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_CHARGE_FAILED, $trial->fresh()->status);
    }

    public function test_a_canceled_billing_subscription_can_be_replaced_by_a_trial(): void
    {
        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_old_canceled',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_old',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
        ]);

        $response = $this->startTrial($user, 'pm_visa_4242', '4242', 'fp_after_cancel');

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([], $this->stripe->canceled);
        $this->assertSame(1, BillingSubscription::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'stripe_status' => 'trialing',
        ]);
        $this->assertNotSame('sub_old_canceled', BillingSubscription::query()->where('user_id', $user->id)->value('stripe_id'));
    }

    public function test_existing_pay_controller_is_not_wired_to_the_trial(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/BillingController.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('trialDays', $source);
        $this->assertStringNotContainsString('TrialSubscriptionService', $source);
        $this->assertStringNotContainsString('createTrialSubscription', $source);

        $billing = file_get_contents(resource_path('views/billing.blade.php'));
        $this->assertIsString($billing);
        $trialHandler = strstr($billing, "trialBtn?.addEventListener", true);
        $this->assertNotFalse(strpos($billing, 'trackPurchaseConversion'));
        $trialBlock = substr($billing, (int) strpos($billing, 'trialBtn?.addEventListener'));
        $trialBlock = substr($trialBlock, 0, (int) strpos($trialBlock, "document.getElementById('billingForm')"));
        $this->assertStringNotContainsString('trackPurchaseConversion', $trialBlock);
        $this->assertNotFalse($trialHandler);
    }

    public function test_admin_with_a_real_paid_subscription_cannot_overwrite_it_with_a_test_trial(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'stripe_id' => 'cus_admin_paid',
            'subscription_status_checked_at' => now(),
        ]);
        BillingSubscription::query()->create([
            'user_id' => $admin->id,
            'type' => 'default',
            'stripe_id' => 'sub_real_paid',
            'stripe_status' => 'active',
            'stripe_price' => 'price_creator_month',
            'quantity' => 1,
        ]);

        $this->actingAs($admin);
        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame([], $this->stripe->created);
        $this->assertDatabaseHas('billing_subscriptions', [
            'stripe_id' => 'sub_real_paid',
            'stripe_status' => 'active',
        ]);
    }

    private function startTrial(User $user, string $paymentMethodId, string $last4, string $fingerprint): \Illuminate\Testing\TestResponse
    {
        $user->forceFill(['subscription_status_checked_at' => now()])->save();
        $this->actingAs($user);

        $setup = $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ]);
        $setup->assertOk();

        $intentId = (string) $setup->json('setup_intent_id');
        $this->stripe->putCard([
            'id' => $paymentMethodId,
            'customer' => $user->fresh()->stripe_id,
            'last4' => $last4,
            'fingerprint' => $fingerprint,
            'brand' => 'visa',
        ]);
        $this->stripe->markSucceeded($intentId, $paymentMethodId);
        $user->forceFill(['subscription_status_checked_at' => now()])->save();

        return $this->postJsonLocalizedRoute('billing.trial.confirm', [
            'setup_intent_id' => $intentId,
        ]);
    }
}
