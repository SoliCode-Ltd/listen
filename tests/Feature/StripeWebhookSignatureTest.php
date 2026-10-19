<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CharPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Payment events are believed only when Stripe signed them. On the servers a
 * missing signing secret refuses every event, and an event with a missing or
 * wrong signature is refused, so nobody can post a made-up payment and get
 * credits for it. (Here a pack payment stands for every kind of event.)
 */
class StripeWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_signing_secret';

    public function test_on_the_servers_without_a_signing_secret_every_event_is_refused(): void
    {
        config(['services.stripe.webhook_secret' => '']);
        $this->app['env'] = 'production';
        [$user, $payload] = $this->paidPackEvent();

        $this->webhook($payload)->assertStatus(503);

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
    }

    public function test_an_event_stripe_did_not_sign_is_refused(): void
    {
        config(['services.stripe.webhook_secret' => self::SECRET]);
        [$user, $payload] = $this->paidPackEvent();

        $this->webhook($payload)->assertStatus(400);
        $this->webhook($payload, $this->signature($payload, 'whsec_someone_elses_secret'))->assertStatus(400);
        $this->webhook($payload, $this->signature(str_replace('"amount":200', '"amount":1', $payload), self::SECRET))->assertStatus(400);
        $this->webhook($payload, $this->signature($payload, self::SECRET, time() - 3600))->assertStatus(400);

        $this->assertSame(0, app(CharPackService::class)->remaining($user->id));
    }

    public function test_an_event_stripe_signed_is_handled(): void
    {
        config(['services.stripe.webhook_secret' => self::SECRET]);
        [$user, $payload] = $this->paidPackEvent();

        $this->webhook($payload, $this->signature($payload, self::SECRET))->assertOk();

        $this->assertSame(20000, app(CharPackService::class)->remaining($user->id));
    }

    private function webhook(string $payload, ?string $signature = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/stripe/webhook', [], [], [], $server, $payload);
    }

    /** The Stripe-Signature header Stripe sends: an HMAC of "time.body". */
    private function signature(string $payload, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    }

    /**
     * A member who started a pack purchase, and the event saying it was paid.
     *
     * @return array{0: User, 1: string}
     */
    private function paidPackEvent(): array
    {
        Plan::factory()->create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000,
            'monthly_requests' => 50, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
        ]);
        $pack = Plan::factory()->create([
            'slug' => 'pack', 'name' => 'Premium Pack', 'kind' => Plan::KIND_PACK, 'pack_days' => 30,
            'price_cents' => 200, 'monthly_chars' => 20000, 'monthly_requests' => 0,
            'max_chars_per_request' => 5000, 'voice_tier' => 'hd', 'is_active' => true,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        $purchase = app(CharPackService::class)->startPurchase($user, $pack, 'card', 200, 'USD');
        $purchase->forceFill(['stripe_payment_intent_id' => 'pi_hook'])->save();

        return [$user, (string) json_encode([
            'id' => 'evt_pack_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_hook', 'object' => 'payment_intent', 'amount' => 200, 'amount_received' => 200,
                'customer' => 'cus_x', 'metadata' => ['listen_purpose' => 'char_pack', 'listen_purchase_id' => (string) $purchase->id],
            ]],
        ])];
    }
}
