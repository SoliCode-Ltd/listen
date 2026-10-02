<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

class ManualPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_request_is_created_and_plan_stays_inactive_until_approval(): void
    {
        [$free, $creator, $user] = $this->seedBillingFixture();

        $response = $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->actingAs($user)
            ->withHeaders(['CF-IPCountry' => 'EG'])
            ->post($this->localizedRoute('billing.manual.confirm'), [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('manual_payment_requests', [
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'billing_cycle' => 'monthly',
            'payment_method' => 'instapay',
            'status' => ManualPaymentRequest::STATUS_PENDING,
            'expected_amount_minor' => 99500, // EGP is rounded up to whole pounds (994.65 -> 995)
            'currency' => 'EGP',
        ]);

        $activeSubscription = $user->planSubscriptions()->latest('id')->first();
        $this->assertNotNull($activeSubscription);
        $this->assertSame($free->id, $activeSubscription->plan_id);
        $this->assertSame('active', $activeSubscription->status);
        $this->assertNotSame($creator->id, $activeSubscription->plan_id);
    }

    public function test_duplicate_pending_manual_requests_are_blocked(): void
    {
        [, $creator, $user] = $this->seedBillingFixture();

        /** @var ManualPaymentService $service */
        $service = app(ManualPaymentService::class);
        $service->createRequest(
            $user,
            $creator,
            'monthly',
            'instapay',
            ['amount_minor' => 49900, 'currency' => 'EGP', 'country_code' => 'EG'],
            ['country_code' => 'EG', 'lines' => ['0123456789']],
        );

        $response = $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->actingAs($user)
            ->withHeaders(['CF-IPCountry' => 'EG'])
            ->from($this->localizedRoute('billing.manual.instructions', [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]))
            ->post($this->localizedRoute('billing.manual.confirm'), [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]);

        $response->assertRedirect();
        $this->assertSame(1, ManualPaymentRequest::query()->count());
    }

    public function test_approving_manual_payment_assigns_plan_and_sets_manual_override(): void
    {
        [, $creator, $user] = $this->seedBillingFixture();

        /** @var ManualPaymentService $service */
        $service = app(ManualPaymentService::class);
        $request = $service->createRequest(
            $user,
            $creator,
            'yearly',
            'instapay',
            ['amount_minor' => 499900, 'currency' => 'EGP', 'country_code' => 'EG'],
            ['country_code' => 'EG', 'lines' => ['0123456789']],
        );

        $reviewer = User::factory()->create(['is_admin' => true]);
        $service->approve($request, $reviewer, 'Verified in admin panel.');

        $request->refresh();
        $subscription = $user->planSubscriptions()->latest('id')->first();

        $this->assertNotNull($subscription);
        $this->assertSame($creator->id, $subscription->plan_id);
        $this->assertSame('manual_override', $subscription->billing_payment_state);
        $this->assertSame(ManualPaymentRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($reviewer->id, $request->reviewed_by);
    }

    public function test_active_stripe_subscription_blocks_manual_payment_request(): void
    {
        [, $creator, $user] = $this->seedBillingFixture();

        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_active_manual_block',
            'stripe_status' => 'active',
            'stripe_price' => 'price_live_123',
            'quantity' => 1,
        ]);

        $response = $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->actingAs($user)
            ->withHeaders(['CF-IPCountry' => 'EG'])
            ->from($this->localizedRoute('billing.manual.instructions', [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]))
            ->post($this->localizedRoute('billing.manual.confirm'), [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]);

        $response->assertRedirect();
        $this->assertSame(0, ManualPaymentRequest::query()->count());
    }

    public function test_manual_payment_confirmation_supports_ajax_without_page_reload(): void
    {
        [, $creator, $user] = $this->seedBillingFixture();

        $response = $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->actingAs($user)
            ->withHeaders([
                'CF-IPCountry' => 'EG',
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post($this->localizedRoute('billing.manual.confirm'), [
                'plan' => $creator->slug,
                'billing_cycle' => 'monthly',
                'payment_method' => 'instapay',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => __('ui.billing.manual_request_received'),
            ]);

        $this->assertDatabaseHas('manual_payment_requests', [
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'billing_cycle' => 'monthly',
            'payment_method' => 'instapay',
            'status' => ManualPaymentRequest::STATUS_PENDING,
        ]);
    }

    public function test_deleting_approved_manual_request_rolls_user_back_to_previous_plan(): void
    {
        [$free, $creator, $user] = $this->seedBillingFixture();

        $starter = Plan::query()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 900,
            'monthly_minutes' => 1200,
            'monthly_chars' => 120000,
            'monthly_requests' => 120,
            'max_chars_per_request' => 1200,
            'is_active' => true,
        ]);

        app(PlanAccessService::class)->assignPlan($user, $starter, 'active', now()->addMonth());

        /** @var ManualPaymentService $service */
        $service = app(ManualPaymentService::class);
        $request = $service->createRequest(
            $user,
            $creator,
            'monthly',
            'instapay',
            ['amount_minor' => 49900, 'currency' => 'EGP', 'country_code' => 'EG'],
            ['country_code' => 'EG', 'lines' => ['0123456789']],
        );

        $reviewer = User::factory()->create(['is_admin' => true]);
        $service->approve($request, $reviewer, 'Approved for rollback test.');

        $requestId = (int) $request->id;
        $result = $service->deleteRequest($request);

        $this->assertTrue((bool) ($result['rolled_back'] ?? false));
        $this->assertFalse((bool) ($result['used_fallback_plan'] ?? true));
        $this->assertDatabaseMissing('manual_payment_requests', ['id' => $requestId]);

        $activeSubscription = $user->planSubscriptions()->latest('id')->first();
        $this->assertNotNull($activeSubscription);
        $this->assertSame($starter->id, $activeSubscription->plan_id);
        $this->assertSame('ok', $activeSubscription->billing_payment_state);
        $this->assertNotSame($free->id, $activeSubscription->plan_id);
    }

    /**
     * @return array{0:Plan,1:Plan,2:User}
     */
    private function seedBillingFixture(): array
    {
        $free = Plan::query()->create([
            'slug' => 'free',
            'name' => 'FREE',
            'price_cents' => 0,
            'monthly_minutes' => 300,
            'monthly_chars' => 30000,
            'monthly_requests' => 60,
            'max_chars_per_request' => 600,
            'is_active' => true,
        ]);

        $creator = Plan::query()->create([
            'slug' => 'creator',
            'name' => 'Creator',
            'price_cents' => 1900,
            'monthly_minutes' => 5000,
            'monthly_chars' => 500000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'is_active' => true,
        ]);

        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('manual_vodafone_cash_enabled', '1', 'billing');
        SiteSetting::set('manual_vodafone_cash_number', '01098765432', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');

        $user = User::factory()->create();

        return [$free, $creator, $user];
    }
}
