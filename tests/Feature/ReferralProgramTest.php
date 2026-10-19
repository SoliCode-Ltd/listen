<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureReferralVisit;
use App\Models\DemoRewardTransaction;
use App\Models\Plan;
use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferralProfile;
use App\Services\PlanAccessService;
use App\Services\Referral\DemoRewardLedgerService;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_profile_assigns_unique_referral_code(): void
    {
        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $profileA = $svc->ensureProfile($userA);
        $profileB = $svc->ensureProfile($userB);

        $this->assertNotEmpty($profileA->referral_code);
        $this->assertNotEmpty($profileB->referral_code);
        $this->assertNotSame($profileA->referral_code, $profileB->referral_code);

        $this->assertSame($profileA->id, $svc->ensureProfile($userA)->id);
    }

    public function test_attach_referrer_on_register_creates_pending_conversion_and_binds_profile(): void
    {
        $campaign = $this->seedCampaign();
        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);

        $referrer = User::factory()->create();
        $referrerProfile = $svc->ensureProfile($referrer);
        $newUser = User::factory()->create();

        $request = Request::create('/demo', 'GET');
        $bound = $svc->attachReferrerOnRegister($newUser, $referrerProfile->referral_code, $request);

        $this->assertNotNull($bound);
        $this->assertSame((int) $referrer->id, (int) $bound->referred_by_user_id);

        $conversion = ReferralConversion::query()
            ->where('campaign_id', $campaign->id)
            ->where('referred_user_id', $newUser->id)
            ->first();

        $this->assertNotNull($conversion);
        $this->assertSame(ReferralConversion::STATUS_PENDING, $conversion->status);
        $this->assertSame($referrer->id, $conversion->referrer_user_id);
        $this->assertSame((int) $campaign->reward_chars, (int) $conversion->reward_chars);
    }

    public function test_self_referral_is_ignored(): void
    {
        $this->seedCampaign();
        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);

        $user = User::factory()->create();
        $profile = $svc->ensureProfile($user);

        $request = Request::create('/demo', 'GET');
        $bound = $svc->attachReferrerOnRegister($user, $profile->referral_code, $request);

        $this->assertNull($bound->referred_by_user_id);
        $this->assertDatabaseMissing('referral_conversions', [
            'referred_user_id' => $user->id,
        ]);
    }

    public function test_reward_ledger_balance_grant_consume_revoke(): void
    {
        /** @var DemoRewardLedgerService $ledger */
        $ledger = app(DemoRewardLedgerService::class);
        $user = User::factory()->create();

        $this->assertSame(0, $ledger->balanceFor($user));

        $ledger->grant($user, 1000);
        $this->assertSame(1000, $ledger->balanceFor($user));
        $this->assertSame(1000, $ledger->totalGrantedFor($user));

        $consumed = $ledger->consume($user, 300);
        $this->assertSame(300, $consumed);
        $this->assertSame(700, $ledger->balanceFor($user));

        // Over-consume is clamped.
        $consumed2 = $ledger->consume($user, 10000);
        $this->assertSame(700, $consumed2);
        $this->assertSame(0, $ledger->balanceFor($user));

        // Consuming against an empty balance returns 0, no transaction.
        $this->assertSame(0, $ledger->consume($user, 100));

        $ledger->grant($user, 500);
        $ledger->revoke($user, 200);
        $this->assertSame(300, $ledger->balanceFor($user));

        $kinds = DemoRewardTransaction::query()->where('user_id', $user->id)->pluck('kind')->all();
        $this->assertContains(DemoRewardTransaction::KIND_GRANT, $kinds);
        $this->assertContains(DemoRewardTransaction::KIND_CONSUME, $kinds);
        $this->assertContains(DemoRewardTransaction::KIND_REVOKE, $kinds);
    }

    public function test_paid_plan_assignment_promotes_conversion_and_grants_reward(): void
    {
        $campaign = $this->seedCampaign();
        $this->seedFreePlan();
        $paidPlan = $this->seedPaidPlan();

        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);
        // An established referrer: signups minutes apart are flagged as tandem abuse.
        $referrer = User::factory()->create(['created_at' => now()->subDays(2)]);
        $referrerProfile = $svc->ensureProfile($referrer);

        $referred = User::factory()->create();
        $svc->attachReferrerOnRegister($referred, $referrerProfile->referral_code, Request::create('/demo', 'GET'));

        $this->assertDatabaseHas('referral_conversions', [
            'referred_user_id' => $referred->id,
            'status' => ReferralConversion::STATUS_PENDING,
        ]);

        // Simulate paid plan activation via PlanAccessService.
        /** @var PlanAccessService $planSvc */
        $planSvc = app(PlanAccessService::class);
        $planSvc->assignPlan($referred, $paidPlan);

        $conv = ReferralConversion::query()
            ->where('referred_user_id', $referred->id)
            ->first();
        $this->assertNotNull($conv);
        $this->assertSame(ReferralConversion::STATUS_APPROVED, $conv->status);

        /** @var DemoRewardLedgerService $ledger */
        $ledger = app(DemoRewardLedgerService::class);
        $this->assertSame((int) $campaign->reward_chars, $ledger->balanceFor($referrer));
    }

    public function test_rapid_tandem_signup_is_flagged_and_not_rewarded(): void
    {
        $campaign = $this->seedCampaign();
        $this->seedFreePlan();
        $paidPlan = $this->seedPaidPlan();

        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);
        // Referrer and referred account created within minutes of each other.
        $referrer = User::factory()->create(['created_at' => now()->subMinutes(2)]);
        $referrerProfile = $svc->ensureProfile($referrer);

        $referred = User::factory()->create();
        $svc->attachReferrerOnRegister($referred, $referrerProfile->referral_code, Request::create('/demo', 'GET'));

        $this->assertDatabaseHas('referral_conversions', [
            'referred_user_id' => $referred->id,
            'status' => ReferralConversion::STATUS_PENDING,
        ]);

        // Simulate paid plan activation via PlanAccessService.
        /** @var PlanAccessService $planSvc */
        $planSvc = app(PlanAccessService::class);
        $planSvc->assignPlan($referred, $paidPlan);

        $conv = ReferralConversion::query()
            ->where('referred_user_id', $referred->id)
            ->first();
        $this->assertNotNull($conv);
        $this->assertSame(ReferralConversion::STATUS_FLAGGED, $conv->status);
        $this->assertContains('rapid_tandem_signup', (array) $conv->fraud_indicators);

        /** @var DemoRewardLedgerService $ledger */
        $ledger = app(DemoRewardLedgerService::class);
        $this->assertSame(0, $ledger->balanceFor($referrer));
    }

    public function test_free_plan_assignment_does_not_grant_reward(): void
    {
        $this->seedCampaign();
        $freePlan = $this->seedFreePlan();

        /** @var ReferralService $svc */
        $svc = app(ReferralService::class);
        $referrer = User::factory()->create();
        $referrerProfile = $svc->ensureProfile($referrer);

        $referred = User::factory()->create();
        $svc->attachReferrerOnRegister($referred, $referrerProfile->referral_code, Request::create('/demo', 'GET'));

        /** @var PlanAccessService $planSvc */
        $planSvc = app(PlanAccessService::class);
        $planSvc->assignPlan($referred, $freePlan);

        $conv = ReferralConversion::query()
            ->where('referred_user_id', $referred->id)
            ->first();
        $this->assertSame(ReferralConversion::STATUS_PENDING, $conv->status);

        /** @var DemoRewardLedgerService $ledger */
        $ledger = app(DemoRewardLedgerService::class);
        $this->assertSame(0, $ledger->balanceFor($referrer));
    }

    public function test_without_a_running_campaign_the_demo_page_promises_no_referral_reward(): void
    {
        ReferralCampaign::query()->update(['is_active' => false]);
        ReferralCampaign::forgetCache();
        $member = User::factory()->create();

        $this->actingAs($member)->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertDontSee('id="referralModal"', false)
            ->assertDontSee('id="referralGiftFab"', false);

        // With one running, the popup gives its own reward and says what earns it.
        $this->seedCampaign(ReferralCampaign::QUALIFICATION_PAID_PLAN, 2500);
        $this->actingAs($member)->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertSee('id="referralModal"', false)
            ->assertSee(str_replace('{reward_amount}', '2,500', __('ui.referral.popup_description_paid_plan')))
            ->assertDontSee(__('ui.referral.popup_description_signup'));

        app()->setLocale('ar');
        $this->seedCampaign(ReferralCampaign::QUALIFICATION_SIGNUP, 2500);
        $this->actingAs($member)->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertSee(str_replace('{reward_amount}', '2,500', __('ui.referral.popup_description_signup', [], 'ar')));
    }

    public function test_with_a_signup_campaign_signing_up_through_a_referral_link_rewards_the_referrer(): void
    {
        $campaign = $this->seedCampaign(ReferralCampaign::QUALIFICATION_SIGNUP);
        $this->seedFreePlan();
        // An established referrer: signups minutes apart are flagged as tandem abuse.
        $referrer = User::factory()->create(['created_at' => now()->subDays(2)]);
        $code = app(ReferralService::class)->ensureProfile($referrer)->referral_code;

        // The friend opens the link (the demo asks them to sign up first), then signs up.
        $visit = $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('demo.page').'?ref='.$code);
        $cookie = $visit->getCookie(CaptureReferralVisit::COOKIE_NAME);
        $this->assertNotNull($cookie);

        $this->withCookie(CaptureReferralVisit::COOKIE_NAME, (string) $cookie->getValue())
            ->postLocalizedRoute('register.submit', [
                'name' => 'Friend',
                'email' => 'friend@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertRedirect();

        $friend = User::query()->where('email', 'friend@example.test')->firstOrFail();
        $conversion = ReferralConversion::query()->where('referred_user_id', $friend->id)->firstOrFail();
        $this->assertSame((int) $referrer->id, (int) $conversion->referrer_user_id);
        $this->assertSame(ReferralConversion::STATUS_APPROVED, $conversion->status);
        $this->assertSame((int) $campaign->reward_chars, app(DemoRewardLedgerService::class)->balanceFor($referrer));
    }

    private function seedCampaign(string $step = ReferralCampaign::QUALIFICATION_PAID_PLAN, int $reward = 1000): ReferralCampaign
    {
        ReferralCampaign::forgetCache();

        // The migration seeds the 'default' campaign; configure it for the test.
        return ReferralCampaign::query()->updateOrCreate(['slug' => 'default'], [
            'name' => 'Default',
            'is_active' => true,
            'qualification_step' => $step,
            'reward_chars' => $reward,
            'visit_window_days' => 30,
            'max_conversions_per_referrer_per_day' => 100,
            'max_conversions_per_referrer_total' => 10000,
        ]);
    }

    private function seedFreePlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'monthly_minutes' => 10,
                'monthly_chars' => 10000,
                'monthly_requests' => 60,
                'max_chars_per_request' => 1200,
                'voice_tier' => 'standard',
                'tts_provider_policy' => 'google_cloud',
                'dialogue_enabled' => false,
                'custom_prompt_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    private function seedPaidPlan(): Plan
    {
        return Plan::query()->firstOrCreate(
            ['slug' => 'starter-monthly'],
            [
                'name' => 'Starter',
                'price_cents' => 1900,
                'monthly_minutes' => 120,
                'monthly_chars' => 200000,
                'monthly_requests' => 600,
                'max_chars_per_request' => 4000,
                'voice_tier' => 'hd',
                'tts_provider_policy' => 'google_cloud',
                'dialogue_enabled' => true,
                'custom_prompt_enabled' => false,
                'is_active' => true,
            ]
        );
    }
}
