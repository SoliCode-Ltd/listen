<?php

namespace Tests\Feature;

use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManageSubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.key' => '', 'services.stripe.secret' => '']);

        $this->free = Plan::create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 5,
            'monthly_chars' => 4500, 'monthly_requests' => 50, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250,
            'monthly_chars' => 225000, 'monthly_requests' => 3000, 'daily_requests' => 100,
            'max_chars_per_request' => 3000, 'download_formats' => ['mp3', 'wav'], 'is_active' => true,
        ]);
    }

    /**
     * A member on Starter with no card subscription: paid by InstaPay, or
     * (when $paid is false) given the plan by the team with nothing paid.
     */
    private function manualMember(?\DateTimeInterface $periodEnd, bool $paid = true, string $state = 'manual_override'): User
    {
        $user = User::factory()->create();
        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->starter->id,
            'status' => 'active',
            'billing_payment_state' => $state,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
        ]);

        if ($paid) {
            ManualPaymentRequest::create([
                'user_id' => $user->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
                'payment_method' => 'instapay', 'expected_amount_minor' => 47400, 'currency' => 'EGP',
                'status' => ManualPaymentRequest::STATUS_APPROVED, 'submitted_at' => now()->subMonth(), 'reviewed_at' => now()->subMonth(),
            ]);
        }

        return $user;
    }

    private function starterName(): string
    {
        return $this->starter->getLocalizedName('en') ?: 'Starter';
    }

    public function test_a_member_who_paid_manually_sees_their_plan_and_end_date(): void
    {
        $user = $this->manualMember(now()->addDays(20));

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.status_manual_active'))
            ->assertDontSee(__('ui.manage_subscription.status_no_subscription'))
            ->assertSee(__('ui.manage_subscription.paid_until'))
            ->assertSee(__('ui.manage_subscription.renew_plan'))
            // Nothing renews by itself, so there is nothing to cancel, and the page says so.
            ->assertSee(__('ui.manage_subscription.auto_renew_off'))
            ->assertSee(__('ui.manage_subscription.nothing_to_cancel', ['date' => now()->addDays(20)->format('j M Y')]))
            ->assertDontSee('id="cancelBtn"', false)
            ->assertDontSee(__('ui.manage_subscription.keep_plan'))
            // Usage lives on the dashboard; this page is about paying and renewing.
            ->assertDontSee('225,000')
            ->assertDontSee('characters/month');
    }

    public function test_an_expired_manual_payment_says_so_and_offers_renewal(): void
    {
        $user = $this->manualMember(now()->subDays(2));

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.status_expired'))
            ->assertSee(__('ui.manage_subscription.renew_plan'))
            // Back on the free plan, which gives no credits here.
            ->assertSee($this->free->getLocalizedName('en') ?: 'FREE');
    }

    public function test_a_pending_manual_payment_is_shown(): void
    {
        $user = User::factory()->create();
        ManualPaymentRequest::create([
            'user_id' => $user->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'instapay', 'expected_amount_minor' => 47400, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_PENDING, 'submitted_at' => now(),
        ]);

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.manual_pending_notice', ['plan' => $this->starter->getLocalizedName('en') ?: 'Starter']));
    }

    public function test_approved_manual_payments_are_listed_with_the_invoices(): void
    {
        $user = $this->manualMember(now()->addDays(20));
        ManualPaymentRequest::create([
            'user_id' => $user->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'vodafone_cash', 'expected_amount_minor' => 47400, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_APPROVED, 'submitted_at' => now(), 'reviewed_at' => now(),
        ]);

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertDontSee(__('ui.manage_subscription.no_invoices_yet'))
            ->assertSee('474 EGP')
            ->assertSee(__('ui.manage_subscription.manual_payment_label'));
    }

    public function test_the_details_show_the_last_payment_its_discount_and_the_billing_cycle(): void
    {
        $user = $this->manualMember(now()->addDays(20));
        ManualPaymentRequest::create([
            'user_id' => $user->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'instapay', 'expected_amount_minor' => 47400, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_APPROVED, 'submitted_at' => now(), 'reviewed_at' => now(),
            'price_snapshot' => ['amount_minor' => 47400, 'full_amount_minor' => 59300, 'discount_percent' => 20],
        ]);

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.last_payment'))
            ->assertSee('474 EGP')
            ->assertSee(__('ui.billing.payment_method_instapay'))
            ->assertSee(__('ui.manage_subscription.discount_value', ['percent' => 20, 'amount' => "\u{2066}−119 EGP\u{2069}"]))
            ->assertSee(__('ui.manage_subscription.member_since'))
            // Renewing is not automatic: what it costs and by when.
            ->assertSee(__('ui.manage_subscription.renew_payment'))
            ->assertSee(__('ui.billing.monthly'));
    }

    public function test_dates_are_the_days_stripe_bills_on_in_utc(): void
    {
        // 23:30 UTC is already the next day in Cairo; receipts and charges use UTC.
        $user = $this->manualMember(\Illuminate\Support\Carbon::parse('2026-12-25 23:30:00', 'UTC'));

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee('25 Dec 2026')
            ->assertDontSee('26 Dec 2026');
    }

    public function test_a_free_member_sees_no_renewal_and_no_usage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertDontSee(__('ui.manage_subscription.renew_plan'))
            ->assertDontSee(__('ui.manage_subscription.renews_on'))
            ->assertDontSee('usage-card');
    }

    public function test_once_a_manual_payment_is_approved_the_member_sees_how_to_renew(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        $user = User::factory()->create();
        $request = ManualPaymentRequest::create([
            'user_id' => $user->id, 'plan_id' => $this->starter->id, 'billing_cycle' => 'monthly',
            'payment_method' => 'instapay', 'expected_amount_minor' => 47400, 'currency' => 'EGP',
            'status' => ManualPaymentRequest::STATUS_PENDING, 'submitted_at' => now(),
        ]);
        app(\App\Services\ManualPaymentService::class)->approve($request, User::factory()->create(['is_admin' => true]));

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.status_manual_active'))
            ->assertSee(__('ui.manage_subscription.manual_active_notice', ['date' => '17 Nov 2026']))
            ->assertSee(__('ui.manage_subscription.paid_until'))
            ->assertSee(__('ui.manage_subscription.last_payment'))
            ->assertSee('474 EGP')
            // What renewing costs and by when, since nothing renews by itself.
            ->assertSee(__('ui.manage_subscription.renew_payment'))
            ->assertSee(__('ui.manage_subscription.renew_before', ['date' => '17 Nov 2026']))
            ->assertSee(__('ui.manage_subscription.auto_renew_off'))
            ->assertSee(__('ui.manage_subscription.renew_plan'))
            ->assertSee(__('ui.manage_subscription.nothing_to_cancel', ['date' => '17 Nov 2026']))
            // Paid by the member, not given by the team.
            ->assertDontSee(__('ui.manage_subscription.granted_active_notice', ['plan' => $this->starterName(), 'date' => '17 Nov 2026']))
            ->assertDontSee(__('ui.manage_subscription.keep_plan'))
            ->assertDontSee(__('ui.manage_subscription.next_payment_none'))
            ->assertDontSee('id="cancelBtn"', false);
    }

    public function test_a_plan_the_team_gave_says_it_does_not_renew_or_charge(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        // As an admin saves it today: "Paid" picked, nothing paid, no card subscription.
        $user = $this->manualMember(\Illuminate\Support\Carbon::parse('2026-11-14 08:37:51', 'UTC'), paid: false, state: 'paid');

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.granted_active_notice', ['plan' => $this->starterName(), 'date' => '14 Nov 2026']))
            ->assertSee(__('ui.manage_subscription.status_active'))
            ->assertSee(__('ui.manage_subscription.active_until'))
            ->assertSee(__('ui.manage_subscription.next_payment_none'))
            ->assertSee(__('ui.manage_subscription.nothing_charged'))
            ->assertSee(__('ui.manage_subscription.auto_renew_off'))
            ->assertSee(__('ui.manage_subscription.keep_plan'))
            ->assertSee(__('ui.manage_subscription.nothing_to_cancel', ['date' => '14 Nov 2026']))
            // Not a payment the member made, and no price to renew at.
            ->assertDontSee(__('ui.manage_subscription.status_manual_active'))
            ->assertDontSee(__('ui.manage_subscription.manual_active_notice', ['date' => '14 Nov 2026']))
            ->assertDontSee(__('ui.manage_subscription.paid_until'))
            ->assertDontSee(__('ui.manage_subscription.renew_payment'))
            ->assertDontSee('id="cancelBtn"', false);
    }

    public function test_a_plan_the_team_gave_reads_the_same_in_arabic(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        $user = $this->manualMember(\Illuminate\Support\Carbon::parse('2026-11-14 08:37:51', 'UTC'), paid: false);
        app()->setLocale('ar');

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.auto_renew', [], 'ar'))
            ->assertSee(__('ui.manage_subscription.keep_plan', [], 'ar'))
            ->assertSee(__('ui.manage_subscription.next_payment_none', [], 'ar'))
            ->assertDontSee(__('ui.manage_subscription.status_manual_active', [], 'ar'));
    }

    public function test_a_plan_the_team_gave_with_no_end_date_never_charges(): void
    {
        $user = $this->manualMember(null, paid: false);

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.granted_open_notice', ['plan' => $this->starterName()]))
            ->assertSee(__('ui.manage_subscription.nothing_to_cancel_open'));
    }

    public function test_a_plan_the_team_gave_that_ended_says_when(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-17 10:00:00', 'UTC'));
        $user = $this->manualMember(\Illuminate\Support\Carbon::parse('2026-10-10 12:00:00', 'UTC'), paid: false);

        $this->actingAs($user)->getLocalizedRoute('manage-subscription.page')
            ->assertOk()
            ->assertSee(__('ui.manage_subscription.granted_expired_notice', ['plan' => $this->starterName(), 'date' => '10 Oct 2026']))
            ->assertSee(__('ui.manage_subscription.status_expired'))
            ->assertSee(__('ui.manage_subscription.renew_plan'))
            ->assertDontSee(__('ui.manage_subscription.auto_renew'))
            ->assertDontSee(__('ui.manage_subscription.keep_plan'));
    }
}
