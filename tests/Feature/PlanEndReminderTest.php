<?php

namespace Tests\Feature;

use App\Filament\Pages\PlanEndReminderEmail;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingLifecycleService;
use App\Services\Newsletter\PlanEndReminder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A plan that does not renew by card is reminded before it ends (Newsletter →
 * Reminder), every time it ends, in Arabic and English, in words that say it ends. An email that could not be sent is tried
 * again, and staging never emails members.
 */
class PlanEndReminderTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(now()->setTime(9, 0));
        $this->starter = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** A Starter month paid by InstaPay that ends in $days days. */
    private function paidByHand(int $days = 3, array $state = []): Subscription
    {
        $member = User::factory()->create(['name' => 'Mona']);

        $plan = Subscription::query()->create([
            'user_id' => $member->id, 'plan_id' => $this->starter->id, 'status' => 'active',
            'billing_payment_state' => 'manual_override',
            'current_period_start' => now()->addDays($days)->subMonth(), 'current_period_end' => now()->addDays($days)->setTime(12, 0),
        ] + $state);
        $this->sentMail();

        return $plan;
    }

    private function remind(): int
    {
        return app(BillingLifecycleService::class)->sendPreRenewalReminders();
    }

    /** @return list<array{subject:string, body:string}> What was sent since the last call. */
    private function sentMail(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $sent = $transport->messages()
            ->map(fn ($message) => [
                'subject' => (string) $message->getOriginalMessage()->getSubject(),
                'body' => (string) $message->getOriginalMessage()->getTextBody(),
                'html' => (string) $message->getOriginalMessage()->getHtmlBody(),
            ])
            ->values()
            ->all();
        $transport->flush();

        return $sent;
    }

    public function test_a_plan_paid_by_hand_is_told_when_it_ends_in_both_languages(): void
    {
        $plan = $this->paidByHand(3);

        $this->assertSame(1, $this->remind());

        [$mail] = $this->sentMail();
        $this->assertSame('Your Starter plan ends in 3 days · باقة مبتدئ تنتهي بعد 3 أيام', $mail['subject']);
        $this->assertStringContainsString('Hi Mona, your Starter plan ends on '.$plan->current_period_end->format('j F Y').'. It does not renew by itself.', $mail['body']);
        $this->assertStringContainsString('أهلاً Mona', $mail['body']);
        $this->assertStringContainsString('/en/manage-subscription', $mail['html']);
        $this->assertStringContainsString('/ar/manage-subscription', $mail['html']);
        foreach (['{name}', '{plan}', '{date}', '{when}', '{days}'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $mail['html']);
        }
        $this->assertStringNotContainsString('unsubscribe', strtolower($mail['body']));
        $this->assertNotNull($plan->fresh()->billing_reminder_7d_sent_at);
    }

    public function test_the_days_are_set_in_newsletter_reminder(): void
    {
        app(PlanEndReminder::class)->save(['first_days' => '5', 'second_days' => '2']);
        $five = $this->paidByHand(5);
        $three = $this->paidByHand(3);
        $two = $this->paidByHand(2);

        $this->assertSame(2, $this->remind());
        $this->assertSame(['Your Starter plan ends in 2 days · باقة مبتدئ تنتهي بعد يومين', 'Your Starter plan ends in 5 days · باقة مبتدئ تنتهي بعد 5 أيام'], collect($this->sentMail())->pluck('subject')->sort()->values()->all());
        $this->assertNotNull($five->fresh()->billing_reminder_7d_sent_at);
        $this->assertNull($three->fresh()->billing_reminder_7d_sent_at);
        $this->assertNotNull($two->fresh()->billing_reminder_1d_sent_at);

        // Turned off: nothing goes out.
        app(PlanEndReminder::class)->save(['enabled' => false]);
        $this->paidByHand(5);
        $this->assertSame(0, $this->remind());
    }

    public function test_every_new_end_date_gets_its_own_reminders(): void
    {
        // Reminded for last month's end date, then renewed by hand.
        $plan = $this->paidByHand(1, [
            'billing_reminder_7d_sent_at' => now()->subDays(33),
            'billing_reminder_1d_sent_at' => now()->subDays(30),
        ]);

        $this->assertSame(1, $this->remind());
        $this->assertSame('Your Starter plan ends tomorrow · باقة مبتدئ تنتهي غداً', $this->sentMail()[0]['subject']);
        $this->assertTrue($plan->fresh()->billing_reminder_1d_sent_at->isToday());

        // Run again the same day: not sent twice.
        $this->assertSame(0, $this->remind());
        $this->assertSame([], $this->sentMail());
    }

    public function test_a_card_plan_gets_no_reminder_from_the_site(): void
    {
        $plan = $this->paidByHand(3);
        BillingSubscription::query()->create([
            'user_id' => $plan->user_id, 'type' => 'default', 'stripe_id' => 'sub_card',
            'stripe_status' => 'active', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);

        $this->assertSame(0, $this->remind());
        $this->assertSame([], $this->sentMail());
    }

    public function test_staging_sends_no_reminders(): void
    {
        config(['listen.live_actions' => false]);
        $plan = $this->paidByHand(3);

        $this->assertSame(0, $this->remind());

        $this->assertSame([], $this->sentMail());
        $this->assertNull($plan->fresh()->billing_reminder_7d_sent_at);
    }

    public function test_a_reminder_that_could_not_be_sent_is_tried_again(): void
    {
        $plan = $this->paidByHand(3);
        $this->mock(PlanEndReminder::class, function ($mock): void {
            $mock->shouldReceive('days')->andReturn(['first' => 3, 'second' => 1]);
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('The mailbox is over its sending limit.'));
        });

        $this->assertSame(0, $this->remind());
        $this->assertNull($plan->fresh()->billing_reminder_7d_sent_at);

        $this->app->forgetInstance(PlanEndReminder::class);
        $this->app->offsetUnset(PlanEndReminder::class);
        $this->assertSame(1, $this->remind());
        $this->assertStringContainsString('ends in 3 days', $this->sentMail()[0]['subject']);
        $this->assertNotNull($plan->fresh()->billing_reminder_7d_sent_at);
    }

    public function test_the_admin_page_shows_saves_and_previews_the_reminder(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(PlanEndReminderEmail::class)
            ->assertSee('Members who pay by card never get it')
            ->set('data.first_days', '4')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(4, app(PlanEndReminder::class)->days()['first']);

        // The last reminder must come after the first.
        Livewire::test(PlanEndReminderEmail::class)->set('data.second_days', '6')->call('save');
        $this->assertSame(1, app(PlanEndReminder::class)->days()['second']);

        $html = app(PlanEndReminder::class)->previewHtml();
        $this->assertStringContainsString('ends in 4 days', $html);
        $this->assertStringContainsString('تنتهي بعد 4 أيام', $html);
    }

    public function test_a_payment_warning_that_could_not_be_sent_is_tried_at_the_next_failure(): void
    {
        config(['cashier.secret' => 'sk_test_fake']);
        $stripe = new FakeStripeHttpClient;
        $stripe->subscription('sub_card', 'past_due', 'cus_card');
        ApiRequestor::setHttpClient($stripe);
        $member = User::factory()->create(['stripe_id' => 'cus_card']);
        $plan = Subscription::query()->create([
            'user_id' => $member->id, 'plan_id' => $this->starter->id, 'status' => 'active',
            'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth(),
        ]);
        BillingSubscription::query()->create([
            'user_id' => $member->id, 'type' => 'default', 'stripe_id' => 'sub_card',
            'stripe_status' => 'past_due', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        $this->sentMail();
        $failed = (object) ['id' => 'in_renewal', 'customer' => 'cus_card', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_card'];

        $mailer = app('mail.manager');
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('The mailbox is over its sending limit.'));
        app(BillingLifecycleService::class)->handlePaymentFailed($failed);
        $this->assertSame('past_due', $plan->fresh()->billing_payment_state);
        $this->assertNull($plan->fresh()->billing_failed_notice_sent_at);

        // Stripe tries the card again, and it fails again: the warning goes out now.
        Mail::swap($mailer);
        app(BillingLifecycleService::class)->handlePaymentFailed($failed);
        $this->assertSame(['Payment failed - action required · لم يكتمل الدفع - مطلوب إجراء منك'], array_column($this->sentMail(), 'subject'));
        $this->assertNotNull($plan->fresh()->billing_failed_notice_sent_at);
    }
}
