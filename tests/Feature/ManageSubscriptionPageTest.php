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

    private function manualMember(\DateTimeInterface $periodEnd): User
    {
        $user = User::factory()->create();
        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $this->starter->id,
            'status' => 'active',
            'billing_payment_state' => 'manual_override',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
        ]);

        return $user;
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
}
