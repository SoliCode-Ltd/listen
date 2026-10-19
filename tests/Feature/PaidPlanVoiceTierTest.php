<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nobody who pays gets the standard voices: every paid plan and every pack
 * uses the premium (HD) voices, whatever its voice tier field says.
 */
class PaidPlanVoiceTierTest extends TestCase
{
    use RefreshDatabase;

    private function tier(?Plan $plan): string
    {
        return app(PlanAccessService::class)->resolveTierForPlan($plan);
    }

    public function test_a_paid_plan_gets_the_premium_voices_whatever_its_field_says(): void
    {
        $this->assertSame(VoiceTier::HD, $this->tier(new Plan(['slug' => 'starter', 'price_cents' => 900, 'voice_tier' => VoiceTier::STANDARD])));
        $this->assertSame(VoiceTier::HD, $this->tier(new Plan(['slug' => 'lite', 'price_cents' => 300, 'voice_tier' => null])));
        $this->assertSame(VoiceTier::HD, $this->tier(new Plan(['slug' => 'creator', 'price_cents' => 2200, 'voice_tier' => VoiceTier::HD])));
    }

    public function test_a_pack_gets_the_premium_voices(): void
    {
        $this->assertSame(VoiceTier::HD, $this->tier(new Plan(['slug' => 'pack', 'kind' => Plan::KIND_PACK, 'price_cents' => 0, 'voice_tier' => VoiceTier::STANDARD])));
    }

    public function test_the_free_plan_keeps_its_own_setting(): void
    {
        $this->assertSame(VoiceTier::STANDARD, $this->tier(new Plan(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => VoiceTier::STANDARD])));
        $this->assertSame(VoiceTier::HD, $this->tier(new Plan(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => VoiceTier::HD])));
        $this->assertSame(VoiceTier::STANDARD, $this->tier(null));
    }

    public function test_a_member_on_a_paid_plan_saved_as_standard_gets_the_premium_voices(): void
    {
        $plan = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'voice_tier' => VoiceTier::STANDARD, 'is_active' => true,
        ]);
        $member = User::factory()->create();
        $access = app(PlanAccessService::class);
        $access->assignPlan($member, $plan, 'active', now()->addMonth());

        $this->assertSame(VoiceTier::HD, $access->resolveTierForPlan($access->resolveAccessPlanForUserId((int) $member->id)));
    }
}
