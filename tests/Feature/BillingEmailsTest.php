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

    /**
     * @return Collection<int, \Symfony\Component\Mailer\SentMessage>
     */
    private function sentEmails(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function cancelledEvent(string $subscriptionId): object
    {
        return json_decode(json_encode([
            'id' => 'evt_'.$subscriptionId,
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => $subscriptionId,
                'object' => 'subscription',
                'customer' => 'cus_mail',
                'canceled_at' => now()->timestamp,
                'items' => ['object' => 'list', 'data' => []],
            ]],
        ]));
    }
}
