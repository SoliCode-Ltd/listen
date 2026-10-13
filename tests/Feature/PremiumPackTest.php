<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\CharGift;
use App\Models\CharPackPurchase;
use App\Models\CharPackWallet;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\Billing\CharPackService;
use App\Services\Billing\StripePackGateway;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\FreeAllowance;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeStripePackGateway;
use Tests\TestCase;

/**
 * The one-time premium pack: buying it (card, InstaPay, Vodafone Cash),
 * spending it in the dashboard and the extension, and what happens when it
 * runs out or the member moves to a plan. Stripe and speech are faked.
 */
class PremiumPackTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $starter;

    private Plan $pack;

    private FakeStripePackGateway $stripe;

    /** @var list<string> */
    private array $spoken = [];

    private bool $speechFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('audio/pack-track.mp3', 'audio');
        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => '',
        ]);

        $this->free = Plan::factory()->create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_requests' => 50, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
        ]);
        $this->starter = Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'voice_tier' => 'hd',
        ]);
        // One-time packs are not sold today (the $2 offer is the Basic monthly
        // plan), but the code stays ready for one: these tests run it.
        $this->pack = Plan::factory()->create([
            'slug' => 'pack', 'name' => 'Premium Pack', 'kind' => Plan::KIND_PACK, 'pack_days' => 30,
            'price_cents' => 200, 'monthly_chars' => 20000, 'monthly_requests' => 0,
            'max_chars_per_request' => 5000, 'voice_tier' => 'hd', 'is_active' => true,
        ]);

        $this->stripe = new FakeStripePackGateway;
        $this->app->instance(StripePackGateway::class, $this->stripe);
        $this->fakeSpeech();
    }

    // ──────────────────────────────────────────────────────────────────
    //  The pack itself
    // ──────────────────────────────────────────────────────────────────

    public function test_the_migration_turns_the_unsold_pack_into_the_basic_monthly_plan(): void
    {
        $basic = Plan::query()->where('slug', 'basic')->firstOrFail();

        $this->assertFalse($basic->isPack());
        $this->assertFalse((bool) $basic->is_active, 'switched off until the owner turns it on');
        $this->assertSame(200, (int) $basic->price_cents);
        $this->assertSame(20000, (int) $basic->monthly_chars);
        $this->assertSame('hd', $basic->voice_tier);
        $this->assertContains('basic', Plan::query()->subscriptionPlans()->pluck('slug')->all());
    }

    public function test_buying_again_adds_characters_and_restarts_the_clock(): void
    {
        $user = $this->member();
        $packs = app(CharPackService::class);

        $this->paidPurchase($user);
        $packs->reserve($user->id, 5000);
        $this->travel(20)->days();

        $this->paidPurchase($user);

        $wallet = CharPackWallet::query()->where('user_id', $user->id)->first();
        $this->assertSame(35000, $wallet->remaining(), '15,000 left plus 20,000 new');
        $this->assertTrue($wallet->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
    }

    public function test_an_ended_pack_does_not_come_back_with_the_next_purchase(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        $this->travel(31)->days();

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));

        $this->paidPurchase($user);
        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_a_purchase_is_added_only_once(): void
    {
        $user = $this->member();
        $purchase = $this->paidPurchase($user);

        app(CharPackService::class)->markPaid($purchase);
        app(CharPackService::class)->markPaid($purchase->fresh());

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_spending_never_goes_past_what_is_left(): void
    {
        $user = $this->member();
        $packs = app(CharPackService::class);
        $this->paidPurchase($user);

        $packs->reserve($user->id, 19000);
        try {
            $packs->reserve($user->id, 1500);
            $this->fail('Spending more than is left must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1,000', $exception->errors()['content'][0]);
        }

        $packs->release($user->id, 4000);
        $this->assertSame(5000, $packs->remaining($user->id));
        $packs->adjust($user->id, 999999);
        $this->assertSame(0, $packs->remaining($user->id), 'never more spent than bought');
    }

    public function test_a_refund_takes_back_only_what_is_unused(): void
    {
        $user = $this->member();
        $purchase = $this->paidPurchase($user);
        app(CharPackService::class)->reserve($user->id, 6000);

        $taken = app(CharPackService::class)->refund($purchase);

        $this->assertSame(14000, $taken);
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
        $this->assertSame(CharPackPurchase::STATUS_REFUNDED, $purchase->fresh()->status);
    }

    // ──────────────────────────────────────────────────────────────────
    //  What the pack unlocks
    // ──────────────────────────────────────────────────────────────────

    public function test_a_free_member_with_pack_characters_gets_the_premium_voice(): void
    {
        $user = $this->member();
        $plans = app(PlanAccessService::class);
        $this->assertSame('free', $plans->resolveAccessPlanForUserId($user->id)->slug);

        $this->paidPurchase($user);

        $access = $plans->resolveAccessPlanForUserId($user->id);
        $this->assertSame('pack', $access->slug);
        $this->assertSame('hd', $plans->resolveTierForPlan($access));
        $this->assertTrue(CharPackService::billsToPack($plans->resolveQuotaPlanForUserId($user->id, $access)));
        $this->assertSame('free', $plans->resolveActivePlanForUserId($user->id)->slug, 'the pack never becomes the subscription');

        app(CharPackService::class)->reserve($user->id, 20000);
        $this->assertSame('free', $plans->resolveAccessPlanForUserId($user->id)->slug, 'back to free once used up');
    }

    public function test_a_paid_member_keeps_their_plan(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->assertSame('starter', app(PlanAccessService::class)->resolveAccessPlanForUserId($user->id)->slug);
    }

    public function test_dashboard_generation_is_paid_from_the_pack(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        $voice = $this->premiumVoice();

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => str_repeat('a', 400),
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ])->assertOk()
            ->assertJsonPath('usage.mode', 'pack')
            ->assertJsonPath('usage.remaining_chars', 19600);

        $this->assertSame(19600, app(CharPackService::class)->remaining($user->id));
        $this->assertTrue((bool) AudioGeneration::query()->latest('id')->value('billed_to_pack'));
        $this->assertSame('hd', AudioGeneration::query()->latest('id')->value('voice_tier'));
        $this->assertSame(0, (int) UsageCounter::query()->where('user_id', $user->id)->value('total_chars'), 'the monthly allowance is untouched');
    }

    public function test_a_failed_generation_gives_the_characters_back(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        $this->speechFails = true;

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => str_repeat('a', 400),
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertStatus(422);

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_a_text_longer_than_what_is_left_is_refused_before_any_speech(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        app(CharPackService::class)->reserve($user->id, 19800);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => str_repeat('a', 400),
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertStatus(422)
            ->assertJsonPath('errors.content.0', __('ui.backend.pack_not_enough', ['remaining' => '200', 'needed' => '400']));

        $this->assertSame([], $this->spoken);
    }

    public function test_a_queued_generation_that_fails_gives_the_characters_back(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        app(CharPackService::class)->reserve($user->id, 300);
        $generation = AudioGeneration::query()->create([
            'user_id' => $user->id,
            'input_text' => str_repeat('a', 300),
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'status' => 'queued',
            'billed_to_pack' => true,
        ]);

        (new GenerateAudioJob($generation->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_the_extension_spends_the_pack_and_shows_it(): void
    {
        $user = $this->member();
        $key = 'lk_live_'.str_repeat('k', 32);
        $user->forceFill(['api_key_hash' => hash('sha256', $key)])->save();
        $this->paidPurchase($user);

        $this->withHeaders(['X-Listen-Key' => $key])->getJson('/api/reader/profile')
            ->assertOk()
            ->assertJsonPath('profile.plan.slug', 'pack')
            ->assertJsonPath('profile.plan.voice_tier', 'hd')
            ->assertJsonPath('profile.usage.mode', 'pack')
            ->assertJsonPath('profile.usage.plan_chars', 20000);

        $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => str_repeat('a', 500),
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
            'prefer_sync' => true,
        ])->assertOk();

        $this->assertSame(19500, app(CharPackService::class)->remaining($user->id));
    }

    // ──────────────────────────────────────────────────────────────────
    //  New members and the free plan
    // ──────────────────────────────────────────────────────────────────

    public function test_once_switched_off_nobody_on_the_free_plan_has_monthly_characters(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $voice = $this->standardVoice();
        $generate = fn (User $user) => $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $generate($member)->assertOk();

        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');

        $generate($member)->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.no_chars_yet'));
        $generate($admin)->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.no_chars_yet'));
        $generate($this->member())->assertStatus(422);
    }

    public function test_a_gift_still_works_for_a_member_without_free_characters(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $user = $this->member();
        UsageCounter::query()->create(['user_id' => $user->id, 'period_ym' => now()->format('Y-m'), 'bonus_chars' => 500]);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->standardVoice()->id),
        ])->assertOk()->assertJsonPath('usage.plan_chars', 500);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Paying by card
    // ──────────────────────────────────────────────────────────────────

    public function test_paying_by_card_adds_the_pack(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
        $charge = $this->stripe->charges[0];
        $this->assertSame(200, $charge['amountMinor']);
        $this->assertSame('usd', $charge['currency']);
        $this->assertSame('char_pack', $charge['metadata']['listen_purpose']);
        $purchase = CharPackPurchase::query()->sole();
        $this->assertSame('listen-pack-'.$purchase->id, $charge['idempotencyKey']);
        $this->assertSame(CharPackPurchase::STATUS_PAID, $purchase->status);
        $this->assertSame('pi_test_1', $purchase->stripe_payment_intent_id);
        $this->assertSame('free', app(PlanAccessService::class)->resolveActivePlanForUserId($user->id)->slug);
    }

    public function test_a_card_that_needs_the_bank_check_is_added_after_it(): void
    {
        $user = $this->member();
        $this->stripe->nextOutcome = 'requires_action';

        $first = $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_3ds'])
            ->assertStatus(402)
            ->assertJsonPath('requires_action', true);
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));

        $this->stripe->completeBankCheck('pi_test_1');
        $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card.confirm', ['purchase' => $first->json('purchase')])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_a_declined_card_adds_nothing(): void
    {
        $user = $this->member();
        $this->stripe->nextOutcome = 'declined';

        $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_declined'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
        $this->assertSame(CharPackPurchase::STATUS_FAILED, CharPackPurchase::query()->sole()->status);
    }

    public function test_a_member_cannot_confirm_someone_elses_purchase(): void
    {
        $owner = $this->member();
        $this->stripe->nextOutcome = 'requires_action';
        $first = $this->actingAs($owner)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_x']);
        $this->stripe->completeBankCheck('pi_test_1');

        $this->actingAs($this->member())->postJsonLocalizedRoute('billing.pack.card.confirm', ['purchase' => $first->json('purchase')])
            ->assertNotFound();
    }

    public function test_members_on_a_plan_are_not_charged_for_a_pack(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])
            ->assertStatus(409);

        $this->assertSame([], $this->stripe->charges);
    }

    public function test_the_pack_cannot_be_bought_while_it_is_switched_off(): void
    {
        $this->pack->update(['is_active' => false]);

        $this->actingAs($this->member())->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])
            ->assertStatus(422);
        $this->assertSame([], $this->stripe->charges);
    }

    public function test_the_webhook_adds_a_paid_pack_once(): void
    {
        $user = $this->member();
        $purchase = app(CharPackService::class)->startPurchase($user, $this->pack, 'card', 200, 'USD');
        $purchase->forceFill(['stripe_payment_intent_id' => 'pi_hook'])->save();
        $event = [
            'id' => 'evt_pack_1',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_hook', 'object' => 'payment_intent', 'amount' => 200, 'amount_received' => 200,
                'customer' => 'cus_x', 'metadata' => ['listen_purpose' => 'char_pack', 'listen_purchase_id' => (string) $purchase->id],
            ]],
        ];

        $this->postJson('/api/stripe/webhook', $event)->assertOk();
        $this->postJson('/api/stripe/webhook', $event)->assertOk();
        $this->postJson('/api/stripe/webhook', ['id' => 'evt_pack_2'] + $event)->assertOk();

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    public function test_a_card_refund_takes_the_pack_back(): void
    {
        $user = $this->member();
        $this->actingAs($user)->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_visa'])->assertOk();

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1', 'refunded' => true, 'customer' => 'cus_x']],
        ])->assertOk();

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
    }

    // ──────────────────────────────────────────────────────────────────
    //  InstaPay / Vodafone Cash
    // ──────────────────────────────────────────────────────────────────

    public function test_paying_by_instapay_adds_the_pack_once_approved(): void
    {
        $this->enableManualPayments();
        $user = $this->member();

        $this->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])
            ->withoutLocaleRedirects()->get($this->localizedRoute('billing.manual.instructions').'?plan=pack&billing_cycle=one_time&payment_method=instapay')
            ->assertOk()
            ->assertSee(__('ui.pack.one_time_payment'));

        $this->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])
            ->postLocalizedRoute('billing.manual.confirm', ['plan' => 'pack', 'billing_cycle' => 'one_time', 'payment_method' => 'instapay'])
            ->assertRedirect();

        $request = ManualPaymentRequest::query()->sole();
        $this->assertSame('one_time', $request->billing_cycle);
        $this->assertSame(10500, (int) $request->expected_amount_minor, '$2 at 52.35 EGP, rounded up to whole pounds');
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));

        app(ManualPaymentService::class)->approve($request, $this->admin());

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
        $subscription = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame($this->free->id, (int) $subscription->plan_id, 'the plan is not touched');
        $this->assertNotSame('manual_override', $subscription->billing_payment_state);
        $this->assertSame('instapay', CharPackPurchase::query()->sole()->method);

        app(ManualPaymentService::class)->deleteRequest($request->fresh());
        $this->assertSame(0, app(CharPackService::class)->remaining($user->id), 'deleting the approval takes the pack back');
    }

    public function test_a_monthly_plan_cannot_be_paid_as_one_time(): void
    {
        $this->enableManualPayments();

        $this->actingAs($this->member())->withHeaders(['CF-IPCountry' => 'EG'])
            ->withoutLocaleRedirects()->get($this->localizedRoute('billing.manual.instructions').'?plan=starter&billing_cycle=one_time&payment_method=instapay')
            ->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Moving to a plan
    // ──────────────────────────────────────────────────────────────────

    public function test_unused_pack_characters_move_into_the_new_plan(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        app(CharPackService::class)->reserve($user->id, 15000);

        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
        $this->assertSame(5000, (int) UsageCounter::query()->where('user_id', $user->id)->where('period_ym', now()->format('Y-m'))->value('bonus_chars'));
        $this->assertSame(5000, (int) CharGift::query()->where('user_id', $user->id)->value('chars'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Pages
    // ──────────────────────────────────────────────────────────────────

    public function test_the_pack_page_offers_card_and_local_payment(): void
    {
        $this->enableManualPayments();

        $this->actingAs($this->member())->withHeaders(['CF-IPCountry' => 'EG'])
            ->getLocalizedRoute('billing.pack')
            ->assertOk()
            ->assertSee('pack-card-form', false)
            ->assertSee(__('ui.pack.pay_locally'))
            ->assertSee('billing_cycle=one_time', false);
    }

    public function test_the_pack_page_tells_plan_members_they_already_have_it(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->actingAs($user)->getLocalizedRoute('billing.pack')
            ->assertOk()
            ->assertSee(__('ui.pack.plan_includes_premium'))
            ->assertDontSee('pack-card-form', false);
    }

    public function test_guests_sign_up_first_and_then_land_on_the_pack(): void
    {
        $this->getLocalizedRoute('pack.start')->assertRedirect(route('register'));
        $this->assertSame(route('billing.pack'), session('url.intended'));
    }

    public function test_the_pricing_page_shows_the_pack_as_a_one_time_card(): void
    {
        $page = $this->getLocalizedRoute('pricing')->assertOk();

        $page->assertSee('data-plan-slug="pack"', false)
            ->assertSee('data-is-pack="1"', false)
            ->assertSee(__('ui.pack.headline', ['count' => '20,000', 'days' => 30]))
            ->assertSee(route('pack.start'), false);
    }

    public function test_the_pricing_page_does_not_sell_the_pack_to_plan_members(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->actingAs($user)->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee(__('ui.pack.for_free_members'));
    }

    public function test_the_free_card_shows_the_demo_when_new_members_get_no_free_characters(): void
    {
        $this->getLocalizedRoute('pricing')->assertSee(__('ui.pricing.characters_per_month'));

        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');

        $this->getLocalizedRoute('pricing')
            ->assertSee(__('ui.pack.free_try_headline', ['count' => number_format(\App\Support\DemoAllowance::chars())]))
            ->assertSee(__('ui.pricing.lines.label_demo'));
    }

    public function test_a_new_member_without_characters_is_offered_the_pack_and_starter(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');

        $this->actingAs($this->member())->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('plan-offer--no_chars', false)
            ->assertSee(__('ui.pack.offer_choose_title'))
            ->assertSee(route('billing.pack'), false)
            ->assertSee(route('billing.page', ['plan' => 'starter']), false);
    }

    public function test_a_pack_holder_sees_their_pack_on_the_dashboard(): void
    {
        $user = $this->member();
        $this->paidPurchase($user);
        app(CharPackService::class)->reserve($user->id, 2000);

        $this->actingAs($user)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('plan-offer--has_pack', false)
            ->assertSee(__('ui.pack.meter_label'))
            ->assertSee('18,000 / 20,000', false);
    }

    public function test_plan_members_get_no_pack_offer(): void
    {
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $this->starter);

        $this->actingAs($user)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertDontSee('id="packOffer"', false);
    }

    public function test_without_a_pack_on_sale_the_dashboard_offers_the_two_cheapest_plans(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $this->pack->update(['is_active' => false]);
        Plan::query()->where('slug', 'basic')->update(['is_active' => true]);

        $this->actingAs($this->member())->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee(__('ui.pack.offer_no_chars_body_plans'))
            ->assertSee(route('billing.page', ['plan' => 'basic']), false)
            ->assertSee(route('billing.page', ['plan' => 'starter']), false)
            ->assertDontSee(route('billing.pack'), false);
    }

    public function test_the_dashboard_offer_shows_what_the_admin_wrote_for_each_plan(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $this->pack->update(['is_active' => false]);
        Plan::query()->where('slug', 'basic')->update([
            'is_active' => true,
            'name_en' => 'Lite',
            'description_en' => 'Written by the owner for Lite.',
            'button_text_en' => 'Start Lite',
            'pricing_extra_lines_en' => json_encode([['label' => 'Owner line', 'value' => 'Yes', 'icon' => 'check']]),
            'pricing_lines' => json_encode(array_fill_keys(array_keys(\App\Support\PlanPricingLines::LINES), false)),
        ]);

        $page = $this->actingAs($this->member())->getLocalizedRoute('dashboard')->assertOk();
        $locale = app()->getLocale();
        if ($locale === 'en') {
            $page->assertSee('Lite')->assertSee('Written by the owner for Lite.')->assertSee('Start Lite')->assertSee('Owner line');
        }
        $page->assertSee('plan-offer-card', false)->assertSee(route('billing.page', ['plan' => 'basic']), false);
    }

    public function test_a_member_without_characters_sees_every_premium_option_but_is_asked_to_choose_a_plan(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $this->premiumVoice();
        $user = $this->member();
        UsageCounter::query()->create(['user_id' => $user->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 3998, 'total_requests' => 23]);

        $page = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk();

        $page->assertSee(__('ui.pack.showcase_note'))
            ->assertSee('Kore')
            ->assertSee(__('ui.pack.no_chars_value'))
            ->assertSee('class="kpis"  style="display:none"', false);
    }

    public function test_the_basic_plan_gives_the_premium_voice_with_its_own_monthly_allowance(): void
    {
        $basic = Plan::query()->where('slug', 'basic')->firstOrFail();
        $basic->update(['is_active' => true]);
        $user = $this->member();
        app(PlanAccessService::class)->assignPlan($user, $basic);
        $plans = app(PlanAccessService::class);

        $access = $plans->resolveAccessPlanForUserId($user->id);
        $this->assertSame('basic', $access->slug);
        $this->assertSame('hd', $plans->resolveTierForPlan($access));
        $this->assertFalse(CharPackService::billsToPack($plans->resolveQuotaPlanForUserId($user->id, $access)));

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => str_repeat('a', 400),
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertOk()->assertJsonPath('usage.plan_chars', 20000)->assertJsonPath('usage.total_chars', 400);
    }

    public function test_the_command_ends_free_monthly_characters_for_everyone(): void
    {
        $this->artisan('listen:end-free-monthly-chars')->assertSuccessful();
        $this->assertTrue(FreeAllowance::freePlanGivesMonthlyChars(), 'a dry run changes nothing');

        $this->artisan('listen:end-free-monthly-chars', ['--apply' => true])->assertSuccessful();

        $this->assertFalse(FreeAllowance::freePlanGivesMonthlyChars());
    }

    public function test_the_admin_switches_free_monthly_characters_for_new_members(): void
    {
        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(\App\Filament\Pages\SiteSettings::class)
            ->assertSet('data.'.FreeAllowance::NEW_MEMBERS_KEY, true)
            ->set('data.'.FreeAllowance::NEW_MEMBERS_KEY, false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(FreeAllowance::freePlanGivesMonthlyChars());
    }

    public function test_the_subscriptions_tab_lists_paid_members_only(): void
    {
        $free = $this->member();
        $paid = $this->member();
        app(PlanAccessService::class)->assignPlan($paid, $this->starter);
        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $freeRow = Subscription::query()->where('user_id', $free->id)->sole();
        $paidRow = Subscription::query()->where('user_id', $paid->id)->sole();

        \Livewire\Livewire::test(\App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions::class)
            ->assertCanSeeTableRecords([$paidRow])
            ->assertCanNotSeeTableRecords([$freeRow]);
    }

    public function test_a_free_member_can_be_gifted_characters_from_the_users_tab(): void
    {
        $free = $this->member();
        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->callTableAction('giftChars', $free, ['chars' => 750, 'note' => 'Welcome'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(750, (int) UsageCounter::query()->where('user_id', $free->id)->value('bonus_chars'));
    }

    public function test_a_missing_free_plan_never_hands_out_a_paid_plan(): void
    {
        Subscription::query()->delete();
        $this->free->delete();

        $this->assertNull(app(PlanAccessService::class)->freePlan());
        $user = $this->member();
        $this->assertSame(0, Subscription::query()->where('user_id', $user->id)->count());
    }

    public function test_the_admin_edits_the_pack(): void
    {
        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(\App\Filament\Resources\PlanResource\Pages\EditPlan::class, ['record' => $this->pack->getRouteKey()])
            ->assertFormFieldIsVisible('pack_days')
            ->assertSee('Characters in the pack')
            ->fillForm(['pack_days' => 45, 'monthly_chars' => 25000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(45, $this->pack->fresh()->packDays());
        $this->assertSame(25000, (int) $this->pack->fresh()->monthly_chars);
    }

    // ──────────────────────────────────────────────────────────────────

    private function withoutLocaleRedirects(): static
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
    }

    private function member(): User
    {
        return User::factory()->create(['subscription_status_checked_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function paidPurchase(User $user): CharPackPurchase
    {
        $packs = app(CharPackService::class);

        return $packs->markPaid($packs->startPurchase($user, $this->pack, 'card', 200, 'USD'));
    }

    private function enableManualPayments(): void
    {
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');
    }

    private function premiumVoice(): Voice
    {
        $provider = VoiceCatalog::preferredProviderForTier('hd');

        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Premium-Kore'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Kore',
            'provider' => $provider, 'provider_voice_id' => 'en-US-Premium-Kore', 'is_active' => true,
        ]);
    }

    private function standardVoice(): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    private function fakeSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function (string $text): array {
            if ($this->speechFails) {
                throw new \RuntimeException('Speech service unavailable.');
            }
            $this->spoken[] = $text;

            return [
                'audio_path' => 'audio/pack-track.mp3',
                'duration_seconds' => 3,
                'provider' => 'gemini',
                'model' => 'premium',
                'latency_ms' => 10,
                'fallback_used' => false,
                'cost_estimate_usd' => 0.0,
                'routing_mode' => 'hybrid',
                'plan_slug' => 'pack',
            ];
        });
        $this->app->instance(TtsService::class, $tts);
    }
}
