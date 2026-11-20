<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\Billing\StripePackGateway;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\PlanUsageGuard;
use App\Support\UsageSnapshot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripePackGateway;
use Tests\TestCase;

/**
 * Extra credits (App\Services\Billing\ExtraCredits): a paid member whose credits
 * run out buys more, once. They go on the member's own usage row for the
 * period, used after the plan's credits and the gifts; what is left moves on
 * each time the plan renews and ends when the plan stops. Each purchase is its
 * own record; no subscription row is ever made for them.
 */
class ExtraCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $starter;

    private Plan $creator;

    private FakeStripePackGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => '',
        ]);

        $this->free = Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 4500, 'monthly_requests' => 0]);
        $this->starter = Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 90000, 'monthly_requests' => 0]);
        $this->creator = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_chars' => 300000, 'monthly_requests' => 0]);

        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-10-01 00:00:00', 'billing');
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');

        $this->stripe = new FakeStripePackGateway;
        $this->stripe->card = ['id' => 'pm_saved', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2028];
        $this->app->instance(StripePackGateway::class, $this->stripe);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Who can buy, and what they see
    // ──────────────────────────────────────────────────────────────────

    public function test_only_members_on_a_paid_plan_can_buy_them_and_only_while_they_are_switched_on(): void
    {
        $extra = app(ExtraCredits::class);

        $free = User::factory()->create();
        app(PlanAccessService::class)->assignPlan($free, $this->free);
        $this->assertSame(ExtraCredits::STATUS_NO_PLAN, $extra->status($free->id));
        $this->offer($free)->assertOk()->assertJsonPath('status', 'no_plan')->assertJsonMissingPath('presets');
        $this->actingAs($free)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])
            ->assertStatus(409)->assertJsonPath('message', __('ui.extra_credits.paid_plans_only'));

        $paid = $this->member();
        $this->assertSame(ExtraCredits::STATUS_OK, $extra->status($paid->id));

        // A renewal that could not be charged is paid first.
        Subscription::query()->where('user_id', $paid->id)->update(['billing_payment_state' => 'past_due', 'billing_grace_ends_at' => now()->addDays(7)]);
        $this->assertSame(ExtraCredits::STATUS_RENEWAL_DUE, $extra->status($paid->id));
        $this->actingAs($paid)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])
            ->assertStatus(409)->assertJsonPath('message', __('ui.extra_credits.renewal_due'));

        Subscription::query()->where('user_id', $paid->id)->update(['billing_payment_state' => 'ok', 'billing_grace_ends_at' => null]);
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        $this->assertSame(ExtraCredits::STATUS_OFF, $extra->status($paid->id));
        $this->actingAs($paid)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])->assertStatus(409);

        $this->assertSame([], $this->stripe->charges);
        $this->assertSame(0, CharPackPurchase::query()->count());
    }

    public function test_the_offer_shows_whole_prices_in_the_members_own_currency(): void
    {
        $member = $this->member();

        $egypt = $this->offer($member, 'EG')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('default_minutes', 30);
        $this->assertSame([10, 20, 30, 50, 100], array_column($egypt->json('presets'), 'minutes'));
        $this->assertSame([9000, 18000, 27000, 45000, 90000], array_column($egypt->json('presets'), 'credits'));
        // $2, $4, $5, $8 and $12 at 52.35, each rounded up to a whole pound.
        $this->assertSame(['EGP 105', 'EGP 210', 'EGP 262', 'EGP 419', 'EGP 629'], $this->plain(array_column($egypt->json('presets'), 'price')));
        $this->assertSame('30 minutes of voice', $egypt->json('presets.2.minutes_label'));
        $this->assertSame(['brand' => 'visa', 'last4' => '4242', 'expires' => '08/28'], $egypt->json('card'));
        // A member who pays by card is not offered transfers.
        $this->assertSame([], $egypt->json('manual'));

        // A member elsewhere sees dollars (local requests take the site's local country).
        SiteSetting::set('pricing_local_country_code', 'US', 'billing');
        $us = $this->offer($member, 'US');
        $this->assertSame(['$2', '$4', '$5', '$8', '$12'], array_column($us->json('presets'), 'price'));

        // The admin's own presets: the default is the smallest when 30 minutes is not one of them.
        SiteSetting::set(ExtraCredits::PRESETS_SETTING, json_encode(['25' => 600, '10' => 300]), 'billing');
        $own = $this->offer($member, 'US');
        $this->assertSame([10, 25], array_column($own->json('presets'), 'minutes'));
        $this->assertSame(10, $own->json('default_minutes'));
        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Paying by card
    // ──────────────────────────────────────────────────────────────────

    public function test_paying_with_the_saved_card_adds_the_credits_to_this_periods_usage(): void
    {
        $member = $this->member();
        $this->spend($member, 90000);
        $this->assertSame(0, $this->snapshot($member)['remaining_chars']);

        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('credits', 27000)
            ->assertJsonPath('usage.extra_chars', 27000)
            ->assertJsonPath('usage.remaining_chars', 27000);

        // One charge in dollars, on the card the member pays with.
        $this->assertCount(1, $this->stripe->charges);
        $charge = $this->stripe->charges[0];
        $purchase = CharPackPurchase::query()->sole();
        $this->assertSame(['pm_saved', 500, 'usd', 'Listen Extra Credits (30 minutes)', false], [$charge['paymentMethodId'], $charge['amountMinor'], $charge['currency'], $charge['description'], $charge['saveCard']]);
        $this->assertSame([
            'listen_purpose' => 'extra_credits',
            'listen_minutes' => '30',
            'listen_credits' => '27000',
            'listen_plan_slug' => 'starter',
            'listen_user_id' => (string) $member->id,
            'listen_purchase_id' => (string) $purchase->id,
        ], $charge['metadata']);
        $this->assertSame('listen-extra-credits-'.$purchase->id, $charge['idempotencyKey']);

        // Its own record, with the period it went to.
        $this->assertSame(
            [CharPackPurchase::KIND_EXTRA_CREDITS, CharPackPurchase::STATUS_PAID, 27000, 0, 500, 'USD', 'card', $this->starter->id, '2026-10-14 08:37', 'pi_test_1'],
            [$purchase->kind, $purchase->status, $purchase->chars, $purchase->days, $purchase->amount_minor, $purchase->currency, $purchase->method, $purchase->plan_id, $purchase->period_ym, $purchase->stripe_payment_intent_id],
        );

        // On the member's own usage row: no other row, and no subscription row.
        $counter = UsageCounter::query()->where('user_id', $member->id)->sole();
        $this->assertSame([27000, 90000, '2026-11-14 08:37:00'], [$counter->extra_chars, $counter->extra_base_chars, $counter->extra_ends_at->toDateTimeString()]);
        $this->assertSame(1, Subscription::query()->where('user_id', $member->id)->count());
        $this->assertSame(90000 + 27000, PlanUsageGuard::charsLimit($this->quota($member), CreditPeriod::counter($member->id)));
    }

    public function test_the_card_payment_is_listed_with_the_members_receipts(): void
    {
        $member = $this->member();
        $this->buy($member, 30);

        $this->actingAs($member)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee('Extra credits (30 minutes)');
    }

    public function test_the_plans_credits_are_used_first_then_the_gifts_then_the_extra_credits(): void
    {
        $member = $this->member();
        $this->buy($member, 10);
        $this->assertSame(9000, $this->snapshot($member)['extra_chars']);

        $this->spend($member, 50000);
        $this->assertSame(9000, $this->snapshot($member)['extra_chars']);

        CreditPeriod::counter($member->id)->update(['bonus_chars' => 5000]);
        $this->spend($member, 44000);
        $this->assertSame(9000, $this->snapshot($member)['extra_chars'], 'the gift is used before the extra credits');

        $this->spend($member, 3000);
        $snapshot = $this->snapshot($member);
        $this->assertSame([7000, 7000, 104000], [$snapshot['extra_chars'], $snapshot['remaining_chars'], $snapshot['plan_chars']]);
    }

    public function test_a_card_the_bank_checks_is_added_once_whether_the_browser_or_the_webhook_says_so(): void
    {
        $member = $this->member();
        $this->stripe->nextOutcome = 'requires_action';

        $started = $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', [
            'minutes' => 30, 'payment_method' => 'pm_new_card', 'save_card' => true,
        ])->assertStatus(402)->assertJsonPath('requires_action', true)->assertJsonPath('client_secret', 'pi_test_1_secret_test');
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);
        $this->assertSame(['pm_new_card', true], [$this->stripe->charges[0]['paymentMethodId'], $this->stripe->charges[0]['saveCard']]);
        $this->assertSame('1', $this->stripe->charges[0]['metadata']['listen_save_card']);

        $this->stripe->completeBankCheck('pi_test_1');
        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card.confirm', ['purchase' => $started->json('purchase')])
            ->assertOk()->assertJsonPath('credits', 27000);

        $this->postJson('/api/stripe/webhook', $this->succeeded('pi_test_1', 500, (int) $started->json('purchase')))->assertOk();
        $this->postJson('/api/stripe/webhook', ['id' => 'evt_again'] + $this->succeeded('pi_test_1', 500, (int) $started->json('purchase')))->assertOk();

        $this->assertSame(27000, (int) CreditPeriod::counter($member->id)->extra_chars);
        // The new card is kept as the one the member pays with.
        $this->assertSame('pm_new_card', $this->stripe->defaultCards[0]['payment_method'] ?? null);
        $this->assertSame('4242', $member->fresh()->pm_last_four);
    }

    public function test_a_payment_that_only_the_webhook_reports_is_added(): void
    {
        $member = $this->member();
        $this->stripe->nextOutcome = 'processing';
        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 20])
            ->assertStatus(202)->assertJsonPath('processing', true);
        $purchase = CharPackPurchase::query()->sole();
        $this->assertSame(CharPackPurchase::STATUS_PENDING, $purchase->status);

        // A lower amount than the price adds nothing.
        $this->postJson('/api/stripe/webhook', $this->succeeded('pi_test_1', 300, $purchase->id))->assertOk();
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);

        $this->postJson('/api/stripe/webhook', ['id' => 'evt_full'] + $this->succeeded('pi_test_1', 400, $purchase->id))->assertOk();
        $this->assertSame(18000, (int) CreditPeriod::counter($member->id)->extra_chars);
        $this->assertSame(CharPackPurchase::STATUS_PAID, $purchase->fresh()->status);
    }

    public function test_a_declined_card_or_no_saved_card_charges_and_adds_nothing(): void
    {
        $member = $this->member();
        $this->stripe->nextOutcome = 'declined';
        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])
            ->assertStatus(422)
            ->assertJsonPath('message', __('ui.extra_credits.card_declined'))
            ->assertJsonPath('reason', 'Your card was declined.');
        $this->assertSame(CharPackPurchase::STATUS_FAILED, CharPackPurchase::query()->sole()->status);

        $this->stripe->card = null;
        $this->stripe->nextOutcome = 'succeeded';
        $this->actingAs($member)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => 30])
            ->assertStatus(422)->assertJsonPath('message', __('ui.extra_credits.no_saved_card'));

        $this->assertCount(1, $this->stripe->charges);
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);
    }

    public function test_a_refund_takes_back_what_is_still_left(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        $this->spend($member, 90000 + 7000);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_refund',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1', 'refunded' => true, 'customer' => 'cus_x']],
        ])->assertOk();

        $this->assertSame(CharPackPurchase::STATUS_REFUNDED, CharPackPurchase::query()->sole()->status);
        $this->assertSame(7000, (int) CreditPeriod::counter($member->id)->extra_chars, 'what was used stays used');
        $this->assertSame(0, $this->snapshot($member)['extra_chars']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  From one period to the next
    // ──────────────────────────────────────────────────────────────────

    public function test_what_is_left_moves_on_each_time_the_plan_renews(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        $this->spend($member, 90000 + 7000);
        $october = CreditPeriod::counter($member->id);

        $this->renew($member, '2026-11-14 08:37:00', '2026-12-14 08:37:00', '2026-11-14 08:40:00');
        $november = CreditPeriod::counter($member->id);
        $this->assertSame(['2026-11-14 08:37', 0, 20000], [$november->period_ym, $november->total_chars, $november->extra_chars]);
        $this->assertSame('2026-12-14 08:37:00', $november->extra_ends_at->toDateTimeString());
        $this->assertSame(ExtraCredits::OUTCOME_CARRIED, $october->fresh()->extra_outcome);
        $this->assertSame(90000 + 20000, PlanUsageGuard::charsLimit($this->quota($member), $november));

        // Unused for a whole period: it moves on again.
        $this->renew($member, '2026-12-14 08:37:00', '2027-01-14 08:37:00', '2026-12-14 08:39:00');
        $this->assertSame(20000, $this->snapshot($member)['extra_chars']);
        $this->assertSame(1, CharPackPurchase::query()->count(), 'moving on adds no purchase');
        $this->assertSame(1, Subscription::query()->where('user_id', $member->id)->count());
    }

    public function test_a_cancelled_plan_keeps_them_until_its_period_ends_then_they_end(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_cancelled', 'stripe_status' => 'active',
            'ends_at' => Carbon::parse('2026-11-14 08:37:00'),
        ]);
        $this->offer($member)->assertJsonPath('plan_ends', true);

        $this->travelTo(Carbon::parse('2026-11-13 20:00:00'));
        $this->assertSame(27000, $this->snapshot($member)['extra_chars'], 'usable until the paid period ends');

        $this->travelTo(Carbon::parse('2026-11-14 11:00:00'));
        $this->assertSame('2026-11', CreditPeriod::counter($member->id)->period_ym);
        $october = UsageCounter::query()->where('user_id', $member->id)->where('period_ym', '2026-10-14 08:37')->sole();
        $this->assertSame(ExtraCredits::OUTCOME_ENDED, $october->extra_outcome);

        // Coming back later starts without them.
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->onPlan($member, $this->starter, '2026-11-20 10:00:00', '2026-12-20 10:00:00');
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);
        $this->assertSame(0, $this->snapshot($member)['extra_chars']);
    }

    public function test_an_unpaid_renewal_keeps_them_through_the_grace_and_ends_them_with_it(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_past_due', 'stripe_status' => 'past_due',
        ]);
        Subscription::query()->where('user_id', $member->id)->update([
            'billing_payment_state' => 'past_due', 'billing_grace_ends_at' => Carbon::parse('2026-11-21 08:37:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $this->assertSame('2026-10-14 08:37', CreditPeriod::counter($member->id)->period_ym);
        $this->assertSame(27000, $this->snapshot($member)['extra_chars']);

        $this->travelTo(Carbon::parse('2026-11-21 09:00:00'));
        $this->assertNotSame('2026-10-14 08:37', CreditPeriod::counter($member->id)->period_ym);
        $this->assertSame(ExtraCredits::OUTCOME_ENDED, UsageCounter::query()->where('user_id', $member->id)->where('period_ym', '2026-10-14 08:37')->value('extra_outcome'));
    }

    public function test_a_renewal_paid_during_the_grace_carries_them_on(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        Subscription::query()->where('user_id', $member->id)->update([
            'billing_payment_state' => 'past_due', 'billing_grace_ends_at' => Carbon::parse('2026-11-21 08:37:00'),
        ]);

        // Paid on the 17th: the billing sync takes Stripe's period, which began on the 14th.
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $this->onPlan($member, $this->starter, '2026-11-14 08:37:00', '2026-12-14 08:37:00');
        Subscription::query()->where('user_id', $member->id)->update(['billing_payment_state' => 'ok', 'billing_grace_ends_at' => null]);

        $this->assertSame(['2026-11-14 08:37', 27000], [CreditPeriod::counter($member->id)->period_ym, $this->snapshot($member)['extra_chars']]);
    }

    public function test_a_change_of_plan_keeps_them(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        $this->spend($member, 90000 + 7000);

        // Up to Creator: the plan's dates start again, the usage and the extra credits go on.
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
        $this->onPlan($member, $this->creator, '2026-10-25 12:00:00', '2026-11-25 12:00:00');
        $counter = CreditPeriod::counter($member->id);
        $this->assertSame(['2026-10-25 12:00', 97000, 27000, 300000], [$counter->period_ym, $counter->total_chars, $counter->extra_chars, $counter->extra_base_chars]);
        $this->assertSame(ExtraCredits::OUTCOME_CARRIED, UsageCounter::query()->where('user_id', $member->id)->where('period_ym', '2026-10-14 08:37')->value('extra_outcome'));
        // Exactly the plan's difference more than before.
        $this->assertSame(300000 + 27000 - 97000, $this->snapshot($member)['remaining_chars']);

        // And back down: still there.
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $this->onPlan($member, $this->starter, '2026-10-28 12:00:00', '2026-11-28 12:00:00');
        $counter = CreditPeriod::counter($member->id);
        $this->assertSame([27000, 90000], [$counter->extra_chars, $counter->extra_base_chars]);
        $this->assertSame(20000, $this->snapshot($member)['extra_chars']);
    }

    public function test_the_hourly_run_settles_members_who_do_not_come_back(): void
    {
        $member = $this->member();
        $this->buy($member, 30);

        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
        $this->artisan('listen:extra-credits-settle')->assertSuccessful();

        $this->assertSame(ExtraCredits::OUTCOME_ENDED, UsageCounter::query()->where('user_id', $member->id)->where('period_ym', '2026-10-14 08:37')->value('extra_outcome'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Paying by InstaPay / Vodafone Cash
    // ──────────────────────────────────────────────────────────────────

    public function test_a_member_who_pays_by_hand_sends_a_transfer_and_gets_the_credits_once_it_is_approved(): void
    {
        Storage::fake('local');
        $this->enableManualPayments();
        $member = $this->member(byHand: true);
        $plan = Subscription::query()->where('user_id', $member->id)->sole();

        $offer = $this->offer($member, 'EG');
        $this->assertSame(['instapay'], array_column($offer->json('manual'), 'method'));
        $this->assertSame(['EGP 262'], $this->plain([$offer->json('presets.2.transfer_price')]));

        $this->transfer($member, 30)->assertOk()->assertJsonPath('pending', true);
        $this->transfer($member, 10)->assertStatus(409)->assertJsonPath('message', __('ui.extra_credits.manual_pending_exists'));
        $this->offer($member, 'EG')->assertJsonPath('pending.minutes', 30);

        $request = ManualPaymentRequest::query()->sole();
        $purchase = CharPackPurchase::query()->sole();
        $this->assertSame(
            [ManualPaymentRequest::CYCLE_EXTRA_CREDITS, $this->starter->id, 26200, 'EGP', 30, 27000, null],
            [$request->billing_cycle, $request->plan_id, $request->expected_amount_minor, $request->currency, $request->price_snapshot['minutes'], $request->price_snapshot['credits'], $request->approved_subscription_id],
        );
        $this->assertSame([CharPackPurchase::KIND_EXTRA_CREDITS, CharPackPurchase::STATUS_PENDING, 'instapay', $request->id], [$purchase->kind, $purchase->status, $purchase->method, $purchase->manual_payment_request_id]);
        Storage::disk('local')->assertExists($request->proof_path);
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);

        app(ManualPaymentService::class)->approve($request, $this->admin());

        $this->assertSame(CharPackPurchase::STATUS_PAID, $purchase->fresh()->status);
        $this->assertSame(27000, $this->snapshot($member)['extra_chars']);
        // The plan itself is untouched: the same row, dates and state.
        $after = Subscription::query()->where('user_id', $member->id)->sole();
        $this->assertSame([$plan->id, $plan->current_period_end->toDateTimeString(), 'manual_override'], [$after->id, $after->current_period_end->toDateTimeString(), $after->billing_payment_state]);
        $this->assertNull($request->fresh()->approved_subscription_id);
        $this->assertSame('Listen Extra Credits (30 minutes)', Payment::query()->where('user_id', $member->id)->value('description'));

        // Deleting the approved payment takes back what is left.
        app(ManualPaymentService::class)->deleteRequest($request->fresh());
        $this->assertSame(CharPackPurchase::STATUS_REFUNDED, $purchase->fresh()->status);
        $this->assertSame(0, $this->snapshot($member)['extra_chars']);
        $this->assertSame(1, Subscription::query()->where('user_id', $member->id)->count());
    }

    public function test_a_rejected_transfer_adds_nothing_and_a_late_one_is_not_approved(): void
    {
        Storage::fake('local');
        $this->enableManualPayments();
        $member = $this->member(byHand: true);

        $this->transfer($member, 20)->assertOk();
        app(ManualPaymentService::class)->reject(ManualPaymentRequest::query()->sole(), $this->admin(), 'No transfer arrived.');
        $this->assertSame(CharPackPurchase::STATUS_FAILED, CharPackPurchase::query()->sole()->status);
        $this->assertSame(0, (int) CreditPeriod::counter($member->id)->extra_chars);

        $this->transfer($member, 20)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00'));
        try {
            app(ManualPaymentService::class)->approve(ManualPaymentRequest::query()->where('status', 'pending')->sole(), $this->admin());
            $this->fail('A transfer for a plan that has ended was approved.');
        } catch (DomainException $exception) {
            $this->assertSame(__('ui.extra_credits.admin_plan_not_on'), $exception->getMessage());
        }
        $this->assertSame(ManualPaymentRequest::STATUS_PENDING, ManualPaymentRequest::query()->latest('id')->value('status'));
    }

    public function test_a_card_member_cannot_send_a_transfer(): void
    {
        Storage::fake('local');
        $this->enableManualPayments();
        $member = $this->member();

        $this->transfer($member, 30)->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, ManualPaymentRequest::query()->count());
    }

    public function test_a_transfer_for_extra_credits_is_not_taken_for_a_plan_payment(): void
    {
        Storage::fake('local');
        $this->enableManualPayments();
        $member = $this->member(byHand: true);
        $this->transfer($member, 30)->assertOk();

        $this->assertSame(0, ManualPaymentRequest::query()->forPlans()->count());
        $this->assertSame(1, ManualPaymentRequest::query()->extraCredits()->count());
        $this->assertFalse(app(ManualPaymentService::class)->hasPendingRequest($member, $this->starter, 'extra_credits', 'instapay'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  The admin
    // ──────────────────────────────────────────────────────────────────

    public function test_the_admin_turns_them_on_and_sets_the_minutes_and_prices_in_billing_settings(): void
    {
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        config(['listen.live_actions' => false]);

        \Livewire\Livewire::test(\App\Filament\Pages\BillingSettings::class)
            ->assertFormSet(['extra_credits_enabled' => false])
            ->fillForm([
                'extra_credits_enabled' => true,
                'extra_credits_presets' => [
                    ['minutes' => 60, 'price_usd' => 9],
                    ['minutes' => 15, 'price_usd' => 3],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        \Illuminate\Support\Facades\Cache::flush();
        $this->assertTrue(ExtraCredits::enabled());
        $this->assertSame([15 => 300, 60 => 900], ExtraCredits::presets());
    }

    public function test_the_admin_takes_back_what_is_left_from_the_purchases_list(): void
    {
        $member = $this->member();
        $this->buy($member, 30);
        $this->spend($member, 90000 + 2000);
        $purchase = CharPackPurchase::query()->sole();

        $this->actingAs($this->admin());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Livewire\Livewire::test(\App\Filament\Resources\CharPackPurchaseResource\Pages\ListCharPackPurchases::class)
            ->assertCanSeeTableRecords([$purchase])
            ->callTableAction('takeBack', $purchase)
            ->assertNotified('25,000 credits taken back');

        $this->assertSame(CharPackPurchase::STATUS_REFUNDED, $purchase->fresh()->status);
        $this->assertSame(0, $this->snapshot($member)['extra_chars']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    /** A member on Starter since 14 October 2026, now on the 20th. */
    private function member(bool $byHand = false): User
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->onPlan($user, $this->starter, '2026-10-14 08:37:00', '2026-11-14 08:37:00');
        if ($byHand) {
            Subscription::query()->where('user_id', $user->id)->update(['billing_payment_state' => 'manual_override']);
        } else {
            // A member who pays by card is a Stripe customer.
            $user->forceFill(['stripe_id' => 'cus_test_'.$user->id])->save();
        }

        return $user;
    }

    private function onPlan(User $user, Plan $plan, string $start, string $end): void
    {
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active', Carbon::parse($end), Carbon::parse($start));
    }

    private function renew(User $user, string $start, string $end, string $at): void
    {
        $this->travelTo(Carbon::parse($at));
        $this->onPlan($user, $this->starter, $start, $end);
    }

    private function buy(User $user, int $minutes): void
    {
        $this->actingAs($user)->postJsonLocalizedRoute('billing.extra-credits.card', ['minutes' => $minutes])->assertOk();
    }

    private function spend(User $user, int $credits): void
    {
        $counter = CreditPeriod::counter($user->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $credits);
        $counter->addSeconds(intdiv($credits, 15));
    }

    private function quota(User $user): ?Plan
    {
        return app(PlanAccessService::class)->resolveQuotaPlanForUserId($user->id);
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return UsageSnapshot::for($user->id, $this->quota($user), CreditPeriod::counter($user->id));
    }

    private function offer(User $user, string $country = 'US'): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['CF-IPCountry' => $country])->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->getJson($this->localizedRoute('billing.extra-credits'));
    }

    private function transfer(User $user, int $minutes): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])->postJsonLocalizedRoute('billing.extra-credits.transfer', [
            'minutes' => $minutes,
            'payment_method' => 'instapay',
            'proof_image' => UploadedFile::fake()->image('receipt.jpg', 600, 900),
        ]);
    }

    /** @return array<string, mixed> */
    private function succeeded(string $intentId, int $amount, int $purchaseId): array
    {
        return [
            'id' => 'evt_'.$intentId,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => $intentId, 'object' => 'payment_intent', 'amount' => $amount, 'amount_received' => $amount,
                'customer' => 'cus_test', 'payment_method' => 'pm_new_card',
                'metadata' => ['listen_purpose' => 'extra_credits', 'listen_purchase_id' => (string) $purchaseId, 'listen_save_card' => '1'],
            ]],
        ];
    }

    /**
     * Prices as read, without the no-break space the currency formatter puts after "EGP".
     *
     * @param  array<int, ?string>  $prices
     * @return array<int, ?string>
     */
    private function plain(array $prices): array
    {
        return array_map(fn (?string $price) => $price === null ? null : str_replace("\u{00A0}", ' ', $price), $prices);
    }

    private function enableManualPayments(): void
    {
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
