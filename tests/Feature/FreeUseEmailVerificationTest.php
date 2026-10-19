<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\Auth\EmailVerificationMailer;
use App\Services\Billing\CharPackService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\CreditPeriod;
use App\Support\EmailVerification;
use App\Support\FreeAllowance;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * An account whose email is not confirmed cannot spend a free allowance: the
 * demo (its check and its voices) and the free credits of the studio and the
 * browser extension. It is told what to do in its language and nothing is
 * made or charged; the demo page and the studio show the button for a new
 * link. Confirmed accounts, Google and phone sign-ins, and anyone who pays or
 * has paid work as before. Speech is faked.
 */
class FreeUseEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Hello there';

    private int $speechCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('audio/free-use.mp3', 'audio');
        Plan::factory()->free()->create([
            'monthly_chars' => 10000, 'monthly_requests' => 50, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
        ]);
        $this->fakeSpeech();
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    //  The demo
    // ──────────────────────────────────────────────────────────────────

    public function test_an_unconfirmed_account_cannot_spend_the_demo_in_english(): void
    {
        $member = $this->unconfirmed('member@example.com');

        foreach (['demo.run', 'demo.preview'] as $route) {
            $this->actingAs($member)->postJsonLocalizedRoute($route, $this->demoText())
                ->assertForbidden()
                ->assertExactJson([
                    'ok' => false,
                    'message' => 'Confirm your email to try the demo: open the confirmation link sent to member@example.com. No email? Send yourself a link on the demo page.',
                    'email_verification_required' => true,
                ]);
        }

        $this->assertNothingSpentOnTheDemo($member);
    }

    public function test_an_unconfirmed_account_cannot_spend_the_demo_in_arabic(): void
    {
        $member = $this->unconfirmed('member@example.com');
        app()->setLocale('ar');

        foreach (['demo.run', 'demo.preview'] as $route) {
            $this->actingAs($member)->postJsonLocalizedRoute($route, ['text' => 'مرحبا بكم'] + $this->demoText())
                ->assertForbidden()
                ->assertJsonPath('ok', false)
                ->assertJsonPath('message', 'أكّد بريدك الإلكتروني لتبدأ العرض التجريبي: افتح رابط التأكيد المُرسَل إلى member@example.com. لم تصلك الرسالة؟ أرسل لنفسك رابطاً من صفحة العرض التجريبي.');
        }

        $this->assertNothingSpentOnTheDemo($member);
    }

    public function test_a_form_post_to_the_demo_is_sent_back_to_the_demo_page(): void
    {
        $member = $this->unconfirmed('member@example.com');

        $this->actingAs($member)->postLocalizedRoute('demo.preview', $this->demoText())
            ->assertRedirect(route('demo.page'))
            ->assertSessionHas('verify_email_notice.type', 'error');

        $this->assertNothingSpentOnTheDemo($member);
    }

    public function test_a_confirmed_account_uses_the_demo_as_before(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($member)->postJsonLocalizedRoute('demo.preview', $this->demoText())->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, DemoVoiceGeneration::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, $this->speechCalls);
    }

    public function test_the_wait_can_be_switched_off_when_email_cannot_be_delivered(): void
    {
        config(['listen.demo.require_verified_email' => false]);
        $member = $this->unconfirmed('member@example.com');

        $this->assertFalse(EmailVerification::requiredForFreeUse($member));
        $this->actingAs($member)->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($member)->postJsonLocalizedRoute('demo.preview', $this->demoText())->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, DemoVoiceGeneration::query()->where('user_id', $member->id)->count());
    }

    public function test_signing_up_then_confirming_opens_the_demo(): void
    {
        $this->postLocalizedRoute('register.submit', [
            'name' => 'New Member', 'email' => 'new@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('demo.page'));
        $member = User::query()->where('email', 'new@example.com')->firstOrFail();

        $this->postJsonLocalizedRoute('demo.preview', $this->demoText())->assertForbidden();
        $this->assertSame(0, $this->speechCalls);

        $body = (string) app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getTextBody();
        $this->assertSame(1, preg_match('#https?://\S+/email/verify/\S+#', $body, $link));
        $this->withoutLocaleRedirects()->get($link[0])->assertRedirect(route('demo.page'));

        // The next request loads the member afresh, as every request does.
        $this->actingAs($member->fresh())->postJsonLocalizedRoute('demo.preview', $this->demoText())->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, DemoVoiceGeneration::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, $this->speechCalls);
    }

    public function test_google_and_phone_accounts_use_the_demo(): void
    {
        // A Google sign-up, through the sign-in callback.
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
        $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-1', 'email' => 'member@gmail.com', 'email_verified' => true])->map([
            'id' => 'google-1', 'name' => 'Google Member', 'nickname' => null, 'email' => 'member@gmail.com', 'avatar' => null,
        ]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $this->getLocalizedRoute('auth.social.callback')->assertRedirect(route('demo.page'));
        $this->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk();

        // A phone number confirmed by its code counts as much as a mailbox.
        $phone = User::factory()->unverified()->create([
            'auth_provider' => 'phone', 'phone_number' => '+201000000002', 'phone_verified_at' => now(), 'email' => 'phone+abc@users.listen.local',
        ]);
        $this->actingAs($phone)->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk();
    }

    public function test_a_pack_holder_or_a_former_payer_is_never_held_back(): void
    {
        $pack = Plan::factory()->create([
            'slug' => 'pack', 'name' => 'Premium Pack', 'kind' => Plan::KIND_PACK, 'pack_days' => 30,
            'price_cents' => 200, 'monthly_chars' => 20000, 'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'voice_tier' => 'hd',
        ]);
        $holder = $this->unconfirmed('holder@example.com');
        $packs = app(CharPackService::class);
        $packs->markPaid($packs->startPurchase($holder, $pack, 'card', 200, 'USD'));

        // Still on the free plan, so the demo is theirs to try.
        $this->assertFalse(EmailVerification::requiredForFreeUse($holder));
        $this->actingAs($holder)->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk();

        // Their pack used up, they have still paid.
        $packs->adjust((int) $holder->id, 20000);
        $this->assertSame(0, $packs->remaining((int) $holder->id));
        $this->assertFalse(EmailVerification::requiredForFreeUse($holder->fresh()));
        $this->actingAs($holder->fresh())->postJsonLocalizedRoute('demo.run', $this->demoText())->assertOk();
    }

    // ──────────────────────────────────────────────────────────────────
    //  The demo page
    // ──────────────────────────────────────────────────────────────────

    public function test_the_demo_page_shows_the_notice_with_a_button_for_a_new_link(): void
    {
        $member = $this->unconfirmed('member@example.com');
        app(EmailVerificationMailer::class)->send($member, 'en');

        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('Confirm your email to try the demo')
            ->assertSee('We sent a confirmation link to <bdi>member@example.com</bdi>. Open it to start making free voices.', false)
            ->assertSee("Can't find it? Look in your spam or junk folder, or send yourself a new link.")
            ->assertSee('action="'.route('verification.send').'"', false)
            ->assertSee('Send a new link')
            ->assertSee('const emailVerificationRequired = true;', false)
            ->assertSee('const shouldDisable = emailVerificationRequired || isQuotaRefreshing', false);

        app()->setLocale('ar');
        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('أكّد بريدك الإلكتروني لتبدأ العرض التجريبي')
            ->assertSee('أرسلنا رابط التأكيد إلى <bdi>member@example.com</bdi>. افتحه لتبدأ إنشاء أصواتك المجانية.', false)
            ->assertSee('لم تجد الرسالة؟ ابحث في مجلد الرسائل غير المرغوب فيها، أو أرسل لنفسك رابطاً جديداً.')
            ->assertSee('أرسل رابطاً جديداً');
    }

    public function test_an_account_that_was_never_sent_a_link_is_offered_one(): void
    {
        // Made before sign-up sent links, or its email failed.
        $member = $this->unconfirmed('member@example.com');

        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('Confirm your email to try the demo')
            ->assertSee('Send yourself a confirmation link at <bdi>member@example.com</bdi>, then open it to start making free voices.', false)
            ->assertSee('Send me a confirmation link')
            ->assertDontSee('We sent a confirmation link')
            ->assertSee('const emailVerificationRequired = true;', false);

        app()->setLocale('ar');
        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('أرسل لنفسك رابط التأكيد إلى <bdi>member@example.com</bdi>، ثم افتحه لتبدأ إنشاء أصواتك المجانية.', false)
            ->assertSee('أرسل لي رابط التأكيد');
    }

    public function test_a_confirmed_account_sees_no_notice(): void
    {
        $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')->assertOk()
            ->assertDontSee('Confirm your email to try the demo')
            ->assertDontSee('id="verifyEmailNotice"', false)
            ->assertSee('const emailVerificationRequired = false;', false);
    }

    // ──────────────────────────────────────────────────────────────────
    //  The studio and the extension
    // ──────────────────────────────────────────────────────────────────

    public function test_free_credits_in_the_studio_wait_for_a_confirmed_email(): void
    {
        $member = $this->unconfirmed('member@example.com');

        $this->studio($member)->assertStatus(422)->assertJsonPath(
            'errors.content.0',
            'Confirm your email to use your free credits: open the confirmation link sent to member@example.com. No email? Send yourself a link on your dashboard.',
        );

        app()->setLocale('ar');
        $this->studio($member)->assertStatus(422)->assertJsonPath(
            'message',
            'أكّد بريدك الإلكتروني لتستخدم رصيدك المجاني: افتح رابط التأكيد المُرسَل إلى member@example.com. لم تصلك الرسالة؟ أرسل لنفسك رابطاً من لوحة التحكم.',
        );

        $this->assertNothingSpentInTheStudio($member);

        $member->forceFill(['email_verified_at' => now()])->save();
        $this->studio($member->fresh())->assertOk();
        $this->assertSame(1, AudioGeneration::query()->where('user_id', $member->id)->count());
    }

    public function test_free_credits_in_the_extension_wait_for_a_confirmed_email(): void
    {
        $member = $this->unconfirmed('member@example.com');
        $key = 'lk_live_'.str_repeat('v', 32);
        $member->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();

        $this->withHeaders(['X-Listen-Key' => $key, 'X-Listen-Locale' => 'en'])->postJson('/api/reader/queue', [
            'content' => self::TEXT, 'target_language' => 'en', 'voice_key' => $this->voiceKey(), 'prefer_sync' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Confirm your email to use your free credits: open the confirmation link sent to member@example.com. No email? Send yourself a link on your dashboard.');

        $this->assertNothingSpentInTheStudio($member);
    }

    public function test_a_gift_is_a_free_allowance_too(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $member = $this->unconfirmed('member@example.com');
        UsageCounter::query()->create(['user_id' => $member->id, 'period_ym' => CreditPeriod::current((int) $member->id)['key'], 'bonus_chars' => 500]);

        $this->studio($member)->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.verify_email.studio_required', ['email' => 'member@example.com']));

        $this->assertNothingSpentInTheStudio($member);
    }

    public function test_a_member_without_free_credits_hears_that_first(): void
    {
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');

        $this->studio($this->unconfirmed('member@example.com'))->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.no_chars_yet'));
    }

    public function test_a_paying_member_is_never_held_back_in_the_studio(): void
    {
        $member = $this->unconfirmed('payer@example.com');
        $plan = Plan::factory()->create([
            'slug' => 'creator', 'price_cents' => 1999, 'voice_tier' => 'standard', 'monthly_chars' => 100000,
            'max_chars_per_request' => 5000, 'monthly_requests' => 0, 'is_active' => true,
        ]);
        Subscription::query()->create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->studio($member)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, AudioGeneration::query()->where('user_id', $member->id)->count());
    }

    public function test_the_studio_shows_the_notice_while_there_are_free_credits(): void
    {
        $member = $this->unconfirmed('member@example.com');

        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk()
            ->assertSee('Confirm your email to use your free credits')
            ->assertSee('<bdi>member@example.com</bdi>', false)
            ->assertSee('action="'.route('verification.send').'"', false);

        $this->actingAs(User::factory()->create())->getLocalizedRoute('dashboard')->assertOk()
            ->assertDontSee('Confirm your email to use your free credits');

        // Without free credits there is nothing to confirm for.
        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0');
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk()
            ->assertDontSee('Confirm your email to use your free credits');
    }

    // ──────────────────────────────────────────────────────────────────

    public function test_the_routes_carry_the_check_and_the_limits(): void
    {
        $router = app('router');

        foreach (['/demo', '/preview'] as $uri) {
            $route = $router->getRoutes()->match(Request::create($uri, 'POST'));
            $this->assertContains('demo.verified', $route->gatherMiddleware(), $uri);
            $this->assertContains('demo.free', $route->gatherMiddleware(), $uri);
        }
        $page = $router->getRoutes()->match(Request::create('/demo', 'GET'));
        $this->assertNotContains('demo.verified', $page->gatherMiddleware(), 'the page itself opens, to show the notice');

        $send = $router->getRoutes()->getByName('verification.send');
        $this->assertContains('auth', $send->gatherMiddleware());
        $this->assertContains('throttle:verify-email', $send->gatherMiddleware());
        $this->assertNotContains('auth', $router->getRoutes()->getByName('verification.verify')->gatherMiddleware());
    }

    // ──────────────────────────────────────────────────────────────────

    private function unconfirmed(string $email): User
    {
        return User::factory()->unverified()->create(['email' => $email, 'subscription_status_checked_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoText(): array
    {
        return ['text' => self::TEXT, 'target_language' => 'en', 'voice_tier' => 'standard', 'voice_key' => $this->voiceKey()];
    }

    private function studio(User $member): TestResponse
    {
        return $this->actingAs($member)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => self::TEXT, 'target_language' => 'en', 'voice_tier' => 'standard', 'voice_key' => $this->voiceKey(),
        ]);
    }

    private function assertNothingSpentOnTheDemo(User $member): void
    {
        $this->assertSame(0, DemoVoiceGeneration::query()->count(), 'no demo voice');
        $this->assertSame(0, $this->speechCalls, 'no speech made');
        $this->actingAs($member)->postJsonLocalizedRoute('demo.quota')->assertOk()
            ->assertJsonPath('quota.used_chars', 0)
            ->assertJsonPath('quota.remaining_chars', 300);
    }

    private function assertNothingSpentInTheStudio(User $member): void
    {
        $this->assertSame(0, AudioGeneration::query()->count(), 'nothing made');
        $this->assertSame(0, $this->speechCalls, 'no speech made');
        $counter = CreditPeriod::counter((int) $member->id);
        $this->assertSame(0, (int) $counter->total_chars, 'no credits reserved');
        $this->assertSame(0, (int) $counter->total_requests);
    }

    private function voiceKey(): string
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);

        return VoiceCatalog::keyFromId((int) $voice->id);
    }

    private function fakeSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function (): array {
            $this->speechCalls++;

            return [
                'audio_path' => 'audio/free-use.mp3', 'duration_seconds' => 3, 'provider' => 'google_cloud', 'model' => 'standard',
                'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'free',
            ];
        });
        $this->app->instance(TtsService::class, $tts);
    }

    private function withoutLocaleRedirects(): static
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
    }
}
