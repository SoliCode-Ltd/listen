<?php

namespace Tests\Feature;

use App\Models\BlockedGeneration;
use App\Models\User;
use App\Services\BlockedUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Accounts the old word list blocked for ordinary words are unblocked; the
 * ones today's list and rule would still block stay blocked, and accounts
 * blocked for anything else are never touched.
 */
class RecheckWordingBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_accounts_todays_rule_would_not_block_are_unblocked(): void
    {
        $falsePositive = $this->blockedFor([['Press x to start', 'x', 0], ['The x factor', 'x', 2]]);
        $abusive = $this->blockedFor([['fuck you', 'fuck', 0], ['shit happens', 'shit', 3]]);
        $weekApart = $this->blockedFor([['fuck you', 'fuck', 0], ['shit happens', 'shit', 24 * 7]]);
        $fromTheExtension = $this->blockedFor([['fuck you', 'fuck', 0], ['shit happens', 'shit', 1]], route: 'api.reader.queue');
        $byAnAdmin = $this->blockedFor([['Press x to start', 'x', 0], ['The x factor', 'x', 1]]);
        $byAnAdmin->forceFill(['blocked_by' => User::factory()->create(['is_admin' => true])->id])->save();
        $otherReason = $this->blockedFor([['Press x to start', 'x', 0]]);
        $otherReason->forceFill(['blocked_reason_en' => 'Multiple accounts', 'blocked_reason_ar' => 'حسابات متعددة', 'blocked_reason' => 'Multiple accounts'])->save();

        $this->artisan('listen:recheck-wording-blocks')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Accounts the wording rule blocked by itself: 4')
            ->expectsOutputToContain('Would be unblocked: 3')
            ->assertSuccessful();
        $this->assertTrue($falsePositive->fresh()->is_blocked, 'a dry run changes nothing');

        $this->artisan('listen:recheck-wording-blocks', ['--apply' => true])
            ->expectsOutputToContain('Unblocked: 3')
            ->assertSuccessful();

        $this->assertFalse($falsePositive->fresh()->is_blocked, 'blocked for "x" only');
        $this->assertFalse($weekApart->fresh()->is_blocked, 'two refused texts a week apart: today\'s rule needs them within a day');
        $this->assertFalse($fromTheExtension->fresh()->is_blocked, 'texts read by the extension never block');
        $this->assertTrue($abusive->fresh()->is_blocked, 'still refused today, within a day');
        $this->assertTrue($byAnAdmin->fresh()->is_blocked, 'an admin\'s block is the admin\'s');
        $this->assertTrue($otherReason->fresh()->is_blocked, 'blocked for another reason');
        $this->assertFalse((bool) $falsePositive->fresh()->multi_account_whitelisted, 'unblocked, but not trusted for later');
    }

    public function test_an_admin_unblock_still_trusts_the_member(): void
    {
        $member = $this->blockedFor([['fuck you', 'fuck', 0], ['shit happens', 'shit', 1]]);

        app(BlockedUserService::class)->unblock($member);

        $this->assertFalse($member->fresh()->is_blocked);
        $this->assertTrue((bool) $member->fresh()->multi_account_whitelisted);
    }

    /**
     * A member the wording rule blocked by itself after these refused texts.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $texts  text, the word refused then, hours after the first
     */
    private function blockedFor(array $texts, string $route = 'dashboard.queue'): User
    {
        $member = User::factory()->create();
        $start = Carbon::parse('2026-10-01 10:00:00');
        foreach ($texts as [$text, $word, $hours]) {
            $row = BlockedGeneration::query()->create([
                'user_id' => $member->id,
                'route_name' => $route,
                'request_path' => '/en/dashboard/queue',
                'attribute' => 'content',
                'input_text' => $text,
                'matched_term' => $word,
                'matched_language' => 'en',
            ]);
            $row->forceFill(['created_at' => $start->copy()->addHours($hours)])->save();
        }
        app(BlockedUserService::class)->block($member);

        return $member->fresh();
    }
}
