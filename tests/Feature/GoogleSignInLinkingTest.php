<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Google sign-in (OAuth callback): signing in and making new accounts work as
 * before, but an existing account found by its email is only joined to the
 * Google identity when Google says outright that it verified the address.
 */
class GoogleSignInLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_existing_account_is_joined_when_google_says_the_email_is_verified(): void
    {
        $member = User::factory()->create(['email' => 'member@gmail.com']);
        $this->fakeGoogleUser(['email_verified' => true]);

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $this->assertAuthenticatedAs($member);
        $this->assertSame('google-123', $member->fresh()->google_id);
    }

    public function test_the_older_verified_email_claim_also_counts(): void
    {
        $member = User::factory()->create(['email' => 'member@gmail.com']);
        $this->fakeGoogleUser(['verified_email' => 'true']);

        $this->getLocalizedRoute('auth.social.callback');

        $this->assertAuthenticatedAs($member);
    }

    public function test_an_existing_account_is_not_joined_without_the_verified_claim(): void
    {
        $member = User::factory()->create(['email' => 'member@gmail.com']);
        $this->fakeGoogleUser([]);

        $this->getLocalizedRoute('auth.social.callback')
            ->assertRedirect($this->localizedRoute('login'))
            ->assertSessionHasErrors(['email' => __('ui.backend.auth_social_unverified_email')]);

        $this->assertGuest();
        $this->assertNull($member->fresh()->google_id);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_new_account_is_still_made_without_the_claim(): void
    {
        $this->fakeGoogleUser([]);

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $user = User::query()->where('email', 'member@gmail.com')->firstOrFail();
        $this->assertSame('google-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_account_already_joined_to_this_google_identity_signs_in_without_the_claim(): void
    {
        $member = User::factory()->create(['email' => 'member@gmail.com', 'google_id' => 'google-123']);
        $this->fakeGoogleUser([]);

        $this->getLocalizedRoute('auth.social.callback');

        $this->assertAuthenticatedAs($member);
    }

    public function test_an_address_google_marks_unverified_is_still_refused(): void
    {
        $this->fakeGoogleUser(['email_verified' => false]);

        $this->getLocalizedRoute('auth.social.callback')
            ->assertRedirect($this->localizedRoute('login'))
            ->assertSessionHasErrors(['email' => __('ui.backend.auth_social_no_email')]);

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    /**
     * @param  array<string, mixed>  $claims  what Google's user info says about the email
     */
    private function fakeGoogleUser(array $claims): void
    {
        $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-123', 'email' => 'member@gmail.com'] + $claims)->map([
            'id' => 'google-123',
            'name' => 'Google Member',
            'nickname' => null,
            'email' => 'member@gmail.com',
            'avatar' => 'https://example.test/avatar.png',
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
