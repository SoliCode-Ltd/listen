<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\BillingSubscription;
use App\Models\CharGift;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\PlanUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A paid plan's monthly credits follow the plan's own dates once the switch is
 * on (App\Support\CreditPeriod). The switch gives nobody new credits and takes
 * none away, and credits start again only at a renewal or with a plan taken
 * after the old one ended: a change of plan carries the usage on.
 */
class CreditPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $starter;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->free = Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 4500, 'monthly_requests' => 0]);
        $this->starter = Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 225000, 'monthly_requests' => 0]);
        $this->creator = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_chars' => 900000, 'monthly_requests' => 0]);
    }

    public function test_a_credit_month_begun_before_the_switch_counts_by_calendar_month_until_it_ends(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-20 10:05:00'));
        $this->onPlan($user, $this->creator, '2026-10-20 10:00:00', '2026-11-20 10:00:00');
        // October's counter also holds the 1st to the 19th, from the period paid before.
        $this->spend($user, 4000);

        $this->switchOn('2026-10-21 12:00:00');

        $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-10', $period['key']);
        $this->assertSame('2026-11-01 00:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(4000, CreditPeriod::counter($user->id)->total_chars);

        // The 1st starts a new count, as calendar months always did.
        $this->travelTo(Carbon::parse('2026-11-05 09:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-11', $period['key']);
        $this->assertSame('2026-11-01 00:00:00', $period['starts_at']->toDateTimeString());
        $this->assertSame('2026-11-20 10:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
        $this->spend($user, 700);

        // The renewal starts the plan's own dates, with a fresh count.
        $this->travelTo(Carbon::parse('2026-11-20 10:20:00'));
        $this->onPlan($user, $this->creator, '2026-11-20 10:00:00', '2026-12-20 10:00:00');
        $this->assertSame('2026-11-20 10:00', CreditPeriod::current($user->id)['key']);
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_a_plan_taken_after_the_switch_follows_its_own_dates(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-14 08:40:00'));
        $this->onPlan($user, $this->creator, '2026-10-14 08:37:00', '2026-11-14 08:37:00');

        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-10-14 08:37', $period['key']);
        $this->assertSame('2026-11-14 08:37:00', $period['ends_at']->toDateTimeString());

        // The free plan stays on calendar months.
        $this->assertSame('2026-11', CreditPeriod::current(User::factory()->create()->id)['key']);
    }

    public function test_a_card_renewal_seen_late_keeps_the_plans_credit_month_for_two_hours(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $card = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-14 08:40:00'));
        $this->onPlan($card, $this->creator, '2026-10-14 08:37:00', '2026-11-14 08:37:00');
        BillingSubscription::query()->create([
            'user_id' => $card->id, 'type' => 'default', 'stripe_id' => 'sub_renewing', 'stripe_status' => 'active',
        ]);
        $this->spend($card, 5000);

        // Stripe has renewed, but the renewal has not reached us yet: the period carries on.
        $this->travelTo(Carbon::parse('2026-11-14 09:30:00'));
        $this->assertSame('2026-10-14 08:37', CreditPeriod::current($card->id)['key']);
        $this->assertSame(5000, CreditPeriod::counter($card->id)->total_chars);

        // Two hours on, with still no renewal, it is over.
        $this->travelTo(Carbon::parse('2026-11-14 10:40:00'));
        $this->assertSame('2026-11', CreditPeriod::current($card->id)['key']);

        // A plan with no live card subscription ends on time.
        $manual = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-14 08:40:00'));
        $this->onPlan($manual, $this->creator, '2026-10-14 08:37:00', '2026-11-14 08:37:00');
        $this->travelTo(Carbon::parse('2026-11-14 09:30:00'));
        $this->assertSame('2026-11', CreditPeriod::current($manual->id)['key']);
    }

    public function test_a_plan_change_carries_the_usage_on_instead_of_starting_a_fresh_allowance(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $this->spend($user, 600000);

        // Down to Starter: Stripe starts the billing period again on the day of the change.
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->onPlan($user, $this->starter, '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $counter = CreditPeriod::counter($user->id);
        $this->assertSame('2026-10-15 12:00', $counter->period_ym);
        $this->assertSame([600000, 40000, 667, 1], [$counter->total_chars, $counter->total_seconds, $counter->total_minutes, $counter->total_requests]);
        $this->spend($user, 1500);

        // Straight back up: still what was left, not a fresh allowance.
        $this->travelTo(Carbon::parse('2026-10-15 12:30:00'));
        $this->onPlan($user, $this->creator, '2026-10-15 12:30:00', '2026-11-15 12:30:00');
        $counter = CreditPeriod::counter($user->id);
        $this->assertSame('2026-10-15 12:30', $counter->period_ym);
        $this->assertSame([601500, 40100, 669, 2], [$counter->total_chars, $counter->total_seconds, $counter->total_minutes, $counter->total_requests]);
        $this->assertSame(298500, PlanUsageGuard::charsLimit($this->creator, $counter) - $counter->total_chars);

        // Each period keeps its own record.
        $this->assertSame(600000, (int) UsageCounter::query()->where('period_ym', '2026-10-05 10:00')->value('total_chars'));
    }

    public function test_a_renewal_starts_a_fresh_allowance(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => 'sub_renews', 'stripe_status' => 'active',
        ]);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $this->spend($user, 600000);

        // The renewal reaches us an hour late; its period starts when the old one ended.
        $this->travelTo(Carbon::parse('2026-11-05 11:00:00'));
        $this->assertSame(600000, CreditPeriod::counter($user->id)->total_chars);
        $this->onPlan($user, $this->creator, '2026-11-05 10:00:00', '2026-12-05 10:00:00');

        $counter = CreditPeriod::counter($user->id);
        $this->assertSame('2026-11-05 10:00', $counter->period_ym);
        $this->assertSame(0, $counter->total_chars);
    }

    public function test_a_plan_taken_after_the_old_one_ended_starts_a_fresh_allowance(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $this->spend($user, 600000);

        // It ran out without renewing; a few days later the member subscribes again.
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00'));
        $this->assertSame('2026-11', CreditPeriod::current($user->id)['key']);
        $this->onPlan($user, $this->creator, '2026-11-10 15:00:00', '2026-12-10 15:00:00');
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
        $this->spend($user, 5000);

        // Cancelled (back on the free plan) and taken again the next day.
        $this->travelTo(Carbon::parse('2026-11-20 08:00:00'));
        $this->onPlan($user, $this->free, '2026-11-20 08:00:00', '2026-12-20 08:00:00');
        $this->assertSame('2026-11', CreditPeriod::current($user->id)['key']);
        $this->travelTo(Carbon::parse('2026-11-21 08:00:00'));
        $this->onPlan($user, $this->starter, '2026-11-21 08:00:00', '2026-12-21 08:00:00');
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_gifts_and_api_key_spending_carry_over_a_plan_change(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $gifts = app(CharGiftService::class);
        $gifts->grant(Subscription::query()->where('user_id', $user->id)->sole(), 5000, 'Sorry for the outage');
        $key = ApiKey::issue($user, 'Agency', null, 20000)['key'];
        ApiKeyUsage::apply($key->id, CreditPeriod::current($user->id)['key'], 3, 12000);
        $this->spend($user, 12000);

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->onPlan($user, $this->starter, '2026-10-15 12:00:00', '2026-11-15 12:00:00');

        $this->assertSame(5000, $gifts->activeGiftChars($user->id));
        $gift = CharGift::query()->where('period_ym', '2026-10-15 12:00')->sole();
        $this->assertSame('Sorry for the outage', $gift->note);
        $this->assertSame('2026-10-05 10:00:00', $gift->created_at->toDateTimeString());
        // The key's own budget goes on from what it spent.
        $this->assertSame(12000, $key->creditsUsed());
        $this->assertSame(3, $key->requestsMade());

        // The gift can still be taken back, in the new period.
        $gifts->revoke($gift);
        $this->assertSame(0, $gifts->activeGiftChars($user->id));
        $this->assertSame(12000, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_the_usage_is_carried_once_and_a_period_that_comes_back_keeps_its_count(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $this->spend($user, 1000);

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->onPlan($user, $this->starter, '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        // The same change seen again (the webhook after the page): nothing more.
        $this->onPlan($user, $this->starter, '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $this->assertSame(1000, CreditPeriod::counter($user->id)->total_chars);
        $this->assertSame(1, UsageCounter::query()->where('period_ym', '2026-10-15 12:00')->count());

        // The old plan and dates come back (a payment taken back): its own count is kept.
        $this->onPlan($user, $this->creator, '2026-10-05 10:00:00', '2026-11-05 10:00:00');
        $this->assertSame('2026-10-05 10:00', CreditPeriod::current($user->id)['key']);
        $this->assertSame(1000, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_month_end_dates_still_work(): void
    {
        $this->switchOn('2027-01-01 00:00:00');
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2027-01-31 10:00:00'));
        $this->onPlan($user, $this->creator, '2027-01-31 10:00:00', '2027-02-28 10:00:00');
        $this->spend($user, 1000);

        // Renewed on 28 February: a fresh allowance until 31 March.
        $this->travelTo(Carbon::parse('2027-02-28 10:05:00'));
        $this->onPlan($user, $this->creator, '2027-02-28 10:00:00', '2027-03-31 10:00:00');
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2027-02-28 10:00', $period['key']);
        $this->assertSame('2027-03-31 10:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
        $this->spend($user, 2000);

        // A change on 29 March is still inside that period, which runs to the 31st.
        $this->travelTo(Carbon::parse('2027-03-29 09:00:00'));
        $this->onPlan($user, $this->starter, '2027-03-29 09:00:00', '2027-04-29 09:00:00');
        $this->assertSame(2000, CreditPeriod::counter($user->id)->total_chars);

        // A yearly plan from 31 January starts again on the last day of the shorter months.
        $yearly = User::factory()->create();
        $this->travelTo(Carbon::parse('2027-01-31 10:00:00'));
        $this->onPlan($yearly, $this->creator, '2027-01-31 10:00:00', '2028-01-31 10:00:00');
        $this->travelTo(Carbon::parse('2027-03-05 12:00:00'));
        $period = CreditPeriod::current($yearly->id);
        $this->assertSame('2027-02-28 10:00', $period['key']);
        $this->assertSame('2027-03-31 10:00:00', $period['ends_at']->toDateTimeString());
        $this->travelTo(Carbon::parse('2027-04-02 12:00:00'));
        $this->assertSame('2027-03-31 10:00', CreditPeriod::current($yearly->id)['key']);
    }

    public function test_a_plan_paid_by_hand_again_before_it_ends_gets_its_new_month_after_the_one_paid_for(): void
    {
        $this->switchOn('2026-10-01 00:00:00');
        $user = User::factory()->create();
        $reviewer = User::factory()->create(['is_admin' => true]);
        $payments = app(ManualPaymentService::class);
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $payments->approve($this->manualPayment($user), $reviewer);
        $this->spend($user, 900000);

        // Out of credits on the 25th, the member pays again: the month paid for runs to
        // its end with what it used, and the new month follows it.
        $this->travelTo(Carbon::parse('2026-10-25 10:00:00'));
        $payments->approve($this->manualPayment($user), $reviewer);

        $this->assertSame('2026-12-01 10:00:00', $this->planEnd($user));
        $counter = CreditPeriod::counter($user->id);
        $this->assertSame('2026-10-01 10:00', $counter->period_ym);
        $this->assertSame(900000, $counter->total_chars);

        // The new month starts where the paid one ends, with a fresh allowance.
        $this->travelTo(Carbon::parse('2026-11-01 10:05:00'));
        $period = CreditPeriod::current($user->id);
        $this->assertSame('2026-11-01 10:00', $period['key']);
        $this->assertSame('2026-12-01 10:00:00', $period['ends_at']->toDateTimeString());
        $this->assertSame(0, CreditPeriod::counter($user->id)->total_chars);
    }

    public function test_renewing_by_hand_when_reminded_keeps_the_days_already_paid_for(): void
    {
        $this->switchOn('2026-09-01 00:00:00');
        $user = User::factory()->create();
        $reviewer = User::factory()->create(['is_admin' => true]);
        $payments = app(ManualPaymentService::class);
        $this->travelTo(Carbon::parse('2026-09-26 10:00:00'));
        $payments->approve($this->manualPayment($user), $reviewer);
        $this->assertSame('2026-10-26 10:00:00', $this->planEnd($user));

        // "Your plan ends in 7 days ... To keep it, renew": the member pays again on 19 October.
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));
        $payments->approve($this->manualPayment($user), $reviewer);
        $this->assertSame('2026-11-26 10:00:00', $this->planEnd($user), 'The 7 days already paid for are kept.');

        // A yearly payment follows on in the same way.
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $payments->approve($this->manualPayment($user, 'yearly'), $reviewer);
        $this->assertSame('2027-11-26 10:00:00', $this->planEnd($user));

        // Paid after the plan ended: the new month starts on the day it is paid.
        $this->travelTo(Carbon::parse('2027-12-03 10:00:00'));
        $payments->approve($this->manualPayment($user), $reviewer);
        $this->assertSame('2028-01-03 10:00:00', $this->planEnd($user));
        $this->assertSame('2027-12-03 10:00', CreditPeriod::current($user->id)['key']);
    }

    private function planEnd(User $user): string
    {
        return Subscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail()->current_period_end->toDateTimeString();
    }

    private function switchOn(string $at): void
    {
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, $at, 'billing');
    }

    private function onPlan(User $user, Plan $plan, string $start, string $end): void
    {
        app(PlanAccessService::class)->assignPlan($user, $plan, 'active', Carbon::parse($end), Carbon::parse($start));
    }

    /** One generation of $chars credits (15 credits a second of audio). */
    private function spend(User $user, int $chars): void
    {
        $counter = CreditPeriod::counter($user->id);
        $counter->increment('total_requests');
        $counter->increment('total_chars', $chars);
        $counter->addSeconds(intdiv($chars, 15));
    }

    private function manualPayment(User $user, string $cycle = 'monthly'): ManualPaymentRequest
    {
        return app(ManualPaymentService::class)->createRequest(
            $user,
            $this->creator,
            $cycle,
            'instapay',
            ['amount_minor' => 99900, 'currency' => 'EGP', 'country_code' => 'EG'],
            ['country_code' => 'EG', 'lines' => ['0123456789']],
        );
    }
}
