<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Rules\NotDisposableEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Signing up with a throwaway inbox (the usual way to open account after
 * account for the free demo) is refused; ordinary addresses, and the
 * people already signed up, are not affected.
 */
class DisposableEmailSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'voice_tier' => 'standard', 'is_active' => true]);
    }

    public function test_a_throwaway_inbox_cannot_sign_up(): void
    {
        foreach (['someone@mailinator.com', 'someone@inbox.mailinator.com', 'Someone@YOPMAIL.com'] as $email) {
            $this->signUp($email)->assertSessionHasErrors(['email' => __('ui.backend.auth_disposable_email')]);
            $this->assertGuest();
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_an_ordinary_address_signs_up(): void
    {
        $this->signUp('someone@gmail.com')->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame(1, User::query()->where('email', 'someone@gmail.com')->count());
    }

    public function test_a_domain_that_only_ends_like_a_listed_one_is_fine(): void
    {
        $this->assertFalse(NotDisposableEmail::isDisposable('someone@notyopmail.com'));
        $this->assertFalse(NotDisposableEmail::isDisposable('someone@mailinator.com.eg'));
        $this->assertFalse(NotDisposableEmail::isDisposable('not-an-address'));
        $this->assertTrue(NotDisposableEmail::isDisposable('someone@a.b.trashmail.com'));
        $this->assertTrue(NotDisposableEmail::isDisposable(' someone@guerrillamail.com. '));
    }

    public function test_the_check_can_be_switched_off(): void
    {
        config(['disposable_email.enabled' => false]);

        $this->signUp('someone@mailinator.com')->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_the_message_is_in_arabic_on_arabic_pages(): void
    {
        app()->setLocale('ar');

        $errors = Validator::make(['email' => 'someone@mailinator.com'], ['email' => [new NotDisposableEmail]])->errors();

        $this->assertSame(__('ui.backend.auth_disposable_email', [], 'ar'), $errors->first('email'));
        $this->assertNotSame(__('ui.backend.auth_disposable_email', [], 'en'), $errors->first('email'));
    }

    private function signUp(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postLocalizedRoute('register.submit', [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
    }
}
