<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\EmailVerificationMailer;
use App\Services\Auth\FirebaseTokenVerifier;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Services\Referral\ReferralQualificationService;
use App\Support\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * An account made with an email and a password gets one email, in the
 * language of the page it signed up on, with a signed link that confirms its
 * address. The link works signed in or not, only for its own account, and
 * not once it is changed or has expired. A signed-in member can send a new
 * one a few times an hour. Mail is the test mailer's in-memory transport:
 * nothing leaves the machine.
 */
class EmailVerificationLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');
        Mockery::close();

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Sign-up
    // ──────────────────────────────────────────────────────────────────

    public function test_sign_up_sends_one_email_in_english_with_a_link(): void
    {
        $this->signUp('member@example.com', 'Sara Ahmed')->assertRedirect(route('demo.page'));

        $member = User::query()->where('email', 'member@example.com')->firstOrFail();
        $this->assertNull($member->email_verified_at, 'signing up does not confirm the address');
        $this->assertAuthenticatedAs($member);

        $this->assertCount(1, $this->sentEmails());
        $email = $this->onlyEmail();
        $this->assertSame('member@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Confirm your email for Listen', $email->getSubject());
        $body = (string) $email->getTextBody();
        $this->assertStringStartsWith("Hi Sara Ahmed,\n\nThanks for signing up to Listen. Open this link to confirm your email address and start making free voices:\n\n", $body);
        $this->assertStringContainsString('The link works for 3 days. If it has run out, sign in and send yourself a new one from the demo page.', $body);
        $this->assertStringContainsString("If you didn't sign up to Listen, you can ignore this email.", $body);
        $this->assertStringEndsWith("Thanks,\nListen Studio Team", $body);
        $this->assertStringStartsWith(url('/email/verify/'.$member->id.'/'.sha1('member@example.com')).'?expires=', $this->linkIn($email));
    }

    public function test_sign_up_on_an_arabic_page_sends_the_email_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->signUp('arabic@example.com', 'منى علي');

        $this->assertCount(1, $this->sentEmails());
        $email = $this->onlyEmail();
        $this->assertSame('أكّد بريدك الإلكتروني في إسمع', $email->getSubject());
        $body = (string) $email->getTextBody();
        $this->assertStringStartsWith("مرحباً منى علي،\n\nشكراً لتسجيلك في إسمع. افتح هذا الرابط لتأكيد بريدك الإلكتروني والبدء في إنشاء أصواتك المجانية:\n\n", $body);
        $this->assertStringContainsString('يعمل الرابط لمدة 3 أيام.', $body);
        $this->assertStringEndsWith("شكراً،\nفريق إسمع", $body);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $body, 'the digits the site writes everywhere');
    }

    public function test_sign_up_is_never_held_up_when_the_email_cannot_be_sent(): void
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
        Log::spy();

        $this->signUp('member@example.com')->assertRedirect(route('demo.page'));

        $member = User::query()->where('email', 'member@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'The email confirmation link was not sent.'
            && ($context['user_id'] ?? null) === $member->id
            && ($context['error'] ?? null) === 'The mailbox is busy.')->once();

        // The demo page does not say a link was sent: it offers to send one.
        $this->assertFalse(EmailVerificationMailer::linkSentRecently($member));
        $this->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('Send yourself a confirmation link at <bdi>member@example.com</bdi>, then open it to start making free voices.', false)
            ->assertSee('Send me a confirmation link');

        // Once the mailbox works again, the member sends themselves a link.
        $mailer->setSymfonyTransport($working);
        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_the_sign_up_email_goes_out_after_the_reply_and_the_page_already_says_so(): void
    {
        $member = User::factory()->unverified()->create();

        app(EmailVerificationMailer::class)->sendAfterResponse($member, 'en');

        $this->assertCount(0, $this->sentEmails(), 'nothing is sent while the reply is being made');
        $this->assertTrue(EmailVerificationMailer::linkSentRecently($member), 'the page the member lands on says a link is on its way');

        app(DeferredCallbackCollection::class)->invoke();

        $this->assertCount(1, $this->sentEmails());
        $this->assertTrue(EmailVerificationMailer::linkSentRecently($member));
        $this->travel(EmailVerification::LINK_DAYS)->days();
        $this->travel(1)->minutes();
        $this->assertFalse(EmailVerificationMailer::linkSentRecently($member), 'only while the link still works');
    }

    public function test_a_failed_sign_up_sends_nothing(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->signUp('taken@example.com')->assertSessionHasErrors('email');

        $this->assertCount(0, $this->sentEmails());
    }

    // ──────────────────────────────────────────────────────────────────
    //  The link
    // ──────────────────────────────────────────────────────────────────

    public function test_the_link_confirms_the_signed_in_member_and_opens_the_demo(): void
    {
        $this->signUp('member@example.com');
        $member = User::query()->where('email', 'member@example.com')->firstOrFail();

        $this->openLink($this->linkIn($this->onlyEmail()))
            ->assertRedirect(route('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'success', 'message' => 'Thanks, your email is confirmed. You can try the demo now.']);

        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertFalse(EmailVerification::requiredForFreeUse($member->fresh()));

        // The next page loads the member afresh, as every request does.
        $this->actingAs($member->fresh())->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('Thanks, your email is confirmed. You can try the demo now.')
            ->assertDontSee(__('ui.verify_email.notice_title'))
            ->assertSee('const emailVerificationRequired = false;', false);
    }

    public function test_the_link_works_signed_out_without_signing_anyone_in(): void
    {
        $member = User::factory()->unverified()->create();
        $link = app(EmailVerificationMailer::class)->verificationUrl($member);

        $this->openLink($link)
            ->assertRedirect(route('login'))
            ->assertSessionHas('verify_email_status', 'Thanks, your email is confirmed. Sign in to try the demo.');

        $this->assertGuest();
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->getLocalizedRoute('login')->assertOk()->assertSee('Thanks, your email is confirmed. Sign in to try the demo.');

        // Opened again: nothing changes, and it says so.
        $confirmedAt = $member->fresh()->email_verified_at;
        $this->travel(5)->minutes();
        $this->openLink($link)->assertSessionHas('verify_email_status', 'Your email is already confirmed.');
        $this->assertEquals($confirmedAt, $member->fresh()->email_verified_at);
    }

    public function test_the_link_confirms_only_its_own_account_whoever_is_signed_in(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($other);
        $this->openLink(app(EmailVerificationMailer::class)->verificationUrl($owner))
            ->assertRedirect(route('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'success', 'message' => 'That email address is now confirmed. You are signed in with a different account.']);

        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($other);
    }

    public function test_a_changed_link_confirms_nothing(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'member@example.com']);
        $other = User::factory()->unverified()->create();
        $link = app(EmailVerificationMailer::class)->verificationUrl($member);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $changed = [
            'the signature' => substr($link, 0, -1).(str_ends_with($link, 'a') ? 'b' : 'a'),
            'no signature' => strtok($link, '?').'?expires='.$query['expires'],
            'another account' => str_replace('/email/verify/'.$member->id.'/', '/email/verify/'.$other->id.'/', $link),
            'a later expiry' => str_replace('expires='.$query['expires'], 'expires='.((int) $query['expires'] + 86400 * 30), $link),
        ];

        foreach ($changed as $what => $url) {
            $this->openLink($url)
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['auth' => 'This confirmation link is not valid. Sign in to send yourself a new one.']);
            $this->assertNull($member->fresh()->email_verified_at, $what);
            $this->assertNull($other->fresh()->email_verified_at, $what);
        }

        // Signed in, the demo page says so above the button for a new link.
        $this->actingAs($member);
        $this->openLink($changed['the signature'])
            ->assertRedirect(route('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'error', 'message' => 'This confirmation link is not valid.']);
        $this->getLocalizedRoute('demo.page')->assertSee('This confirmation link is not valid.')->assertSee('Send me a confirmation link');
        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_a_link_for_an_address_the_account_no_longer_has_confirms_nothing(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $link = app(EmailVerificationMailer::class)->verificationUrl($member);
        $member->forceFill(['email' => 'new@example.com'])->save();

        $this->openLink($link)->assertSessionHasErrors('auth');

        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_the_link_works_for_three_days_and_no_longer(): void
    {
        $member = User::factory()->unverified()->create();
        $late = User::factory()->unverified()->create();
        $mailer = app(EmailVerificationMailer::class);
        $link = $mailer->verificationUrl($member);
        $lateLink = $mailer->verificationUrl($late);

        $this->travel(EmailVerification::LINK_DAYS * 24 * 60 - 1)->minutes();
        $this->openLink($link)->assertSessionHas('verify_email_status');
        $this->assertNotNull($member->fresh()->email_verified_at, 'just before the end it still works');

        $this->travel(2)->minutes();
        $this->openLink($lateLink)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['auth' => 'This confirmation link has expired. Sign in to send yourself a new one.']);
        $this->assertNull($late->fresh()->email_verified_at);

        app()->setLocale('ar');
        $this->actingAs($late);
        $this->openLink($lateLink)
            ->assertRedirect(route('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'error', 'message' => 'انتهت صلاحية رابط التأكيد هذا.']);
        $this->assertNull($late->fresh()->email_verified_at);
    }

    public function test_a_link_signed_for_https_works_when_the_proxy_hands_it_over_as_http(): void
    {
        $member = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        // Signed for https, as on the live site.
        URL::forceScheme('https');
        $link = app(EmailVerificationMailer::class)->verificationUrl($member);
        $otherLink = app(EmailVerificationMailer::class)->verificationUrl($other);
        URL::forceScheme(null);
        $this->assertStringStartsWith('https://localhost/email/verify/', $link);

        $this->openLink(str_replace('https://', 'http://', $link))->assertSessionHas('verify_email_status');
        $this->assertNotNull($member->fresh()->email_verified_at);

        // Changed, it fails over https as well.
        $this->openLink(str_replace(['https://', 'expires='], ['http://', 'expires=1'], $otherLink))->assertSessionHasErrors('auth');
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_a_member_with_a_plan_lands_on_the_dashboard(): void
    {
        $member = User::factory()->unverified()->create(['subscription_status_checked_at' => now()]);
        app(\App\Services\PlanAccessService::class)->assignPlan($member, Plan::factory()->paid()->create(['slug' => 'creator']));

        $this->actingAs($member);
        $this->openLink(app(EmailVerificationMailer::class)->verificationUrl($member))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('ok', 'Thanks, your email is confirmed.');
    }

    public function test_confirming_counts_for_a_referral_campaign_that_waits_for_it(): void
    {
        $member = User::factory()->unverified()->create();
        $referrals = Mockery::mock(ReferralQualificationService::class);
        $referrals->shouldReceive('evaluate')->once()->withArgs(fn (User $user, string $event): bool => $user->is($member) && $event === 'email_verified');
        $this->app->instance(ReferralQualificationService::class, $referrals);

        $this->openLink(app(EmailVerificationMailer::class)->verificationUrl($member));

        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    // ──────────────────────────────────────────────────────────────────
    //  A new link
    // ──────────────────────────────────────────────────────────────────

    public function test_a_signed_in_member_can_send_a_new_link_in_the_page_language(): void
    {
        $member = User::factory()->unverified()->create(['email' => 'member@example.com', 'name' => 'Sara']);

        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'We sent a confirmation link to member@example.com. It works for 3 days.']);
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame('Confirm your email for Listen', $this->onlyEmail()->getSubject());

        app()->setLocale('ar');
        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')
            ->assertOk()
            ->assertJsonPath('message', 'أرسلنا رابط التأكيد إلى member@example.com، وهو يعمل لمدة 3 أيام.');
        $arabic = $this->sentEmails()->last()->getOriginalMessage();
        $this->assertSame('أكّد بريدك الإلكتروني في إسمع', $arabic->getSubject());

        // The newest link confirms the account.
        $this->openLink($this->linkIn($arabic))->assertRedirect(route('demo.page'));
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    public function test_the_button_on_the_page_sends_a_link_and_says_so_there(): void
    {
        // Made before sign-up sent links: the page offers one rather than saying it was sent.
        $member = User::factory()->unverified()->create(['email' => 'member@example.com']);
        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('Send yourself a confirmation link at <bdi>member@example.com</bdi>, then open it to start making free voices.', false)
            ->assertSee('Send me a confirmation link')
            ->assertDontSee('We sent a confirmation link');

        $this->actingAs($member)->from($this->localizedRoute('demo.page'))->postLocalizedRoute('verification.send')
            ->assertRedirect($this->localizedRoute('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'success', 'message' => 'We sent a confirmation link to member@example.com. It works for 3 days.']);

        $this->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('We sent a confirmation link to member@example.com. It works for 3 days.')
            ->assertSee('We sent a confirmation link to <bdi>member@example.com</bdi>. Open it to start making free voices.', false)
            ->assertSee("Can't find it? Look in your spam or junk folder, or send yourself a new link.")
            ->assertSee('Send a new link');
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_a_new_link_is_limited_to_a_few_an_hour_for_each_member(): void
    {
        $member = User::factory()->unverified()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($member)->postJsonLocalizedRoute('verification.send')->assertOk();
        }

        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')
            ->assertStatus(429)
            ->assertExactJson(['ok' => false, 'message' => 'You asked for several new links. Please wait 60 minutes, then try again.']);

        app()->setLocale('ar');
        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')
            ->assertStatus(429)
            ->assertJsonPath('message', 'طلبت روابط جديدة عدة مرات. انتظر 60 دقيقة ثم حاول مرة أخرى.');
        app()->setLocale('en');

        // The page's button gets the same answer, shown above it.
        $this->actingAs($member)->from($this->localizedRoute('demo.page'))->postLocalizedRoute('verification.send')
            ->assertRedirect($this->localizedRoute('demo.page'))
            ->assertSessionHas('verify_email_notice', ['type' => 'error', 'message' => 'You asked for several new links. Please wait 60 minutes, then try again.']);

        $this->assertCount(3, $this->sentEmails());

        $this->travel(61)->minutes();
        $this->actingAs($member)->postJsonLocalizedRoute('verification.send')->assertOk();
        $this->assertCount(4, $this->sentEmails());
    }

    public function test_a_new_link_is_limited_for_each_network_address_too(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs(User::factory()->unverified()->create())
                ->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])
                ->postJsonLocalizedRoute('verification.send')->assertOk();
        }

        $this->actingAs(User::factory()->unverified()->create())
            ->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])
            ->postJsonLocalizedRoute('verification.send')->assertStatus(429);

        $this->actingAs(User::factory()->unverified()->create())
            ->withServerVariables(['REMOTE_ADDR' => '10.9.8.8'])
            ->postJsonLocalizedRoute('verification.send')->assertOk();

        $this->assertCount(11, $this->sentEmails());
    }

    public function test_the_wait_is_told_in_minutes_that_read_right(): void
    {
        $this->assertSame('You asked for several new links. Please wait a minute, then try again.', trans_choice('ui.verify_email.resend_too_many', 1, ['count' => 1]));
        $this->assertSame('You asked for several new links. Please wait 5 minutes, then try again.', trans_choice('ui.verify_email.resend_too_many', 5, ['count' => 5]));

        app()->setLocale('ar');
        $this->assertSame('طلبت روابط جديدة عدة مرات. انتظر دقيقة ثم حاول مرة أخرى.', trans_choice('ui.verify_email.resend_too_many', 1, ['count' => 1]));
        $this->assertSame('طلبت روابط جديدة عدة مرات. انتظر دقيقتين ثم حاول مرة أخرى.', trans_choice('ui.verify_email.resend_too_many', 2, ['count' => 2]));
        $this->assertSame('طلبت روابط جديدة عدة مرات. انتظر 5 دقائق ثم حاول مرة أخرى.', trans_choice('ui.verify_email.resend_too_many', 5, ['count' => 5]));
        $this->assertSame('طلبت روابط جديدة عدة مرات. انتظر 45 دقيقة ثم حاول مرة أخرى.', trans_choice('ui.verify_email.resend_too_many', 45, ['count' => 45]));
    }

    public function test_a_confirmed_member_gets_no_new_link(): void
    {
        $this->actingAs(User::factory()->create())->postJsonLocalizedRoute('verification.send')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'Your email is already confirmed.']);

        $this->assertCount(0, $this->sentEmails());
    }

    public function test_a_guest_cannot_ask_for_a_link(): void
    {
        $this->postJsonLocalizedRoute('verification.send')->assertUnauthorized();

        $this->assertCount(0, $this->sentEmails());
    }

    // ──────────────────────────────────────────────────────────────────
    //  Google and phone sign-ins
    // ──────────────────────────────────────────────────────────────────

    public function test_a_google_sign_up_is_confirmed_and_gets_no_email(): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
        $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-1', 'email' => 'member@gmail.com', 'email_verified' => true])->map([
            'id' => 'google-1', 'name' => 'Google Member', 'nickname' => null, 'email' => 'member@gmail.com', 'avatar' => null,
        ]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $member = User::query()->where('email', 'member@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertNotNull($member->email_verified_at);
        $this->assertFalse(EmailVerification::requiredForFreeUse($member));
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_an_unconfirmed_email_account_is_confirmed_by_signing_in_with_google(): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
        $member = User::factory()->unverified()->create(['email' => 'member@gmail.com']);
        $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-2', 'email' => 'member@gmail.com', 'email_verified' => true])->map([
            'id' => 'google-2', 'name' => 'Google Member', 'nickname' => null, 'email' => 'member@gmail.com', 'avatar' => null,
        ]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->getLocalizedRoute('auth.social.callback');

        $this->assertAuthenticatedAs($member);
        $this->assertFalse(EmailVerification::requiredForFreeUse($member->fresh()));
    }

    public function test_phone_and_firebase_google_sign_ups_are_confirmed(): void
    {
        $verifier = Mockery::mock(FirebaseTokenVerifier::class);
        $verifier->shouldReceive('verify')->with('phone-token', 'phone')->andReturn([
            'uid' => 'phone-uid', 'phone_number' => '+201000000001', 'email' => null, 'email_verified' => false,
            'display_name' => null, 'photo_url' => null, 'provider_ids' => ['phone'], 'provider_emails' => [],
        ]);
        $verifier->shouldReceive('verify')->with('google-token', 'google.com')->andReturn([
            'uid' => 'google-uid', 'phone_number' => null, 'email' => 'someone@gmail.com', 'email_verified' => true,
            'display_name' => 'Someone', 'photo_url' => null, 'provider_ids' => ['google.com'], 'provider_emails' => ['google.com' => 'someone@gmail.com'],
        ]);
        $this->app->instance(FirebaseTokenVerifier::class, $verifier);
        $bridge = app(PhoneAuthBridgeService::class);

        $phone = $bridge->resolveUserFromIdToken('phone-token', '127.0.0.1');
        $google = $bridge->resolveUserFromGoogleIdToken('google-token', '127.0.0.1');

        foreach ([$phone, $google] as $member) {
            $this->assertTrue(EmailVerification::isVerified($member));
            $this->assertFalse(EmailVerification::requiredForFreeUse($member));
        }
        $this->assertNotNull($phone->phone_verified_at);
        // A phone sign-up's internal address never gets mail.
        $this->assertFalse(app(EmailVerificationMailer::class)->send($phone, 'en'));
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_the_texts_and_the_email_name_no_other_company(): void
    {
        $member = User::factory()->unverified()->create(['name' => 'Sara']);
        app(EmailVerificationMailer::class)->send($member, 'en');
        app(EmailVerificationMailer::class)->send($member, 'ar');

        $texts = $this->sentEmails()->map(fn (SentMessage $sent): string => $sent->getOriginalMessage()->getSubject().' '.$sent->getOriginalMessage()->getTextBody())->all();
        foreach (['en', 'ar'] as $locale) {
            $texts = array_merge($texts, array_values((array) __('ui.verify_email', [], $locale)));
        }

        $this->assertCount(2, $this->sentEmails());
        foreach ($texts as $text) {
            $this->assertDoesNotMatchRegularExpression('/google|gmail|gemini|firebase|titan|outlook|yahoo|جوجل/iu', (string) $text);
        }
    }

    // ──────────────────────────────────────────────────────────────────

    private function signUp(string $email, string $name = 'New Member'): TestResponse
    {
        return $this->postLocalizedRoute('register.submit', [
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    private function openLink(string $url): TestResponse
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($url);
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function sentEmails(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function onlyEmail(): Email
    {
        $this->assertCount(1, $this->sentEmails());

        return $this->sentEmails()->first()->getOriginalMessage();
    }

    private function linkIn(Email $email): string
    {
        $this->assertSame(1, preg_match('#https?://\S+/email/verify/\S+#', (string) $email->getTextBody(), $match));

        return $match[0];
    }
}
