<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\CreditPeriod;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The extension the store has now (1.2.0) waits about 30 seconds for a voice,
 * then says "try again" while the server is still making a long text. Asking
 * again for the same text, voice and options while it is being made gets that
 * voice back: it is reserved and charged once. Speech is faked, and nothing
 * runs after the reply, so the first voice is still being made when asked again.
 */
class ExtensionRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);
        $this->app->instance(TtsService::class, $this->createMock(TtsService::class));
        Bus::fake();
    }

    public function test_the_same_long_text_asked_for_again_while_it_is_being_made_is_charged_once(): void
    {
        $user = $this->member();
        $key = $this->extensionKey($user);

        $first = $this->ask($key, $this->longText())->assertStatus(202)->assertJsonPath('status', 'processing');
        $started = $this->madeAfterReplies();
        $again = $this->ask($key, $this->longText())->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('audio_url', null);

        $this->assertGreaterThan(0, (int) $first->json('job_id'));
        $this->assertSame($first->json('job_id'), $again->json('job_id'), 'the voice already being made');
        $this->assertSame(1, AudioGeneration::query()->count());
        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(1, (int) $counter->total_requests, 'reserved once');
        $this->assertSame((int) $first->json('estimated_credits'), (int) $counter->total_chars);
        $this->assertSame($started, $this->madeAfterReplies(), 'and not made a second time');

        // A different text is a voice of its own.
        $other = $this->ask($key, $this->longText('Every morning the baker opens the shop at six. '))->assertStatus(202);
        $this->assertNotSame($first->json('job_id'), $other->json('job_id'));
        $this->assertSame(2, AudioGeneration::query()->count());
        $this->assertSame(2, (int) CreditPeriod::counter((int) $user->id)->total_requests);
        $this->assertSame($started + 1, $this->madeAfterReplies());
    }

    public function test_after_30_minutes_the_same_text_is_made_again(): void
    {
        $key = $this->extensionKey($this->member());

        $first = $this->ask($key, $this->longText())->assertStatus(202);
        AudioGeneration::query()->update(['created_at' => now()->subMinutes(31)]);
        $again = $this->ask($key, $this->longText())->assertStatus(202);

        $this->assertNotSame($first->json('job_id'), $again->json('job_id'));
        $this->assertSame(2, AudioGeneration::query()->count());
    }

    /** The work handed to after the reply so far (the voices, and a paid member's welcome email). */
    private function madeAfterReplies(): int
    {
        return Bus::dispatchedAfterResponse(CallQueuedClosure::class)->count();
    }

    private function ask(string $key, string $text): \Illuminate\Testing\TestResponse
    {
        // As 1.2.0 asks: it wants to wait for every text.
        return $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => $text,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => $this->voiceKey(),
            'prefer_sync' => true,
        ]);
    }

    /** About 3,000 characters: made after the reply, from 2,500. */
    private function longText(string $sentence = 'The quick brown fox jumps over the lazy dog. '): string
    {
        return trim(str_repeat($sentence, (int) ceil(3000 / strlen($sentence))));
    }

    private function member(): User
    {
        $plan = Plan::factory()->create([
            'voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function extensionKey(User $user): string
    {
        $key = 'lk_live_'.str_repeat('r', 32);
        $user->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();

        return $key;
    }

    private function voiceKey(): string
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-retry-standard'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Retry',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-retry-standard', 'is_active' => true,
        ]);

        return VoiceCatalog::keyFromId((int) $voice->id);
    }
}
