<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SavedPaymentMethod;
use App\Models\SiteSetting;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use App\Support\PlanUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        Cache::flush();

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

    public function test_free_and_unpriced_plans_and_cycles_never_open_a_setup_intent(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'yearly',
        ])->assertStatus(422);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'free',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'starter',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame([], $this->stripe->setupIntents);
        $this->assertSame(0, BillingTrial::query()->count());

        Plan::query()->where('slug', 'starter')->update([
            'stripe_price_monthly' => 'price_starter_month',
        ]);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'starter',
            'billing_cycle' => 'monthly',
        ])->assertOk();

        $this->assertSame('starter', $this->stripe->setupIntents['seti_1']['metadata']['listen_plan_slug']);
    }

    public function test_turning_the_trial_off_leaves_only_the_normal_checkout(): void
    {
        SiteSetting::set('billing_trial_enabled', '0', 'billing');
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);

        $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => 'creator',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame([], $this->stripe->setupIntents);
        $this->assertFalse(app(\App\Services\Billing\TrialEligibilityService::class)->offerFor($user)['available']);
    }

    public function test_any_paid_monthly_plan_keeps_its_features_and_uses_the_filament_usage_cap(): void
    {
        Plan::query()->where('slug', 'starter')->update([
            'stripe_price_monthly' => 'price_starter_month',
        ]);
        SiteSetting::set('billing_trial_quota_plan_slug', 'free', 'billing');
        SiteSetting::set('billing_trial_days', '3', 'billing');

        $creatorUser = User::factory()->create(['subscription_status_checked_at' => now()]);
        $creatorResponse = $this->startTrial($creatorUser, 'pm_visa_creator', '4242', 'fp_creator_choice');
        $creatorResponse->assertOk()
            ->assertJsonPath('plan', 'creator')
            ->assertJsonPath('amount_cents', 2200);

        $creatorTrial = BillingTrial::query()->where('user_id', $creatorUser->id)->firstOrFail();
        $this->assertSame('creator', $creatorTrial->plan?->slug);
        $this->assertSame('free', $creatorTrial->quotaPlan?->slug);
        $this->assertSame(10000, $creatorTrial->chars_limit);
        $this->assertSame('price_creator_month', $this->stripe->created[0]['price_id']);
        $this->assertTrue($creatorTrial->trial_ends_at->greaterThan(now()->addDays(2)));
        $this->assertTrue($creatorTrial->trial_ends_at->lessThan(now()->addDays(4)));
        $this->assertSame('creator', app(PlanAccessService::class)->resolveActivePlanForUserId($creatorUser->id)?->slug);
        $this->assertSame(10000, (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId($creatorUser->id)?->monthly_chars);

        $starterUser = User::factory()->create(['subscription_status_checked_at' => now()]);
        $starterResponse = $this->startTrial($starterUser, 'pm_visa_starter', '5556', 'fp_starter_choice', 'starter');
        $starterResponse->assertOk()
            ->assertJsonPath('plan', 'starter')
            ->assertJsonPath('amount_cents', 900);

        $starterTrial = BillingTrial::query()->where('user_id', $starterUser->id)->firstOrFail();
        $this->assertSame('starter', $starterTrial->plan?->slug);
        $this->assertSame('free', $starterTrial->quotaPlan?->slug);
        $this->assertSame(900, $starterTrial->amount_cents);
        $this->assertSame('price_starter_month', $this->stripe->created[1]['price_id']);
        $this->assertSame('starter', app(PlanAccessService::class)->resolveActivePlanForUserId($starterUser->id)?->slug);
        $this->assertSame(10000, (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId($starterUser->id)?->monthly_chars);
    }

    public function test_homepage_trial_button_follows_the_setting_and_the_visitor(): void
    {
        Plan::query()->where('slug', 'starter')->update([
            'stripe_price_monthly' => 'price_starter_month',
        ]);
        $billingUrl = route('billing.page', ['billing_cycle' => 'monthly', 'payment_method' => 'stripe']);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('class="btn btn-trial"', false)
            ->assertSee('Start 7-day free trial', false)
            ->assertSee(route('trial.start'), false)
            ->assertDontSee(__('ui.landing.create_free_account'), false);

        $this->getLocalizedRoute('trial.start')->assertRedirect(route('register'));
        $this->assertSame($billingUrl, session('url.intended'));

        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('class="trial-pill"', false)
            ->assertSee('7 days free', false)
            ->assertSee('on any paid plan, cancel anytime.', false)
            ->assertSee('href="'.e(route('trial.start', ['plan' => 'creator'])).'"', false)
            ->assertSee('data-yearly-href="'.e(route('register')).'"', false)
            ->assertDontSee('No charge today', false);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('trial.start').'?plan=creator')->assertRedirect(route('register'));
        $this->assertSame(
            route('billing.page', ['plan' => 'creator', 'billing_cycle' => 'monthly', 'payment_method' => 'stripe']),
            session('url.intended')
        );

        $this->actingAs($user)->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('data-monthly-href="'.e(route('trial.start', ['plan' => 'creator'])).'"', false)
            ->assertSee('data-yearly-href="'.e(route('billing.page', ['plan' => 'creator', 'billing_cycle' => 'yearly'])).'"', false)
            ->assertSee('data-yearly-label="'.__('ui.pricing.upgrade_now').'"', false);

        $this->actingAs($user)->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('class="btn btn-trial"', false)
            ->assertSee(__('ui.landing.go_to_dashboard'), false);

        $this->actingAs($user)->getLocalizedRoute('trial.start')->assertRedirect($billingUrl);

        $this->actingAs($user)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('id="trialBtn"', false)
            ->assertSee('id="checkoutChoice"', false)
            ->assertSee('Start with a 7-day free trial', false)
            ->assertSee('Pay now, no trial', false)
            ->assertSee('.btn[hidden]', false)
            ->assertSee('value="stripe" checked', false)
            ->assertSee('"enabled":true', false)
            ->assertSee('"plans":["starter","creator"]', false)
            ->assertSee('"plan_cycles":{"starter":["monthly"],"creator":["monthly"]}', false);

        $this->startTrial($user, 'pm_visa_home', '4242', 'fp_home')->assertOk();
        $this->actingAs($user->fresh())->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('class="btn btn-trial"', false)
            ->assertSee(__('ui.landing.browser_extension'), false);
    }

    public function test_turning_the_trial_off_hides_it_on_billing_and_the_homepage(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        SiteSetting::set('billing_trial_enabled', '0', 'billing');

        $this->actingAs($user)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertDontSee('id="trialBtn"', false)
            ->assertDontSee('id="checkoutChoice"', false)
            ->assertSee('value="stripe" checked', false)
            ->assertSee('"enabled":false', false)
            ->assertSee(__('ui.billing.confirm_and_pay'), false);

        $this->actingAs($user)->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('class="btn btn-trial"', false)
            ->assertSee(__('ui.landing.browser_extension'), false);

        auth()->logout();

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('class="btn btn-trial"', false)
            ->assertSee(__('ui.landing.create_free_account'), false);

        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertDontSee('class="trial-pill"', false)
            ->assertDontSee(route('trial.start'), false)
            ->assertDontSee('7 days free', false);
    }

    public function test_yearly_plans_get_the_same_trial_and_charge_the_yearly_price_after_it(): void
    {
        Plan::query()->where('slug', 'creator')->update(['stripe_price_yearly' => 'price_creator_year']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);

        $this->startTrial($user, 'pm_yearly', '4242', 'fp_yearly', 'creator', 'yearly')
            ->assertOk()
            ->assertJsonPath('plan', 'creator')
            ->assertJsonPath('amount_cents', $creator->getEffectiveYearlyPriceCents());

        $this->assertSame('yearly', $this->stripe->setupIntents['seti_1']['metadata']['listen_billing_cycle']);
        $this->assertSame('price_creator_year', $this->stripe->created[0]['price_id']);
        $this->assertSame('yearly', $this->stripe->created[0]['metadata']['listen_billing_cycle']);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $this->stripe->created[0]['trial_end'], 120);

        $trial = BillingTrial::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('yearly', $trial->billing_cycle);
        $this->assertSame(500000, $trial->chars_limit);
        $this->assertSame('creator', app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)?->slug);
        $this->assertSame(500000, (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id)?->monthly_chars);
        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_creator_year',
        ]);

        $second = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->startTrial($second, 'pm_yearly_again', '5556', 'fp_yearly')->assertStatus(422);
        $this->assertCount(1, $this->stripe->created);
    }

    public function test_pricing_offers_the_trial_on_yearly_plans_too(): void
    {
        Plan::query()->where('slug', 'creator')->update(['stripe_price_yearly' => 'price_creator_year']);
        $yearlyTrial = route('trial.start', ['plan' => 'creator', 'billing_cycle' => 'yearly']);

        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('data-monthly-href="'.e(route('trial.start', ['plan' => 'creator'])).'"', false)
            ->assertSee('data-yearly-href="'.e($yearlyTrial).'"', false)
            ->assertSee('data-yearly-label="Start 7-day free trial"', false);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('trial.start').'?plan=creator&billing_cycle=yearly')->assertRedirect(route('register'));
        $this->assertSame(
            route('billing.page', ['plan' => 'creator', 'billing_cycle' => 'yearly', 'payment_method' => 'stripe']),
            session('url.intended')
        );

        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user)->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('data-yearly-href="'.e($yearlyTrial).'"', false);
        $this->actingAs($user)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('"creator":["monthly","yearly"]', false);
    }

    public function test_admin_can_limit_the_trial_to_chosen_plans_and_cycles(): void
    {
        Plan::query()->where('slug', 'starter')->update(['stripe_price_monthly' => 'price_starter_month']);
        Plan::query()->where('slug', 'creator')->update(['stripe_price_yearly' => 'price_creator_year']);
        SiteSetting::set('billing_trial_plan_slugs', json_encode(['starter']), 'billing');

        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);
        $this->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'monthly'])->assertStatus(422);
        $this->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'yearly'])->assertStatus(422);
        $this->assertSame([], $this->stripe->setupIntents);

        $this->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('"plans":["starter"]', false)
            ->assertSee('"plan_cycles":{"starter":["monthly"]}', false);

        auth()->logout();
        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('on selected plans, cancel anytime.', false)
            ->assertSee(e(route('trial.start', ['plan' => 'starter'])), false)
            ->assertDontSee(e(route('trial.start', ['plan' => 'creator'])), false);

        SiteSetting::set('billing_trial_plan_slugs', json_encode(['starter', 'creator']), 'billing');
        SiteSetting::set('billing_trial_cycles', json_encode(['monthly']), 'billing');
        Cache::flush();
        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('on any paid plan, cancel anytime.', false)
            ->assertSee(e(route('trial.start', ['plan' => 'creator'])), false)
            ->assertDontSee(e(route('trial.start', ['plan' => 'creator', 'billing_cycle' => 'yearly'])), false);

        $this->actingAs($user);
        $this->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'yearly'])->assertStatus(422);
        $this->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'monthly'])->assertOk();

        SiteSetting::set('billing_trial_plan_slugs', json_encode([]), 'billing');
        Cache::flush();
        $this->assertFalse(app(\App\Services\Billing\TrialEligibilityService::class)->promoFor(null)['show']);
        auth()->logout();
        $this->getLocalizedRoute('pricing')->assertOk()->assertDontSee('class="trial-pill"', false);
        $this->getLocalizedRoute('home')->assertOk()->assertDontSee('class="btn btn-trial"', false);
    }

    public function test_a_plan_turned_off_after_setup_cannot_finish_the_trial(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user);
        $setup = $this->postJsonLocalizedRoute('billing.trial.setup', ['plan' => 'creator', 'billing_cycle' => 'monthly'])->assertOk();
        $intentId = (string) $setup->json('setup_intent_id');
        $this->stripe->putCard(['id' => 'pm_late_off', 'customer' => $user->fresh()->stripe_id, 'fingerprint' => 'fp_late_off']);
        $this->stripe->markSucceeded($intentId, 'pm_late_off');

        SiteSetting::set('billing_trial_plan_slugs', json_encode(['starter']), 'billing');
        Cache::flush();

        $this->postJsonLocalizedRoute('billing.trial.confirm', ['setup_intent_id' => $intentId])->assertStatus(422);
        $this->assertSame([], $this->stripe->created);
    }

    public function test_billing_settings_page_saves_the_trial_plans_and_cycles(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(\App\Filament\Pages\BillingSettings::class)
            ->assertFormSet(['trial_plan_slugs' => ['starter', 'creator'], 'trial_cycles' => ['monthly', 'yearly']])
            ->fillForm(['trial_plan_slugs' => ['creator'], 'trial_cycles' => ['monthly']])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        $eligibility = app(\App\Services\Billing\TrialEligibilityService::class);
        $this->assertSame(['creator'], $eligibility->allowedPlanSlugs());
        $this->assertSame(['monthly'], $eligibility->allowedCycles());
    }

    public function test_google_ads_click_is_kept_on_the_trial_and_exported_once_it_is_paid(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('pricing').'?gclid=Cj0KCQ-test_click')
            ->assertOk()
            ->assertCookie(\App\Http\Middleware\CaptureAdClickId::COOKIE, 'Cj0KCQ-test_click');

        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->withCredentials()->withCookie(\App\Http\Middleware\CaptureAdClickId::COOKIE, 'Cj0KCQ-test_click');
        $this->startTrial($user, 'pm_ads', '4242', 'fp_ads')->assertOk();

        $trial = BillingTrial::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Cj0KCQ-test_click', $trial->gclid);

        $export = app(\App\Services\Billing\TrialAdsConversionExport::class);
        $this->assertSame([], $export->rows());

        $trial->forceFill([
            'status' => BillingTrial::STATUS_PAID,
            'paid_at' => \Illuminate\Support\Carbon::parse('2026-09-20 10:00:00', 'UTC'),
            'amount_paid_cents' => 2200,
        ])->save();
        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $trial->plan_id,
            'status' => BillingTrial::STATUS_PAID,
            'is_admin_test' => true,
            'gclid' => 'admin_click',
            'amount_cents' => 2200,
            'paid_at' => now(),
        ]);

        $this->assertSame([
            ['Cj0KCQ-test_click', 'Trial converted', '2026-09-20 10:00:00+00:00', '22.00', 'USD'],
        ], $export->rows());
        $this->assertStringStartsWith("\"Google Click ID\",\"Conversion Name\",\"Conversion Time\",\"Conversion Value\",\"Conversion Currency\"\n", $export->csv());

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Livewire\Livewire::test(\App\Filament\Resources\BillingTrialResource\Pages\ListBillingTrials::class)
            ->callAction('exportGoogleAds')
            ->assertFileDownloaded('google-ads-trial-conversions-'.now()->format('Y-m-d').'.csv');
    }

    public function test_billing_page_sends_the_trial_conversion_only_when_it_is_configured(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($user)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('const gadsTrialConversionSendTo = ""', false);

        SiteSetting::set('google_ads_trial_conversion_send_to', 'AW-123/TrialLabel', 'seo');
        Cache::flush();
        $this->actingAs($user)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('const gadsTrialConversionSendTo = "AW-123\/TrialLabel"', false)
            ->assertSee('event_callback: resolve', false);
    }

    public function test_the_trial_cap_never_exceeds_the_plan_the_customer_picked(): void
    {
        Plan::query()->where('slug', 'starter')->update([
            'stripe_price_monthly' => 'price_starter_month',
        ]);
        SiteSetting::set('billing_trial_quota_plan_slug', 'creator', 'billing');

        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->startTrial($user, 'pm_small_plan', '4242', 'fp_small_plan', 'starter')->assertOk();

        $trial = BillingTrial::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('starter', $trial->quotaPlan?->slug);
        $this->assertSame(500000, $trial->chars_limit);
        $this->assertSame(500000, (int) app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id)?->monthly_chars);
    }

    public function test_the_cap_stays_until_the_first_charge_even_after_the_trial_date_passes(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_gap']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $starter = Plan::query()->where('slug', 'starter')->firstOrFail();
        app(PlanAccessService::class)->assignPlan($user, $creator, 'active', now()->addMonth());
        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'quota_plan_id' => $starter->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_customer_id' => 'cus_gap',
            'stripe_subscription_id' => 'sub_gap',
            'amount_cents' => 2200,
            'started_at' => now()->subDays(7),
            'trial_ends_at' => now()->subHour(),
        ]);

        $access = app(PlanAccessService::class);
        $this->assertSame(500000, (int) $access->resolveQuotaPlanForUserId($user->id)?->monthly_chars);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });
        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->andReturnNull();
        $referrals->shouldReceive('isFirstPaidPlanTransition')->andReturnFalse();
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_gap_paid',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_gap',
                'customer' => 'cus_gap',
                'subscription' => 'sub_gap',
                'amount_paid' => 2200,
                'currency' => 'usd',
            ]],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_PAID, $trial->fresh()->status);
        $this->assertSame(1500000, (int) $access->resolveQuotaPlanForUserId($user->id)?->monthly_chars);

        $trial->forceFill(['status' => BillingTrial::STATUS_TRIALING, 'paid_at' => null, 'trial_ends_at' => now()->subDays(4)])->save();
        $this->assertFalse($access->userHasUnpaidTrialWindow($user->id));
    }

    public function test_resuming_a_canceled_trial_puts_it_back_in_trial(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_resume']);
        $creator = Plan::query()->where('slug', 'creator')->firstOrFail();
        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'status' => BillingTrial::STATUS_CANCELED,
            'canceled_at' => now(),
            'stripe_subscription_id' => 'sub_resume',
            'amount_cents' => 2200,
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->mock(BillingSyncService::class, function ($mock) {
            $mock->shouldReceive('syncIfStale')->andReturnNull();
            $mock->shouldReceive('syncUser')->andReturnNull();
        });

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_resume',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_resume',
                'customer' => 'cus_resume',
                'status' => 'trialing',
                'cancel_at_period_end' => false,
            ]],
        ])->assertOk();

        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);
        $this->assertNull($trial->fresh()->canceled_at);
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

    private function startTrial(User $user, string $paymentMethodId, string $last4, string $fingerprint, string $plan = 'creator', string $cycle = 'monthly'): \Illuminate\Testing\TestResponse
    {
        $user->forceFill(['subscription_status_checked_at' => now()])->save();
        $this->actingAs($user);

        $setup = $this->postJsonLocalizedRoute('billing.trial.setup', [
            'plan' => $plan,
            'billing_cycle' => $cycle,
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
