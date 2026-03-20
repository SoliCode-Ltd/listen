<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BlockedUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class BlockedUserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => 'Abusive usage',
        ]);

        $response = $this->postLocalizedRoute('login.submit', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHas('blocked_notice', function ($notice): bool {
            return is_array($notice)
                && ($notice['reason'] ?? null) === 'Abusive usage'
                && ($notice['email'] ?? null) === 'help@listenai.studio';
        });
        $this->assertGuest();
    }

    public function test_blocked_user_is_redirected_out_of_dashboard_session(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_at' => now(),
        ]);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('blocked_notice');
        $this->assertGuest();
    }

    public function test_blocked_user_api_key_access_is_denied(): void
    {
        $user = User::factory()->create();
        $apiKey = $user->issueApiKey();

        app(BlockedUserService::class)->block($user, 'API abuse');

        $response = $this->getJson('/api/reader/profile?api_key='.$apiKey);

        $response->assertStatus(403);
        $response->assertSee(__('ui.backend.account_blocked_with_reason', [
            'reason' => 'API abuse',
            'email' => 'help@listenai.studio',
        ]));
    }

    public function test_block_message_uses_locale_specific_reason_when_available(): void
    {
        $user = User::factory()->create([
            'is_blocked' => true,
            'blocked_reason_ar' => 'سبب عربي',
            'blocked_reason_en' => 'English reason',
        ]);

        $service = app(BlockedUserService::class);
        $originalLocale = App::currentLocale();

        App::setLocale('ar');
        $this->assertStringContainsString('سبب عربي', $service->messageFor($user));

        App::setLocale('en');
        $this->assertStringContainsString('English reason', $service->messageFor($user));

        App::setLocale($originalLocale);
    }
}
