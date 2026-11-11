<?php

namespace Tests\Feature;

use App\Filament\Resources\PasswordResetRequestResource;
use App\Filament\Resources\PasswordResetRequestResource\Pages\ListPasswordResetRequests;
use App\Models\PasswordResetRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * "Forgot your password?" on the sign-in page: the member types their email, gets one email
 * in the site's design and in the language of the page, opens its link and chooses a new
 * password. The page never says whether an address has an account, the link works once and
 * for an hour, and every request is kept for the admin with what came of it.
 * Emails go to the array mailer: nothing leaves the machine.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        $this->member = User::factory()->create(['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'password' => Hash::make('old-password')]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_sign_in_page_links_to_it_in_both_languages(): void
    {
        $this->getLocalizedRoute('login')->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee('href="'.route('password.request').'"', false);

        app()->setLocale('ar');
        $this->getLocalizedRoute('login')->assertOk()->assertSee('نسيت كلمة المرور؟');
        $this->getLocalizedRoute('password.request')->assertOk()
            ->assertSee('إعادة تعيين كلمة المرور')
            ->assertSee('أرسل لي الرابط')
            ->assertSee('noindex', false);
    }

    public function test_a_member_gets_one_email_in_english_with_a_link_and_the_request_is_kept(): void
    {
        $this->ask('sara@example.com')
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('password_reset_notice', "If that email has an account, we've sent it a reset link. The link works for 60 minutes.");

        $email = $this->onlyEmail();
        $this->assertSame('sara@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Reset your Listen password', $email->getSubject());
        $body = rtrim((string) $email->getTextBody());
        $this->assertStringStartsWith("Reset your password\n\nHello Sara,\n\nWe received a request to reset the password of your Listen account. Press the button below to choose a new one.\n\n", $body);
        $this->assertStringContainsString('The link works for 60 minutes and can be used once.', $body);
        $this->assertStringContainsString("If you didn't ask for this, you can ignore this email. Your password stays the same.", $body);
        $this->assertStringContainsString("\n\nChoose a new password: ".$this->linkIn($email)."\n\n", $body);

        // The site's own email design, as the paid welcome email; the member asked for it, so no unsubscribe link.
        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('Choose a new password', $html);
        $this->assertStringNotContainsString('Unsubscribe', $html);
        $this->assertSame('help@listenai.studio', $email->getReplyTo()[0]->getAddress());

        $row = PasswordResetRequest::query()->sole();
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertSame('sara@example.com', $row->email);
        $this->assertSame('en', $row->locale);
        $this->assertSame(PasswordResetRequest::SENT, $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->completed_at);
        $this->assertSame('Email sent, waiting', $row->outcome()['label']);
    }

    public function test_asking_on_the_arabic_page_sends_the_email_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->ask('sara@example.com')->assertSessionHas('password_reset_notice');

        $email = $this->onlyEmail();
        $this->assertSame('إعادة تعيين كلمة المرور في إسمع', $email->getSubject());
        $body = (string) $email->getTextBody();
        $this->assertStringContainsString('وصلنا طلب لإعادة تعيين كلمة المرور لحسابك في إسمع.', $body);
        $this->assertStringContainsString('يعمل الرابط لمدة 60 دقيقة ويُستخدم مرة واحدة.', $body);
        $this->assertStringContainsString('اختيار كلمة مرور جديدة: '.$this->linkIn($email), $body);
        $this->assertStringContainsString('dir="rtl"', (string) $email->getHtmlBody());
        $this->assertSame('ar', PasswordResetRequest::query()->sole()->locale);

        // The link opens the page in Arabic too.
        $this->openLink($this->linkIn($email))->assertOk()->assertSee('اختر كلمة مرور جديدة');
    }

    public function test_the_link_sets_a_new_password_once_and_the_request_is_marked_done(): void
    {
        $this->ask('sara@example.com');
        $link = $this->linkIn($this->onlyEmail());

        $this->openLink($link)->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('<bdi>sara@example.com</bdi>', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->choose($link, 'new-password-1')
            ->assertRedirect(route('login'))
            ->assertSessionHas('password_reset_status', 'Your password is changed. Sign in with the new one.');
        $this->assertGuest();

        $this->member->refresh();
        $this->assertTrue(Hash::check('new-password-1', $this->member->password));
        $this->assertFalse(Hash::check('old-password', $this->member->password));

        $row = PasswordResetRequest::query()->sole();
        $this->assertNotNull($row->completed_at);
        $this->assertSame('Password changed', $row->outcome()['label']);

        // The sign-in page says so, and the new password signs in; the old one does not.
        $this->withSession(['password_reset_status' => 'Your password is changed. Sign in with the new one.'])
            ->getLocalizedRoute('login')->assertSee('Your password is changed. Sign in with the new one.');
        $this->postLocalizedRoute('login.submit', ['email' => 'sara@example.com', 'password' => 'old-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->postLocalizedRoute('login.submit', ['email' => 'sara@example.com', 'password' => 'new-password-1']);
        $this->assertAuthenticatedAs($this->member);
        auth()->logout();

        // Used once: the same link opens nothing and changes nothing.
        $this->openLink($link)->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->choose($link, 'another-password')->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('new-password-1', $this->member->fresh()->password));
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        $this->ask('nobody@example.com')
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('password_reset_notice', "If that email has an account, we've sent it a reset link. The link works for 60 minutes.");

        $this->assertCount(0, $this->sentEmails());
        $row = PasswordResetRequest::query()->sole();
        $this->assertNull($row->user_id);
        $this->assertSame(PasswordResetRequest::NO_ACCOUNT, $row->status);
        $this->assertSame('No account with this email', $row->outcome()['label']);
    }

    public function test_an_account_made_with_a_phone_number_has_no_mailbox_to_send_to(): void
    {
        $phone = User::factory()->create(['email' => '966500000000@users.listen.local']);

        $this->ask($phone->email)->assertSessionHas('password_reset_notice');

        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(PasswordResetRequest::NO_MAILBOX, PasswordResetRequest::query()->sole()->status);
    }

    public function test_asking_again_at_once_sends_no_second_email(): void
    {
        $this->ask('sara@example.com');
        $this->ask('sara@example.com')->assertSessionHas('password_reset_notice');

        $this->assertCount(1, $this->sentEmails());
        $this->assertSame([PasswordResetRequest::SENT, PasswordResetRequest::TOO_SOON], PasswordResetRequest::query()->orderBy('id')->pluck('status')->all());

        // A minute later a new link goes out, and only the newest link works.
        $first = $this->linkIn($this->sentEmails()->first()->getOriginalMessage());
        $this->travel(61)->seconds();
        $this->ask('sara@example.com');
        $this->assertCount(2, $this->sentEmails());
        $this->openLink($first)->assertRedirect(route('password.request'));
        $this->openLink($this->linkIn($this->sentEmails()->last()->getOriginalMessage()))->assertOk();
    }

    public function test_the_link_runs_out_after_an_hour(): void
    {
        $this->ask('sara@example.com');
        $link = $this->linkIn($this->onlyEmail());

        $this->travel(61)->minutes();

        $this->openLink($link)->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => 'This link no longer works: it has run out or was already used. Ask for a new one below.']);
        $this->choose($link, 'new-password-1')->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('old-password', $this->member->fresh()->password));
        $this->assertSame('Link ran out unused', PasswordResetRequest::query()->sole()->outcome()['label']);
    }

    public function test_a_made_up_link_changes_nothing(): void
    {
        $this->ask('sara@example.com');

        $fake = route('password.reset', ['token' => str_repeat('a', 64), 'email' => 'sara@example.com']);
        $this->openLink($fake)->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->choose($fake, 'new-password-1')->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('old-password', $this->member->fresh()->password));

        // Nor does a real link with someone else's address on it.
        $other = User::factory()->create(['email' => 'other@example.com', 'password' => Hash::make('other-password')]);
        $link = $this->linkIn($this->onlyEmail());
        $this->postLocalizedRoute('password.update', [
            'token' => $this->tokenIn($link), 'email' => 'other@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('other-password', $other->fresh()->password));
    }

    public function test_the_new_password_must_be_long_enough_and_typed_twice_the_same(): void
    {
        $this->ask('sara@example.com');
        $link = $this->linkIn($this->onlyEmail());

        $this->choose($link, 'short')->assertSessionHasErrors('password');
        $this->choose($link, 'new-password-1', 'new-password-2')->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('old-password', $this->member->fresh()->password));

        // The link still works after a mistake.
        $this->choose($link, 'new-password-1')->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password-1', $this->member->fresh()->password));
    }

    public function test_an_email_that_cannot_be_sent_is_noted_and_the_member_can_ask_again_at_once(): void
    {
        $mailer = app('mailer');
        $working = $mailer->getSymfonyTransport();
        $mailer->setSymfonyTransport(new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('The mail server is down.');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });

        $this->ask('sara@example.com')->assertSessionHas('password_reset_notice');
        $row = PasswordResetRequest::query()->sole();
        $this->assertSame(PasswordResetRequest::FAILED, $row->status);
        $this->assertSame('Email could not be sent', $row->outcome()['label']);

        $mailer->setSymfonyTransport($working);
        $this->ask('sara@example.com');
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame(PasswordResetRequest::SENT, PasswordResetRequest::query()->latest('id')->first()->status);
    }

    public function test_only_a_few_links_an_hour_for_one_address(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ask('nobody@example.com')->assertSessionHas('password_reset_notice');
        }

        $this->ask('nobody@example.com')
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => 'You asked for several links. Please wait 60 minutes, then try again.']);
        $this->assertSame(5, PasswordResetRequest::query()->count());
    }

    public function test_it_works_before_the_list_s_migration_is_run(): void
    {
        Schema::drop('password_reset_requests');

        $this->ask('sara@example.com')->assertSessionHas('password_reset_notice');
        $link = $this->linkIn($this->onlyEmail());
        $this->choose($link, 'new-password-1')->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-1', $this->member->fresh()->password));
        $this->assertFalse(PasswordResetRequestResource::shouldRegisterNavigation());
    }

    public function test_a_signed_in_member_can_use_it_too_and_signs_in_again_with_the_new_password(): void
    {
        $this->actingAs($this->member);
        $this->getLocalizedRoute('password.request')->assertOk()->assertSee('Send me the link');

        $this->ask('sara@example.com')->assertSessionHas('password_reset_notice');
        $link = $this->linkIn($this->onlyEmail());
        $this->openLink($link)->assertOk();

        $this->choose($link, 'new-password-1')
            ->assertRedirect(route('login'))
            ->assertSessionHas('password_reset_status');
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password-1', $this->member->fresh()->password));

        // The used link tells a signed-in member why it no longer works, as it tells anyone.
        $this->actingAs($this->member->fresh());
        $this->openLink($link)->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => [__('ui.reset_password.link_invalid')]]))])
            ->getLocalizedRoute('password.request')->assertOk()->assertSee('This link no longer works');
    }

    public function test_it_is_closed_while_email_sign_in_is_turned_off(): void
    {
        SiteSetting::set('auth_email_enabled', '0', 'auth');
        app()->forgetInstance(\App\Services\Auth\AuthSettingsService::class);

        if (app(\App\Services\Auth\AuthSettingsService::class)->emailEnabled()) {
            $this->markTestSkipped('Email sign-in is turned off another way on this site.');
        }

        $this->ask('sara@example.com')->assertRedirect(route('login'))->assertSessionHasErrors('auth');
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_the_admin_reads_who_asked_and_what_came_of_it_under_the_paid_welcome_email(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->ask('sara@example.com');
        $this->choose($this->linkIn($this->onlyEmail()), 'new-password-1');
        $this->ask('nobody@example.com');

        $this->assertSame('Newsletter', PasswordResetRequestResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Pages\PaidWelcomeEmail::getNavigationSort(), PasswordResetRequestResource::getNavigationSort());
        $this->assertLessThan(\App\Filament\Resources\NewsletterSuppressionResource::getNavigationSort(), PasswordResetRequestResource::getNavigationSort());
        $this->assertLessThan(\App\Filament\Pages\PlanEndReminderEmail::getNavigationSort(), PasswordResetRequestResource::getNavigationSort());

        $list = Livewire::actingAs($admin)->test(ListPasswordResetRequests::class)
            ->assertCanSeeTableRecords(PasswordResetRequest::query()->get())
            ->assertSee('sara@example.com')
            ->assertSee('Sara Ahmed')
            ->assertSee('Password changed')
            ->assertSee('nobody@example.com')
            ->assertSee('No account with this email');
        // Pages without counting every row first.
        $this->assertInstanceOf(Paginator::class, $list->instance()->getTableRecords());
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $list->instance()->getTableRecords());

        $list->filterTable('result', 'changed')
            ->assertCanSeeTableRecords(PasswordResetRequest::query()->whereNotNull('completed_at')->get())
            ->assertCanNotSeeTableRecords(PasswordResetRequest::query()->whereNull('completed_at')->get());

        auth()->logout();
        $this->actingAs($this->member)->get(PasswordResetRequestResource::getUrl('index'))->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────

    private function ask(string $email): TestResponse
    {
        return $this->postLocalizedRoute('password.email', ['email' => $email]);
    }

    private function choose(string $link, string $password, ?string $again = null): TestResponse
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $this->postLocalizedRoute('password.update', [
            'token' => $this->tokenIn($link),
            'email' => (string) ($query['email'] ?? ''),
            'password' => $password,
            'password_confirmation' => $again ?? $password,
        ]);
    }

    private function tokenIn(string $link): string
    {
        return basename((string) parse_url($link, PHP_URL_PATH));
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
        $this->assertSame(1, preg_match('#https?://\S+/reset-password/\S+#', (string) $email->getTextBody(), $match));

        return $match[0];
    }
}
