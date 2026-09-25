<?php

namespace Tests\Unit;

use App\Models\ClonedVoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClonedVoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_ready_returns_true_when_status_ready_and_key_present(): void
    {
        $voice = ClonedVoice::factory()->ready()->create();

        $this->assertTrue($voice->isReady());
    }

    public function test_is_ready_returns_false_when_status_processing(): void
    {
        $voice = ClonedVoice::factory()->processing()->create();

        $this->assertFalse($voice->isReady());
    }

    public function test_is_ready_returns_false_when_key_missing(): void
    {
        $voice = ClonedVoice::factory()->create([
            'status' => 'ready',
            'provider_voice_key' => null,
        ]);

        $this->assertFalse($voice->isReady());
    }

    public function test_is_failed_returns_true_when_status_failed(): void
    {
        $voice = ClonedVoice::factory()->failed()->create();

        $this->assertTrue($voice->isFailed());
    }

    public function test_is_processing_returns_true_for_intermediate_states(): void
    {
        foreach (['pending', 'validating', 'processing'] as $status) {
            $voice = ClonedVoice::factory()->create(['status' => $status]);
            $this->assertTrue($voice->isProcessing(), "Expected isProcessing() to be true for status: {$status}");
        }
    }

    public function test_is_processing_returns_false_for_terminal_states(): void
    {
        foreach (['ready', 'failed'] as $status) {
            $voice = ClonedVoice::factory()->create(['status' => $status]);
            $this->assertFalse($voice->isProcessing(), "Expected isProcessing() to be false for status: {$status}");
        }
    }

    public function test_scope_ready_returns_only_ready_voices_with_keys(): void
    {
        ClonedVoice::factory()->ready()->create();
        ClonedVoice::factory()->processing()->create();
        ClonedVoice::factory()->failed()->create();

        $ready = ClonedVoice::ready()->get();

        $this->assertCount(1, $ready);
        $this->assertEquals('ready', $ready->first()->status);
    }

    public function test_scope_for_user_filters_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ClonedVoice::factory()->create(['user_id' => $user1->id]);
        ClonedVoice::factory()->create(['user_id' => $user1->id]);
        ClonedVoice::factory()->create(['user_id' => $user2->id]);

        $this->assertCount(2, ClonedVoice::forUser($user1->id)->get());
        $this->assertCount(1, ClonedVoice::forUser($user2->id)->get());
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $voice = ClonedVoice::factory()->create(['user_id' => $user->id]);

        $this->assertEquals($user->id, $voice->user->id);
    }

    public function test_user_has_many_cloned_voices(): void
    {
        $user = User::factory()->create();
        ClonedVoice::factory()->count(3)->create(['user_id' => $user->id]);

        $this->assertCount(3, $user->clonedVoices);
    }

    public function test_user_ready_cloned_voices_only_returns_ready(): void
    {
        $user = User::factory()->create();
        ClonedVoice::factory()->ready()->create(['user_id' => $user->id]);
        ClonedVoice::factory()->processing()->create(['user_id' => $user->id]);

        $this->assertCount(1, $user->readyClonedVoices);
    }

    public function test_soft_delete_works(): void
    {
        $voice = ClonedVoice::factory()->create();
        $voice->delete();

        $this->assertSoftDeleted('cloned_voices', ['id' => $voice->id]);
        $this->assertCount(0, ClonedVoice::all());
        $this->assertCount(1, ClonedVoice::withTrashed()->get());
    }
}
