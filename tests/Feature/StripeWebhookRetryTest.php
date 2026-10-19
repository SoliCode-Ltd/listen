<?php

namespace Tests\Feature;

use App\Services\Billing\CharPackCardPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A Stripe event counts as handled only once it has been: if the worker dies
 * halfway, Stripe's next delivery of it is still handled.
 */
class StripeWebhookRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{handled:bool, being_handled:bool}> */
    private array $seenWhileHandling = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => '']);
        Cache::flush();
    }

    private function handlerThat(?\Throwable $fails = null): void
    {
        $this->mock(CharPackCardPayment::class, function ($mock) use ($fails) {
            $mock->shouldReceive('handlePaymentSucceeded')->andReturnUsing(function () use ($fails) {
                // What a worker that died right here would leave behind.
                $this->seenWhileHandling[] = [
                    'handled' => Cache::has('stripe_webhook_event_processed:evt_retry'),
                    'being_handled' => Cache::has('stripe_webhook_event_processing:evt_retry'),
                ];

                if ($fails) {
                    throw $fails;
                }
            });
        });
    }

    private function deliver()
    {
        return $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_retry',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_retry', 'customer' => 'cus_retry', 'metadata' => ['listen_purpose' => 'char_pack']]],
        ]);
    }

    public function test_an_event_is_marked_handled_only_after_its_handler_finished(): void
    {
        $this->handlerThat();

        $this->deliver()->assertOk()->assertSee('Webhook Handled');

        $this->assertSame([['handled' => false, 'being_handled' => true]], $this->seenWhileHandling);

        // Delivered again later: answered without handling it twice.
        $this->deliver()->assertOk()->assertSee('Webhook already handled');
        $this->assertCount(1, $this->seenWhileHandling);
    }

    public function test_a_worker_that_died_halfway_only_holds_the_event_back_for_a_few_minutes(): void
    {
        $this->handlerThat();
        // The first delivery's worker died while handling it.
        Cache::put('stripe_webhook_event_processing:evt_retry', now()->timestamp, now()->addMinutes(10));

        // Turned away (not a 2xx), so Stripe keeps sending it.
        $this->deliver()->assertStatus(409);
        $this->assertSame([], $this->seenWhileHandling);

        $this->travel(11)->minutes();

        $this->deliver()->assertOk()->assertSee('Webhook Handled');
        $this->assertCount(1, $this->seenWhileHandling);
    }

    public function test_a_failed_handler_lets_the_next_delivery_handle_the_event(): void
    {
        $this->handlerThat(new \RuntimeException('Database went away.'));
        $this->deliver()->assertStatus(500);

        $this->handlerThat();
        $this->deliver()->assertOk()->assertSee('Webhook Handled');

        $this->assertCount(2, $this->seenWhileHandling);
        $this->assertFalse(Cache::has('stripe_webhook_event_processing:evt_retry'));
    }
}
