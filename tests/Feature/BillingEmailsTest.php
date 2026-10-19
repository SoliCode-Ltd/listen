<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeWebhookEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
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

    public function test_the_receipt_for_a_plan_change_names_the_new_plan_and_its_renewal_date(): void
    {
        config(['services.stripe.prices' => [
            'starter' => ['monthly' => 'price_starter_m'],
            'creator' => ['monthly' => 'price_creator_m'],
        ]]);
        $oldRenewal = now()->addDays(9)->startOfDay();
        $newRenewal = now()->addMonth()->startOfDay();
        $emails = app(StripeWebhookEmailService::class);

        // Stripe lists the credit for the old plan first. As the current API sends it...
        $emails->sendForEvent($this->event('invoice.paid', $this->planChangeInvoice('in_change', [
            [
                'description' => 'Unused time on Starter after 5 Oct 2026', 'amount' => -450,
                'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => true]],
                'period' => ['start' => now()->timestamp, 'end' => $oldRenewal->timestamp],
            ],
            [
                'description' => '1 × Creator (at $19.00 / month)', 'amount' => 1900,
                'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => false]],
                'period' => ['start' => now()->timestamp, 'end' => $newRenewal->timestamp],
            ],
        ])));
        // ...and as older API versions did, with the line's own price.
        $emails->sendForEvent($this->event('invoice.paid', $this->planChangeInvoice('in_change_old_api', [
            [
                'description' => 'Unused time on Starter after 5 Oct 2026', 'amount' => -450, 'proration' => true,
                'price' => ['id' => 'price_starter_m'], 'period' => ['start' => now()->timestamp, 'end' => $oldRenewal->timestamp],
            ],
            [
                'description' => '1 × Creator (at $19.00 / month)', 'amount' => 1900, 'proration' => false,
                'price' => ['id' => 'price_creator_m'], 'period' => ['start' => now()->timestamp, 'end' => $newRenewal->timestamp],
            ],
        ])));

        $sent = $this->sentEmails()->map(fn ($message): string => (string) $message->getOriginalMessage()->getHtmlBody())->values();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('1 × Creator (at $19.00 / month)', $sent[0]);
        $this->assertStringContainsString('Creator (Monthly)', $sent[1]);
        foreach ($sent as $html) {
            $this->assertStringNotContainsString('Starter', $html);
            $this->assertStringContainsString($newRenewal->format('M j, Y'), $html);
            $this->assertStringNotContainsString($oldRenewal->format('M j, Y'), $html);
        }
    }

    /**
     * A paid plan change's invoice (Starter to Creator, charged at once).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function planChangeInvoice(string $id, array $lines): array
    {
        return [
            'id' => $id, 'customer' => 'cus_mail', 'subscription' => 'sub_change', 'amount_paid' => 1450, 'currency' => 'usd',
            'billing_reason' => 'subscription_update', 'status_transitions' => ['paid_at' => now()->timestamp],
            'lines' => ['data' => $lines],
        ];
    }

    public function test_the_billing_settings_switch_decides_whether_they_are_sent(): void
    {
        $emails = app(StripeWebhookEmailService::class);

        // Switched off in Billing Settings, though the server's setting says on.
        config(['listen.billing.webhook_email_enabled' => true]);
        SiteSetting::set('billing_webhook_emails', '0', 'billing');
        $emails->sendForEvent($this->cancelledEvent('sub_off'));
        $this->assertCount(0, $this->sentEmails());

        // Switched on there, though the server's setting says off.
        config(['listen.billing.webhook_email_enabled' => false]);
        SiteSetting::set('billing_webhook_emails', '1', 'billing');
        $emails->sendForEvent($this->cancelledEvent('sub_on'));
        $this->assertCount(1, $this->sentEmails());

        // Never saved: the server's setting decides.
        SiteSetting::query()->where('key', 'billing_webhook_emails')->delete();
        Cache::forget('site_setting.billing_webhook_emails');
        $emails->sendForEvent($this->cancelledEvent('sub_default'));
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_an_email_that_failed_to_send_goes_out_when_the_event_comes_again(): void
    {
        $mailer = app('mailer');
        $working = $mailer->getSymfonyTransport();
        $mailer->setSymfonyTransport(new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('The mailbox is busy.');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });

        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_retry'));

        // Stripe sends the event again once the mailbox works.
        $mailer->setSymfonyTransport($working);
        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_retry'));
        $this->assertCount(1, $this->sentEmails());

        // Sent once: a third delivery sends nothing more.
        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_retry'));
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_phone_signups_internal_addresses_get_no_billing_email(): void
    {
        $this->member->forceFill(['email' => 'phone+201000000000@users.listen.local'])->save();

        app(StripeWebhookEmailService::class)->sendForEvent($this->cancelledEvent('sub_phone'));

        $this->assertCount(0, $this->sentEmails());
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
