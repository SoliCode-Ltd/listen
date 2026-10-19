<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeWebhookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripePlanSyncClient;
use Tests\TestCase;

/**
 * The billing emails Stripe's events send members (StripeWebhookEmailService).
 */
class BillingEmailsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // A plan name looked up from a price is answered here, never by Stripe.
        ApiRequestor::setHttpClient(new FakeStripePlanSyncClient);

        $plan = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900]);
        $this->member = User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@example.test']);
        $this->member->forceFill(['stripe_id' => 'cus_mail'])->save();
        Subscription::withoutEvents(fn () => Subscription::create([
            'user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth(),
        ]));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_billing_emails_go_out_from_the_live_site_only(): void
    {
        config(['listen.live_actions' => false]);
        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_staging'));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_grace',
            'stripe_status' => 'active', 'ends_at' => now()->addDays(5),
        ]);
        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);
        $this->assertCount(0, $this->sentEmails());

        config(['listen.live_actions' => true]);
        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_live'));
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame('Subscription canceled', $this->sentEmails()->first()->getOriginalMessage()->getSubject());
    }

    public function test_billing_emails_never_name_the_payment_processor_or_show_its_ids_and_read_left_to_right(): void
    {
        // The site was in Arabic when the event came in; the emails are English.
        app()->setLocale('ar');
        $emails = app(StripeWebhookEmailService::class);

        $emails->sendForEvent($this->cancelledEvent('sub_gone'));
        $emails->sendForEvent($this->event('invoice.paid', [
            'id' => 'in_paid', 'customer' => 'cus_mail', 'subscription' => 'sub_gone', 'amount_paid' => 1900, 'currency' => 'usd',
            'billing_reason' => 'subscription_cycle', 'number' => 'LISTEN-0042', 'status_transitions' => ['paid_at' => now()->timestamp],
            'lines' => ['data' => [['description' => 'Listen Creator', 'period' => ['end' => now()->addMonth()->timestamp]]]],
        ]));
        $emails->sendForEvent($this->event('checkout.session.completed', [
            'id' => 'cs_pack', 'mode' => 'payment', 'customer' => 'cus_mail', 'amount_total' => 500, 'currency' => 'usd',
            'created' => now()->timestamp, 'metadata' => ['description' => 'Premium pack'],
        ]));
        $emails->sendForEvent($this->event('charge.refunded', [
            'id' => 'ch_refund', 'customer' => 'cus_mail', 'amount_refunded' => 500, 'currency' => 'usd', 'created' => now()->timestamp,
        ]));
        $emails->sendForEvent($this->event('customer.deleted', ['id' => 'cus_mail', 'email' => 'sara@example.test']));
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_grace',
            'stripe_status' => 'active', 'ends_at' => now()->addDays(5),
        ]);
        $emails->sendCancellationScheduledForUser($this->member);

        $sent = $this->sentEmails()->map(fn ($message): string => (string) $message->getOriginalMessage()->getHtmlBody());
        $this->assertCount(6, $sent);
        foreach ($sent as $html) {
            $this->assertStringNotContainsString('Stripe', $html);
            foreach (['cus_mail', 'sub_gone', 'sub_grace', 'in_paid', 'cs_pack', 'ch_refund'] as $id) {
                $this->assertStringNotContainsString($id, $html);
            }
            $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
        }
        // The invoice number is the member's own reference, on their invoice too.
        $this->assertTrue($sent->contains(fn (string $html): bool => str_contains($html, 'LISTEN-0042')));
        $this->assertTrue($sent->contains(fn (string $html): bool => str_contains($html, 'Your billing profile was removed.')));
    }

    /**
     * @return Collection<int, \Symfony\Component\Mailer\SentMessage>
     */
    private function sentEmails(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function cancelledEvent(string $subscriptionId): object
    {
        return $this->event('customer.subscription.deleted', [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'customer' => 'cus_mail',
            'canceled_at' => now()->timestamp,
            'items' => ['object' => 'list', 'data' => []],
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function event(string $type, array $object): object
    {
        return json_decode(json_encode([
            'id' => 'evt_'.str_replace('.', '_', $type).'_'.($object['id'] ?? ''),
            'type' => $type,
            'data' => ['object' => $object],
        ]));
    }
}
