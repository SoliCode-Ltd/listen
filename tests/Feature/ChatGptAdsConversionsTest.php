<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettings;
use App\Http\Middleware\CaptureAdClickId;
use App\Models\ChatGptAdsEvent;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\ChatGptAdsConversions;
use App\Services\ManualPaymentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Paid plans sent to ChatGPT Ads from the server (OpenAI's Conversions API): a card
 * payment once Stripe says it is paid, with the id the page's pixel event has, and a
 * payment by hand once approved. Only for members where the consent rules do not apply.
 */
class ChatGptAdsConversionsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake', 'cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        ApiRequestor::setHttpClient(new FakeStripeHttpClient);
        Http::preventStrayRequests();

        Plan::query()->create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 300, 'monthly_chars' => 30000,
            'monthly_requests' => 60, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->creator = Plan::query()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_minutes' => 5000, 'monthly_chars' => 500000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true, 'stripe_price_monthly' => 'price_creator_m',
        ]);
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function turnOn(): ChatGptAdsConversions
    {
        $conversions = app(ChatGptAdsConversions::class);
        SiteSetting::set(ChatGptAdsConversions::ENABLED, '1', ChatGptAdsConversions::GROUP);
        SiteSetting::set(ChatGptAdsConversions::PIXEL_ID, 'X3wiXsHtetMpUTppXyHw5b', ChatGptAdsConversions::GROUP);
        $conversions->saveApiKey('capi_test_key');

        return $conversions;
    }

    private function web()
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    /** @param array<string, mixed> $extra */
    private function invoice(string $id, string $reason, int $amountPaid = 1900, array $extra = []): object
    {
        return json_decode(json_encode(array_merge([
            'id' => $id, 'object' => 'invoice', 'customer' => 'cus_gpt', 'currency' => 'usd',
            'amount_paid' => $amountPaid, 'billing_reason' => $reason, 'subscription' => 'sub_gpt',
            'status_transitions' => ['paid_at' => now()->subMinute()->timestamp],
        ], $extra)));
    }

    private function checkout(User $user, string $eventId = 'a1b2c3d4-e5f6-4711-8000-123456789abc'): void
    {
        // Stripe (faked) refuses: what the checkout came from is kept all the same, for the payment to come.
        $this->web()->actingAs($user)->withCredentials()
            ->withUnencryptedCookies([CaptureAdClickId::PIXEL_OPPREF_COOKIE => 'opp_click_123', CaptureAdClickId::PIXEL_BROWSER_COOKIE => 'ob-7a1c'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)', 'CF-Connecting-IP' => '41.33.10.20', 'Referer' => 'http://localhost/ar/billing?plan=creator'])
            ->postJson($this->localizedRoute('billing.subscribe'), ['plan' => 'creator', 'billing_cycle' => 'monthly', 'payment_method' => 'pm_card', 'ads_event_id' => $eventId]);
    }

    public function test_a_paid_card_checkout_is_sent_once_with_the_pages_event_id(): void
    {
        $conversions = $this->turnOn();
        $user = User::factory()->create(['stripe_id' => 'cus_gpt', 'email' => ' Member@Example.com', 'subscription_status_checked_at' => now()]);
        $this->checkout($user);

        $context = $user->fresh()->chatgpt_ads_context;
        $this->assertSame('opp_click_123', $context['oppref']);
        $this->assertSame('creator_monthly', $context['plan_id']);
        $this->assertSame('41.33.10.20', $context['ip']);

        // Stripe sends invoice.paid and invoice.payment_succeeded for one payment.
        foreach (['invoice.paid', 'invoice.payment_succeeded'] as $type) {
            $conversions->handleStripeEvent((object) ['type' => $type, 'data' => (object) ['object' => $this->invoice('in_gpt', 'subscription_create')]]);
        }

        $event = ChatGptAdsEvent::query()->sole();
        $this->assertSame('invoice:in_gpt', $event->reference);
        $this->assertSame('a1b2c3d4-e5f6-4711-8000-123456789abc', $event->event_id);
        $payload = $event->payload;
        $this->assertSame('subscription_created', $payload['type']);
        $this->assertSame('a1b2c3d4-e5f6-4711-8000-123456789abc', $payload['id']);
        $this->assertSame('web', $payload['action_source']);
        $this->assertSame('http://localhost/ar/billing?plan=creator', $payload['source_url']);
        $this->assertSame('opp_click_123', $payload['oppref']);
        $this->assertSame(['type' => 'plan_enrollment', 'plan_id' => 'creator_monthly', 'amount' => 1900, 'currency' => 'USD'], $payload['data']);
        $this->assertSame([hash('sha256', 'member@example.com')], $payload['user']['emails_sha256']);
        $this->assertSame([hash('sha256', (string) $user->id)], $payload['user']['external_ids_sha256']);
        $this->assertSame('41.33.10.20', $payload['user']['ip_address']);
        $this->assertSame('ob-7a1c', $payload['user']['obref']);
        $this->assertStringNotContainsString('Member@Example.com', json_encode($payload));

        // The checkout's id is used once: a later payment is a sale of its own.
        $this->assertArrayNotHasKey('event_id', $user->fresh()->chatgpt_ads_context);

        Http::fake(['bzr.openai.com/*' => Http::response(['accepted_events' => 1], 202)]);
        $this->assertTrue($conversions->send($event));
        Http::assertSent(function (HttpRequest $request) use ($payload): bool {
            return $request->url() === 'https://bzr.openai.com/v1/events?pid=X3wiXsHtetMpUTppXyHw5b'
                && $request->hasHeader('Authorization', 'Bearer capi_test_key')
                && $request['events'] === [$payload]
                && ! isset($request['validate_only']);
        });
        $this->assertSame(ChatGptAdsEvent::STATUS_SENT, $event->fresh()->status);
        // Sent once.
        $this->assertFalse($conversions->send($event->fresh()));
        Http::assertSentCount(1);
    }

    public function test_only_a_new_plan_or_a_plan_change_soon_after_a_checkout_is_sent(): void
    {
        $conversions = $this->turnOn();
        $user = User::factory()->create(['stripe_id' => 'cus_gpt', 'subscription_status_checked_at' => now()]);
        $this->checkout($user);

        // Never a renewal, a $0 invoice or another event.
        $conversions->recordPaidInvoice($this->invoice('in_renewal', 'subscription_cycle'));
        $conversions->recordPaidInvoice($this->invoice('in_zero', 'subscription_create', 0));
        $conversions->handleStripeEvent((object) ['type' => 'invoice.payment_failed', 'data' => (object) ['object' => $this->invoice('in_failed', 'subscription_create')]]);
        $this->assertSame(0, ChatGptAdsEvent::query()->count());

        // A plan change later still counts, with an id of its own.
        $conversions->recordPaidInvoice($this->invoice('in_first', 'subscription_create'));
        $conversions->recordPaidInvoice($this->invoice('in_change', 'subscription_update', 1300));
        $this->assertSame(['a1b2c3d4-e5f6-4711-8000-123456789abc', 'stripe_in_change'], ChatGptAdsEvent::query()->orderBy('id')->pluck('event_id')->all());

        // A checkout from more than a day before is not this payment's.
        $this->travel(25)->hours();
        $conversions->recordPaidInvoice($this->invoice('in_late', 'subscription_update'));
        $this->assertSame(2, ChatGptAdsEvent::query()->count());
    }

    public function test_nothing_is_kept_or_sent_while_it_is_off_or_where_the_consent_rules_apply(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_gpt', 'subscription_status_checked_at' => now()]);
        $conversions = app(ChatGptAdsConversions::class);

        // Off (as it is until the key is saved and the switch is on).
        $this->checkout($user);
        $this->assertNull($user->fresh()->chatgpt_ads_context);

        $this->turnOn();
        SiteSetting::set(ChatGptAdsConversions::ENABLED, '0', ChatGptAdsConversions::GROUP);
        $this->checkout($user);
        $this->assertNull($user->fresh()->chatgpt_ads_context);

        // On, from Europe, the UK or Switzerland (a country that is not known is ruled out the
        // same way: CaptureAdClickId::mayTrack, AdsPurchaseUploadTest).
        SiteSetting::set(ChatGptAdsConversions::ENABLED, '1', ChatGptAdsConversions::GROUP);
        foreach (['FR', 'GB', 'CH'] as $country) {
            SiteSetting::set('pricing_forced_country_code', $country, 'billing');
            $this->checkout($user);
            $this->assertNull($user->fresh()->chatgpt_ads_context, $country);
        }
        $conversions->recordPaidInvoice($this->invoice('in_gpt', 'subscription_create'));
        $this->assertSame(0, ChatGptAdsEvent::query()->count());
    }

    public function test_a_payment_by_hand_is_sent_once_the_team_approves_it(): void
    {
        $this->turnOn();
        $user = User::factory()->create();

        $this->web()->actingAs($user)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0', 'CF-Connecting-IP' => '41.33.10.21'])
            ->withCookie(CaptureAdClickId::OPPREF_COOKIE, 'opp_manual_1')
            ->post($this->localizedRoute('billing.manual.confirm'), ['plan' => 'creator', 'billing_cycle' => 'monthly', 'payment_method' => 'instapay'])
            ->assertRedirect();

        $request = ManualPaymentRequest::query()->where('user_id', $user->id)->firstOrFail();
        // The site's own copy of the click, when the browser blocked the pixel.
        $this->assertSame('opp_manual_1', $request->chatgpt_ads_context['oppref']);
        $this->assertSame(0, ChatGptAdsEvent::query()->count());

        app(ManualPaymentService::class)->approve($request, User::factory()->create(['is_admin' => true]));

        $event = ChatGptAdsEvent::query()->sole();
        $this->assertSame('manual:'.$request->id, $event->reference);
        $this->assertSame('manual_'.$request->id, $event->payload['id']);
        $this->assertSame('opp_manual_1', $event->payload['oppref']);
        $this->assertSame('creator_monthly', $event->payload['data']['plan_id']);
        $this->assertSame((int) $request->fresh()->expected_amount_minor, $event->payload['data']['amount']);
        $this->assertSame('EGP', $event->payload['data']['currency']);
    }

    public function test_a_refused_event_is_not_tried_again_and_a_busy_one_is(): void
    {
        $conversions = $this->turnOn();
        $user = User::factory()->create();
        $make = fn (string $reference, ?Carbon $at = null) => ChatGptAdsEvent::query()->create([
            'user_id' => $user->id, 'reference' => $reference, 'source' => ChatGptAdsEvent::SOURCE_CARD, 'event_id' => $reference,
            'payload' => ['id' => $reference, 'type' => 'subscription_created', 'timestamp_ms' => ($at ?? now())->getTimestampMs(), 'data' => ['type' => 'plan_enrollment']],
        ]);

        Http::fake(['bzr.openai.com/*' => Http::sequence()->push(['error' => 'bad field'], 400)->push('busy', 503)->push(['accepted_events' => 1], 202)]);

        $refused = $make('invoice:in_refused');
        $this->assertFalse($conversions->send($refused));
        $this->assertSame(ChatGptAdsEvent::STATUS_FAILED, $refused->fresh()->status);
        $this->assertStringContainsString('bad field', (string) $refused->fresh()->error);

        $busy = $make('invoice:in_busy');
        $this->assertFalse($conversions->send($busy));
        $this->assertSame(ChatGptAdsEvent::STATUS_PENDING, $busy->fresh()->status);
        $this->assertSame(1, $busy->fresh()->attempts);

        // Too old for ChatGPT Ads: not sent at all.
        $old = $make('invoice:in_old', now()->subDays(8));

        $this->artisan('listen:chatgpt-ads-send')->assertSuccessful();
        $this->assertSame(ChatGptAdsEvent::STATUS_SENT, $busy->fresh()->status);
        $this->assertSame(ChatGptAdsEvent::STATUS_EXPIRED, $old->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_the_site_keeps_the_chatgpt_ads_click_only_where_it_may(): void
    {
        $land = fn () => $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing').'?oppref=opp_landing_9')->assertOk();

        SiteSetting::set('pricing_forced_country_code', 'DE', 'billing');
        $land()->assertCookieMissing(CaptureAdClickId::OPPREF_COOKIE);

        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        $land()->assertCookie(CaptureAdClickId::OPPREF_COOKIE, 'opp_landing_9');
    }

    public function test_the_admin_saves_the_key_encrypted_and_checks_it_without_saving_an_event(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // The pixel's ID is there already; the switch is off until the key is saved.
        $this->assertSame('X3wiXsHtetMpUTppXyHw5b', SiteSetting::get(ChatGptAdsConversions::PIXEL_ID));
        $this->assertFalse(app(ChatGptAdsConversions::class)->enabled());

        Livewire::test(SeoSettings::class)
            ->set('data.'.ChatGptAdsConversions::ENABLED, true)
            ->set('data.'.ChatGptAdsConversions::API_KEY, ' capi_secret_1 ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.'.ChatGptAdsConversions::API_KEY, '');

        $stored = (string) SiteSetting::query()->where('key', ChatGptAdsConversions::API_KEY)->value('value');
        $this->assertStringNotContainsString('capi_secret_1', $stored);
        $this->assertSame('capi_secret_1', app(ChatGptAdsConversions::class)->apiKey());
        $this->assertTrue(app(ChatGptAdsConversions::class)->enabled());
        $this->assertNull(SiteSetting::query()->where('key', ChatGptAdsConversions::API_KEY)->where('group', 'seo')->first());

        // Saving again with the box empty keeps the key.
        Livewire::test(SeoSettings::class)->call('save')->assertHasNoErrors();
        $this->assertSame('capi_secret_1', app(ChatGptAdsConversions::class)->apiKey());

        Http::fake(['bzr.openai.com/*' => Http::response(['accepted_events' => 1], 202)]);
        Livewire::test(SeoSettings::class)->callAction(TestAction::make('check_chatgpt_ads_key')->schemaComponent('chatgpt_ads_actions', schema: 'form'));
        Http::assertSent(fn (HttpRequest $request): bool => $request['validate_only'] === true
            && $request['events'][0]['type'] === 'subscription_created'
            && $request->hasHeader('Authorization', 'Bearer capi_secret_1'));
        $this->assertSame(0, ChatGptAdsEvent::query()->count());
    }

    public function test_the_browser_event_carries_the_same_id_as_the_server_one(): void
    {
        $code = (string) SiteSetting::get('purchase_event_code');
        $this->assertStringContainsString('purchase.event_id ? { event_id: purchase.event_id } : {}', $code);

        $billing = (string) file_get_contents(resource_path('views/billing.blade.php'));
        $this->assertStringContainsString('ads_event_id: newAdsEventId(),', $billing);
        $this->assertStringContainsString("event_id: eventId ? String(eventId) : '',", $billing);
    }
}
