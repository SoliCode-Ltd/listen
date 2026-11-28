<?php

namespace Tests\Feature;

use App\Filament\Widgets\CancellationReasonStats;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Services\Billing\CancellationLog;
use App\Services\StripeWebhookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Billing → Cancellations counts every cancellation, wherever it was made: the cancel dialog,
 * Stripe itself, or before the dialog asked why (cancellations:import). Once each, never a
 * subscription Stripe ended for a failed payment, and not once the member kept their plan.
 */
class CancellationLogTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-28 10:00:00', 'UTC'));
        config(['listen.live_actions' => true]);
        $this->plan = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'stripe_price_monthly' => 'price_creator_m']);
        $this->member = User::factory()->create(['email' => 'member@example.test']);
        $this->member->forceFill(['stripe_id' => 'cus_log'])->save();
        Subscription::withoutEvents(fn () => Subscription::create([
            'user_id' => $this->member->id, 'plan_id' => $this->plan->id, 'status' => 'active', 'current_period_end' => now()->addDays(17),
        ]));
        // Billing emails are not what this is about.
        SiteSetting::set('billing_webhook_emails', '0', 'billing');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_cancellation_in_stripe_is_counted_with_the_reason_its_survey_gave(): void
    {
        $this->event('customer.subscription.updated', $this->stripeSubscription('sub_portal', [
            'cancel_at_period_end' => true, 'cancel_at' => now()->addDays(17)->timestamp, 'canceled_at' => now()->subMinute()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested', 'feedback' => 'too_expensive', 'comment' => 'Too much for me now'],
        ]), ['cancel_at_period_end' => false]);

        $row = SubscriptionCancellation::query()->sole();
        $this->assertSame('sub_portal', $row->stripe_subscription_id);
        $this->assertSame(SubscriptionCancellation::SOURCE_STRIPE, $row->source);
        $this->assertSame('price', $row->reason);
        $this->assertSame('Too much for me now', $row->note);
        $this->assertSame('2026-12-15', $row->ends_at->toDateString());
        $this->assertSame('2026-11-28 09:59:00', $row->cancelled_at->toDateTimeString());
        $this->assertSame('Creator', $row->plan_name);
    }

    public function test_one_made_in_the_cancel_dialog_is_counted_once_when_stripe_tells_it_too(): void
    {
        // The dialog saves it first; Stripe's notice can even arrive before it is marked done.
        foreach ([now(), null] as $cancelledAt) {
            SubscriptionCancellation::query()->delete();
            SubscriptionCancellation::create([
                'user_id' => $this->member->id, 'stripe_subscription_id' => 'sub_site', 'source' => 'site',
                'reason' => 'quality', 'cancelled_at' => $cancelledAt,
            ]);
            $this->event('customer.subscription.updated', $this->stripeSubscription('sub_site', [
                'cancel_at_period_end' => true, 'cancel_at' => now()->addDays(17)->timestamp, 'canceled_at' => now()->timestamp,
                'cancellation_details' => ['reason' => 'cancellation_requested'],
            ]), ['cancel_at_period_end' => false]);

            $this->assertSame(1, SubscriptionCancellation::query()->count(), $cancelledAt ? 'marked done' : 'still on its way');
            $this->assertSame('quality', SubscriptionCancellation::query()->sole()->reason);
        }
    }

    public function test_turning_renewal_back_on_takes_it_out_of_the_count(): void
    {
        $row = SubscriptionCancellation::create([
            'user_id' => $this->member->id, 'stripe_subscription_id' => 'sub_back', 'source' => 'site',
            'reason' => 'price', 'cancelled_at' => now()->subDay(),
        ]);
        $this->event('customer.subscription.updated', $this->stripeSubscription('sub_back', ['cancel_at_period_end' => false, 'cancel_at' => null]), ['cancel_at_period_end' => true]);

        $this->assertNotNull($row->fresh()->resumed_at);
        $this->assertSame(0, SubscriptionCancellation::query()->counted()->count());

        // Cancelled again later: a new cancellation, counted.
        $this->event('customer.subscription.updated', $this->stripeSubscription('sub_back', [
            'cancel_at_period_end' => true, 'cancel_at' => now()->addDays(17)->timestamp, 'canceled_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]), ['cancel_at_period_end' => false]);
        $this->assertSame(1, SubscriptionCancellation::query()->counted()->count());
        $this->assertSame(2, SubscriptionCancellation::query()->count());
    }

    public function test_a_subscription_stripe_ended_for_a_failed_payment_is_not_a_cancellation(): void
    {
        $this->event('customer.subscription.deleted', $this->stripeSubscription('sub_failed', [
            'status' => 'canceled', 'canceled_at' => now()->timestamp, 'ended_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'payment_failed'],
        ]));
        $this->assertSame(0, SubscriptionCancellation::query()->count());

        // Ended at once on request (in Stripe), with nothing scheduled before: counted.
        $this->event('customer.subscription.deleted', $this->stripeSubscription('sub_now', [
            'status' => 'canceled', 'canceled_at' => now()->timestamp, 'ended_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]));
        $this->assertSame(['sub_now'], SubscriptionCancellation::query()->pluck('stripe_subscription_id')->all());

        // A scheduled one reaching its end is the same cancellation, not a second.
        $this->event('customer.subscription.deleted', $this->stripeSubscription('sub_now', [
            'status' => 'canceled', 'canceled_at' => now()->timestamp, 'ended_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]));
        $this->assertSame(1, SubscriptionCancellation::query()->count());
    }

    public function test_a_subscription_the_site_ended_itself_is_not_a_cancellation(): void
    {
        $endedNow = fn (string $id) => $this->stripeSubscription($id, [
            'status' => 'canceled', 'cancel_at_period_end' => false, 'canceled_at' => now()->timestamp, 'ended_at' => now()->timestamp,
            'latest_invoice' => 'in_'.$id, 'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]);

        // The grace job, account deletion or a replaced checkout: marked just before the site asks Stripe.
        CancellationLog::siteEnds('sub_by_site');
        $this->event('customer.subscription.deleted', $endedNow('sub_by_site'));

        // A free trial an admin (or a blocked account) ended.
        BillingTrial::query()->create(['user_id' => $this->member->id, 'plan_id' => $this->plan->id, 'status' => BillingTrial::STATUS_BLOCKED,
            'stripe_subscription_id' => 'sub_trial_blocked']);
        $this->event('customer.subscription.deleted', $endedNow('sub_trial_blocked'));

        $this->assertSame(0, SubscriptionCancellation::query()->count());

        // One the member set to end, reaching its end with no earlier notice: still their cancellation.
        CancellationLog::siteEnds('sub_set_to_end');
        $this->event('customer.subscription.deleted', $this->stripeSubscription('sub_set_to_end', [
            'status' => 'canceled', 'cancel_at_period_end' => true, 'canceled_at' => now()->subDays(20)->timestamp, 'ended_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]));
        $this->assertSame(['sub_set_to_end'], SubscriptionCancellation::query()->pluck('stripe_subscription_id')->all());
    }

    public function test_rows_the_dialog_saved_before_the_subscription_was_kept_get_it(): void
    {
        $other = User::factory()->create();
        foreach ([['sub_older', null], ['sub_ending', now()->addDays(10)]] as [$id, $endsAt]) {
            BillingSubscription::query()->create(['user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => $id,
                'stripe_status' => 'active', 'stripe_price' => 'price_creator_m', 'ends_at' => $endsAt]);
        }
        BillingSubscription::query()->create(['user_id' => $other->id, 'type' => 'default', 'stripe_id' => 'sub_others',
            'stripe_status' => 'active', 'stripe_price' => 'price_creator_m']);
        $mine = SubscriptionCancellation::create(['user_id' => $this->member->id, 'reason' => 'price', 'cancelled_at' => now()->subHour()]);
        $theirs = SubscriptionCancellation::create(['user_id' => $other->id, 'reason' => 'other', 'cancelled_at' => now()->subHour()]);
        $none = SubscriptionCancellation::create(['user_id' => User::factory()->create()->id, 'reason' => 'other', 'cancelled_at' => now()->subHour()]);

        $this->assertSame(2, CancellationLog::fillMissingSubscriptionIds());
        $this->assertSame('sub_ending', $mine->fresh()->stripe_subscription_id, 'The one set to end.');
        $this->assertSame('sub_others', $theirs->fresh()->stripe_subscription_id);
        $this->assertNull($none->fresh()->stripe_subscription_id);

        // Found again: Stripe's notice at the end counts no second one, and Keep my plan takes it out.
        $this->event('customer.subscription.deleted', $this->stripeSubscription('sub_ending', [
            'status' => 'canceled', 'cancel_at_period_end' => true, 'canceled_at' => now()->subHour()->timestamp, 'ended_at' => now()->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]));
        $this->assertSame(1, SubscriptionCancellation::query()->where('user_id', $this->member->id)->count());
        app(CancellationLog::class)->markResumed('sub_ending');
        $this->assertNotNull($mine->fresh()->resumed_at);
        $this->assertSame(0, DB::table('subscription_cancellations')->where('id', $mine->id)->whereNull('resumed_at')->count());
    }

    public function test_another_sites_customer_on_the_shared_stripe_account_is_never_recorded(): void
    {
        $this->event('customer.subscription.updated', $this->stripeSubscription('sub_other', [
            'customer' => 'cus_not_ours', 'cancel_at_period_end' => true, 'cancel_at' => now()->addDays(5)->timestamp,
            'cancellation_details' => ['reason' => 'cancellation_requested'],
        ]), ['cancel_at_period_end' => false]);

        $this->assertSame(0, SubscriptionCancellation::query()->count());
    }

    public function test_the_import_brings_past_cancellations_from_stripe_once_and_writes_only_with_write(): void
    {
        $stripe = new class implements ClientInterface
        {
            public array $subscriptions = [];

            public array $paths = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
                $this->paths[] = strtolower((string) $method).' '.$path;
                $id = (string) preg_replace('#^/v1/subscriptions/#', '', $path);

                return isset($this->subscriptions[$id])
                    ? [json_encode($this->subscriptions[$id]), 200, []]
                    : [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']]), 404, []];
            }
        };
        ApiRequestor::setHttpClient($stripe);
        // The fake answers; the Stripe library only needs a key to send the request at all.
        config(['cashier.secret' => 'sk_test_fake']);
        $old = fn (string $id, array $fields) => ['id' => $id, 'object' => 'subscription', 'customer' => 'cus_log', 'items' => ['object' => 'list', 'data' => []]] + $fields;
        $stripe->subscriptions = [
            'sub_old_price' => $old('sub_old_price', ['status' => 'canceled', 'cancel_at_period_end' => true, 'canceled_at' => Carbon::parse('2026-10-03')->timestamp,
                'ended_at' => Carbon::parse('2026-11-01')->timestamp, 'cancellation_details' => ['reason' => 'cancellation_requested', 'feedback' => 'unused']]),
            'sub_old_failed' => $old('sub_old_failed', ['status' => 'canceled', 'canceled_at' => Carbon::parse('2026-10-10')->timestamp,
                'ended_at' => Carbon::parse('2026-10-10')->timestamp, 'cancellation_details' => ['reason' => 'payment_failed']]),
            'sub_old_kept' => $old('sub_old_kept', ['status' => 'active', 'cancel_at_period_end' => false, 'cancel_at' => null, 'canceled_at' => null]),
            'sub_scheduled' => $old('sub_scheduled', ['status' => 'active', 'cancel_at_period_end' => true, 'cancel_at' => Carbon::parse('2026-12-20')->timestamp,
                'canceled_at' => Carbon::parse('2026-11-20')->timestamp, 'cancellation_details' => ['reason' => 'cancellation_requested']]),
            // Ended at once on request in Stripe, its last invoice paid: counted.
            'sub_ended_now' => $old('sub_ended_now', ['status' => 'canceled', 'cancel_at_period_end' => false, 'canceled_at' => Carbon::parse('2026-11-05')->timestamp,
                'ended_at' => Carbon::parse('2026-11-05')->timestamp, 'cancellation_details' => ['reason' => 'cancellation_requested'],
                'latest_invoice' => ['id' => 'in_paid', 'object' => 'invoice', 'status' => 'paid']]),
            // Ended at once by the site's grace job: its renewal was never paid (voided). Stripe
            // still says "cancellation requested".
            'sub_grace_ended' => $old('sub_grace_ended', ['status' => 'canceled', 'cancel_at_period_end' => false, 'canceled_at' => Carbon::parse('2026-11-08')->timestamp,
                'ended_at' => Carbon::parse('2026-11-08')->timestamp, 'cancellation_details' => ['reason' => 'cancellation_requested'],
                'latest_invoice' => ['id' => 'in_void', 'object' => 'invoice', 'status' => 'void']]),
            // A free trial an admin ended: its $0 invoice is paid, but the trial says who ended it.
            'sub_trial_admin' => $old('sub_trial_admin', ['status' => 'canceled', 'cancel_at_period_end' => false, 'canceled_at' => Carbon::parse('2026-11-09')->timestamp,
                'ended_at' => Carbon::parse('2026-11-09')->timestamp, 'cancellation_details' => ['reason' => 'cancellation_requested'],
                'latest_invoice' => ['id' => 'in_zero', 'object' => 'invoice', 'status' => 'paid']]),
        ];
        BillingTrial::query()->create(['user_id' => $this->member->id, 'plan_id' => $this->plan->id, 'status' => BillingTrial::STATUS_CANCELED,
            'stripe_subscription_id' => 'sub_trial_admin', 'canceled_at' => Carbon::parse('2026-11-09')]);
        foreach (['sub_old_price' => 'canceled', 'sub_old_failed' => 'canceled', 'sub_old_kept' => 'active', 'sub_scheduled' => 'active', 'sub_listed' => 'active',
            'sub_ended_now' => 'canceled', 'sub_grace_ended' => 'canceled', 'sub_trial_admin' => 'canceled'] as $id => $status) {
            BillingSubscription::query()->create([
                'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => $id, 'stripe_status' => $status,
                'stripe_price' => 'price_creator_m', 'ends_at' => now()->addDays(5),
            ]);
        }
        // Already listed (the cancel dialog): not read again.
        SubscriptionCancellation::create(['user_id' => $this->member->id, 'stripe_subscription_id' => 'sub_listed', 'source' => 'site', 'reason' => 'price', 'cancelled_at' => now()]);

        $this->artisan('cancellations:import')->expectsOutputToContain('Would add: 3')
            ->expectsOutputToContain('2 ended by the site')->assertSuccessful();
        $this->assertSame(1, SubscriptionCancellation::query()->count(), 'A preview writes nothing.');
        $this->assertNotContains('get /v1/subscriptions/sub_listed', $stripe->paths);
        $this->assertSame([], array_filter($stripe->paths, fn (string $p): bool => ! str_starts_with($p, 'get ')), 'Stripe is only read.');

        $this->artisan('cancellations:import --write')->expectsOutputToContain('Added: 3')->assertSuccessful();
        $imported = SubscriptionCancellation::query()->where('source', SubscriptionCancellation::SOURCE_IMPORT)->orderBy('cancelled_at')->get();
        $this->assertSame(['sub_old_price', 'sub_ended_now', 'sub_scheduled'], $imported->pluck('stripe_subscription_id')->all());
        $this->assertSame('not_needed', $imported[0]->reason);
        $this->assertSame('2026-10-03', $imported[0]->cancelled_at->toDateString());
        $this->assertNull($imported[2]->reason);
        $this->assertSame('2026-12-20', $imported[2]->ends_at->toDateString());

        // Again: nothing more.
        $this->artisan('cancellations:import --write')->expectsOutputToContain('Added: 0')->assertSuccessful();
        $this->assertSame(4, SubscriptionCancellation::query()->count());
    }

    public function test_the_import_writes_only_on_the_live_site(): void
    {
        config(['listen.live_actions' => false]);

        $this->artisan('cancellations:import --write')->assertFailed();
    }

    public function test_the_admin_counts_include_cancellations_without_a_reason_and_leave_out_kept_ones(): void
    {
        $rows = [
            ['reason' => 'price', 'cancelled_at' => now()->subDays(3)],
            ['reason' => null, 'source' => 'import', 'cancelled_at' => now()->subDays(40)],
            ['reason' => 'quality', 'cancelled_at' => now()->subDays(2), 'resumed_at' => now()->subDay()],
            ['reason' => null, 'source' => 'import', 'cancelled_at' => now()->subDays(200)],
        ];
        foreach ($rows as $i => $row) {
            SubscriptionCancellation::create(['user_id' => $this->member->id, 'stripe_subscription_id' => 'sub_'.$i] + $row);
        }

        $stats = collect((new \ReflectionMethod(CancellationReasonStats::class, 'getStats'))->invoke(new CancellationReasonStats))
            ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()]);

        $this->assertSame('2', $stats['Cancelled, last 90 days']);
        $this->assertSame('1', $stats["The price didn't suit them"]);
        $this->assertSame('0', $stats['Voices or dialects not as expected'], 'Kept their plan: not counted.');
        $this->assertSame('1', $stats['No reason given']);
        $this->assertSame('1', $stats['Kept their plan']);
    }

    /** @param array<string, mixed> $fields */
    private function stripeSubscription(string $id, array $fields): array
    {
        // The test's own fields win (the left side of + keeps its keys).
        return $fields + ['id' => $id, 'object' => 'subscription', 'customer' => 'cus_log', 'status' => 'active', 'items' => ['object' => 'list', 'data' => []]];
    }

    /** @param array<string, mixed> $previous */
    private function event(string $type, array $object, ?array $previous = null): void
    {
        $data = ['object' => $object] + ($previous !== null ? ['previous_attributes' => $previous] : []);
        app(StripeWebhookEmailService::class)->sendForEvent(json_decode(json_encode(['id' => 'evt_'.uniqid(), 'type' => $type, 'data' => $data])));
    }
}
