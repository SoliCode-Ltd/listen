<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfanityFilterGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_generation_rejects_blocked_arabic_text(): void
    {
        Plan::factory()->free()->create([
            'monthly_chars' => 500000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 50000,
        ]);

        $user = User::factory()->create([
            'subscription_status_checked_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.generate', [
            'content' => 'هذا نص شرموطة غير مقبول',
            'target_language' => 'ar',
            'translate' => false,
            'voice_tier' => 'standard',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
        $response->assertJsonPath('errors.content.0', __('ui.backend.generation_text_prohibited'));
        $this->assertDatabaseCount('text_items', 0);

        $this->assertDatabaseHas('blocked_generations', [
            'user_id' => $user->id,
            'route_name' => 'dashboard.generate',
            'attribute' => 'content',
        ]);
    }
}
