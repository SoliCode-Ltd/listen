<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Services\Auth\FirebaseTokenVerifier;
use App\Services\Auth\PhoneAuthBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_per_email(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com', 'password' => Hash::make('correct-password')]);

        for ($i = 0; $i < 10; $i++) {
            $this->postLocalizedRoute('login.submit', ['email' => 'member@example.com', 'password' => 'wrong-'.$i]);
        }

        // Even the right password is refused once the limit is reached.
        $response = $this->postLocalizedRoute('login.submit', ['email' => 'member@example.com', 'password' => 'correct-password']);

        $response->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression('/\d+/', (string) session('errors')->first('email'));
        $this->assertNotSame(__('ui.backend.auth_invalid_credentials'), session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_login_still_works_within_the_limit(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com', 'password' => Hash::make('correct-password')]);

        $this->postLocalizedRoute('login.submit', ['email' => 'member@example.com', 'password' => 'wrong']);
        $this->postLocalizedRoute('login.submit', ['email' => 'member@example.com', 'password' => 'correct-password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_contact_form_is_rate_limited(): void
    {
        $payload = ['name' => 'Visitor', 'email' => 'visitor@example.com', 'subject' => 'Hello', 'message' => 'Hi there'];

        for ($i = 0; $i < 7; $i++) {
            $this->postLocalizedRoute('contact.submit', $payload);
        }

        $this->assertSame(5, Contact::count());
    }

    public function test_web_pages_send_security_headers(): void
    {
        $response = $this->getLocalizedRoute('login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->getLocalizedRoute('login')->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ])->get('https://localhost'.$this->localizedRoute('login'));
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_media_route_serves_audio_but_never_hidden_or_php_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/20260101_000000_listen.studio_abc.mp3', 'ID3-audio');

        $this->get('/media/audio/20260101_000000_listen.studio_abc.mp3')->assertOk();
        $this->get('/media/.htaccess')->assertNotFound();
        $this->get('/media/index.php')->assertNotFound();
        $this->get('/media/audio/../../.env')->assertNotFound();
    }

    public function test_extension_api_routes_are_rate_limited(): void
    {
        foreach (['/api/reader/jobs', '/api/reader/profile'] as $uri) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertContains('throttle:extension-api', $route->gatherMiddleware(), $uri);
        }
        $route = app('router')->getRoutes()->match(Request::create('/api/reader/queue', 'POST'));
        $this->assertContains('throttle:extension-api', $route->gatherMiddleware());
    }

    public function test_firebase_google_sign_in_uses_the_google_identity_email_not_an_unverified_one(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);

        $this->fakeFirebaseIdentity([
            'uid' => 'attacker-uid',
            'email' => 'victim@example.com',
            'email_verified' => false,
            'provider_emails' => ['password' => 'victim@example.com', 'google.com' => 'attacker@gmail.com'],
        ]);

        $user = app(PhoneAuthBridgeService::class)->resolveUserFromGoogleIdToken('token', '127.0.0.1');

        $this->assertNotSame($victim->id, $user->id);
        $this->assertSame('attacker@gmail.com', $user->email);
        $this->assertNull($victim->fresh()->firebase_uid);
    }

    public function test_firebase_google_sign_in_refuses_to_link_an_unverified_email_to_an_existing_account(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);

        $this->fakeFirebaseIdentity([
            'uid' => 'attacker-uid',
            'email' => 'victim@example.com',
            'email_verified' => false,
            'provider_emails' => [],
        ]);

        try {
            app(PhoneAuthBridgeService::class)->resolveUserFromGoogleIdToken('token', '127.0.0.1');
            $this->fail('An unverified email must not link to an existing account.');
        } catch (RuntimeException $e) {
            $this->assertSame('google_email_not_verified', $e->getMessage());
        }

        $this->assertNull($victim->fresh()->firebase_uid);
    }

    public function test_firebase_google_sign_in_still_links_a_verified_google_email(): void
    {
        $member = User::factory()->create(['email' => 'member@gmail.com']);

        $this->fakeFirebaseIdentity([
            'uid' => 'member-uid',
            'email' => 'member@gmail.com',
            'email_verified' => true,
            'provider_emails' => ['google.com' => 'member@gmail.com'],
        ]);

        $user = app(PhoneAuthBridgeService::class)->resolveUserFromGoogleIdToken('token', '127.0.0.1');

        $this->assertSame($member->id, $user->id);
    }

    private function fakeFirebaseIdentity(array $identity): void
    {
        $verifier = Mockery::mock(FirebaseTokenVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(array_merge([
            'phone_number' => null,
            'display_name' => 'Someone',
            'photo_url' => null,
            'provider_ids' => ['google.com'],
        ], $identity));
        $this->app->instance(FirebaseTokenVerifier::class, $verifier);
    }
}
