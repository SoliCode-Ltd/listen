<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * A Google picture address can be very long. Signing up with one works: the
 * column holds it, and where it cannot (a server whose migration has not run,
 * or an address beyond any sense) the picture is left out, never the member.
 */
class GoogleAvatarLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
        Cache::forget(User::AVATAR_LIMIT_CACHE);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_sign_up_with_a_long_picture_address_makes_the_member_and_keeps_the_picture(): void
    {
        $avatar = 'https://lh3.googleusercontent.com/a-/'.str_repeat('A', 900).'=s96-c';
        $this->fakeGoogleUser($avatar);

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $member = User::query()->where('email', 'new.member@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertSame($avatar, $member->google_avatar);
    }

    public function test_an_address_longer_than_the_column_holds_is_left_out_and_the_member_still_signs_up(): void
    {
        $this->fakeGoogleUser('https://lh3.googleusercontent.com/a-/'.str_repeat('A', 3000));

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $member = User::query()->where('email', 'new.member@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertNull($member->google_avatar);
    }

    /** A server where the migration has not run yet: the column still holds 255 characters. */
    public function test_before_the_column_is_widened_a_long_address_is_left_out_instead_of_failing(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_avatar')->nullable()->change();
        });
        Cache::forget(User::AVATAR_LIMIT_CACHE);

        $this->assertNull(User::avatarThatFits('https://lh3.googleusercontent.com/a-/'.str_repeat('A', 400)));
        $short = 'https://lh3.googleusercontent.com/a/short=s96-c';
        $this->assertSame($short, User::avatarThatFits($short));

        $this->fakeGoogleUser('https://lh3.googleusercontent.com/a-/'.str_repeat('A', 400));
        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();
        $this->assertNull(User::query()->where('email', 'new.member@gmail.com')->firstOrFail()->google_avatar);
    }

    public function test_a_member_signing_in_again_keeps_their_picture_when_the_new_one_cannot_be_held(): void
    {
        $kept = 'https://lh3.googleusercontent.com/a/kept=s96-c';
        $member = User::factory()->create(['email' => 'new.member@gmail.com', 'google_id' => 'google-456', 'google_avatar' => $kept]);
        $this->fakeGoogleUser('https://lh3.googleusercontent.com/a-/'.str_repeat('A', 3000));

        $this->getLocalizedRoute('auth.social.callback')->assertRedirect();

        $this->assertAuthenticatedAs($member);
        $this->assertSame($kept, $member->fresh()->google_avatar);
    }

    private function fakeGoogleUser(string $avatar): void
    {
        $googleUser = (new SocialiteUser)->setRaw(['sub' => 'google-456', 'email' => 'new.member@gmail.com', 'email_verified' => true])->map([
            'id' => 'google-456',
            'name' => 'New Member',
            'nickname' => null,
            'email' => 'new.member@gmail.com',
            'avatar' => $avatar,
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
