<?php

namespace Tests\Feature;

use App\Models\BlockedGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Text refused for abusive wording is always logged for an admin, but only a
 * free member who tries twice within a day is blocked by it. Admins, members
 * an admin unblocked or trusted, paying members, and pages the extension read
 * from other sites never block an account by themselves.
 */
class BlockedWordingAutoBlockTest extends TestCase
{
    use RefreshDatabase;

    private const ABUSIVE = 'هذا نص شرموطة غير مقبول';

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard', 'is_active' => true,
        ]);
        $this->starter = Plan::query()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_minutes' => 250, 'monthly_chars' => 225000,
            'monthly_requests' => 3000, 'max_chars_per_request' => 3000, 'voice_tier' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_a_free_member_who_tries_twice_within_a_day_is_blocked(): void
    {
        $member = $this->member();

        $this->attempt($member)->assertStatus(422);
        $this->assertFalse($member->fresh()->isBlocked(), 'one attempt never blocks');

        $this->travel(3)->hours();
        $this->attempt($member)->assertStatus(422);

        $this->assertTrue($member->fresh()->isBlocked());
        $this->assertSame(2, BlockedGeneration::query()->where('user_id', $member->id)->count());
    }

    public function test_attempts_from_more_than_a_day_ago_do_not_count(): void
    {
        $member = $this->member();
        $this->attempt($member)->assertStatus(422);

        $this->travel(25)->hours();
        $this->attempt($member)->assertStatus(422);

        $this->assertFalse($member->fresh()->isBlocked());
        $this->assertSame(2, BlockedGeneration::query()->where('user_id', $member->id)->count(), 'both are still logged');
    }

    public function test_admins_trusted_members_and_paying_members_are_never_blocked_but_still_logged(): void
    {
        $paying = $this->member();
        Subscription::query()->where('user_id', $paying->id)->delete();
        Subscription::create([
            'user_id' => $paying->id, 'plan_id' => $this->starter->id, 'status' => 'active',
            'current_period_end' => now()->addDays(10),
        ]);

        $members = [
            'admin' => $this->member(['is_admin' => true]),
            'unblocked or trusted by an admin' => $this->member(['multi_account_whitelisted' => true]),
            'paying member' => $paying,
        ];

        foreach ($members as $who => $member) {
            $this->attempt($member)->assertStatus(422);
            $this->attempt($member)->assertStatus(422);

            $this->assertFalse($member->fresh()->isBlocked(), $who);
            $this->assertSame(2, BlockedGeneration::query()->where('user_id', $member->id)->count(), $who);
        }
    }

    public function test_pages_the_extension_read_do_not_count(): void
    {
        $member = $this->member();
        $key = $member->issueApiKey();

        $this->readerAttempt($key)->assertStatus(422);
        $this->readerAttempt($key)->assertStatus(422);
        $this->assertFalse($member->fresh()->isBlocked());

        // Then one on the site: still only one attempt that counts.
        $this->attempt($member)->assertStatus(422);
        $this->assertFalse($member->fresh()->isBlocked());

        $this->assertSame(
            ['api.reader.queue', 'api.reader.queue', 'dashboard.generate'],
            BlockedGeneration::query()->where('user_id', $member->id)->orderBy('id')->pluck('route_name')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['subscription_status_checked_at' => now()]);
    }

    private function attempt(User $member): TestResponse
    {
        return $this->actingAs($member)->postJsonLocalizedRoute('dashboard.generate', [
            'content' => self::ABUSIVE,
            'target_language' => 'ar',
            'translate' => false,
            'voice_tier' => 'standard',
        ]);
    }

    private function readerAttempt(string $key): TestResponse
    {
        return $this->postJson('/api/reader/queue', [
            'content' => self::ABUSIVE,
            'target_language' => 'ar',
        ], ['X-Listen-Key' => $key]);
    }
}
