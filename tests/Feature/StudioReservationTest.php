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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Parallel requests cannot overspend: the studio and the extension check the
 * limits and reserve the credits in one locked step, with the generation saved
 * inside it, for the audio the requested speed makes; and Generate has a
 * generous rate limit. Speech is faked.
 */
class StudioReservationTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Hello there';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('audio/reserved.mp3', 'audio');
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_limits_are_checked_and_the_credits_reserved_in_one_step_with_the_generation(): void
    {
        $user = $this->member(['daily_requests' => 50]);
        $this->fakeSpeech();
        $base = DB::transactionLevel();
        $step = 0;
        $log = [];
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$step, $base): void {
            if ($event->connection->transactionLevel() === $base + 1) {
                $step++;
            }
        });
        DB::listen(function (QueryExecuted $query) use (&$log, &$step, $base): void {
            $log[] = ['sql' => $query->sql, 'step' => $query->connection->transactionLevel() > $base ? $step : 0];
        });

        $this->generate($user)->assertOk();

        $stepOf = function (string $needle) use ($log): int {
            foreach ($log as $entry) {
                if (str_contains($entry['sql'], $needle)) {
                    return $entry['step'];
                }
            }
            $this->fail("No query with: {$needle}");
        };
        $dailyCheck = $stepOf('"created_at" >=');
        $this->assertGreaterThan(0, $dailyCheck, 'the daily limit is checked inside a transaction');
        $this->assertSame($dailyCheck, $stepOf('"total_requests" = "total_requests" + 1'), 'the credits are reserved in the same transaction');
        $this->assertSame($dailyCheck, $stepOf('insert into "audio_generations"'), 'and the generation is saved in it');
    }

    public function test_a_slower_speed_reserves_for_the_longer_audio(): void
    {
        // "Hello there" is about 3 seconds (45 credits); at half speed about 6 (90).
        $user = $this->member(['monthly_chars' => 60, 'voice_tier' => 'hd']);
        $this->fakeSpeech();

        $this->generate($user, ['voice_tier' => 'hd', 'voice_key' => $this->premiumKey(), 'speed' => 0.5])
            ->assertStatus(422)
            ->assertJsonPath('errors.content.0', __('ui.backend.monthly_chars_not_enough', ['remaining' => '60', 'needed' => '90']));
        $this->assertSame(0, AudioGeneration::query()->count(), 'nothing is made or reserved');
        $this->assertSame(0, (int) CreditPeriod::counter((int) $user->id)->total_chars);

        $this->generate($user, ['voice_tier' => 'hd', 'voice_key' => $this->premiumKey(), 'speed' => 1.0])->assertOk();
    }

    public function test_a_failed_slow_generation_gives_back_all_it_reserved(): void
    {
        $user = $this->member(['voice_tier' => 'hd']);
        $this->fakeSpeech(fails: true);

        $this->generate($user, ['voice_tier' => 'hd', 'voice_key' => $this->premiumKey(), 'speed' => 0.5])->assertStatus(422);

        $reservation = AudioGeneration::query()->latest('id')->value('settings_snapshot')['reservation'] ?? null;
        $this->assertSame(90, $reservation['credits'] ?? null, 'what was reserved is kept on the generation');
        $this->assertSame(6, $reservation['seconds'] ?? null);
        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(0, (int) $counter->total_chars, 'all of it given back');
        $this->assertSame(0, (int) $counter->total_seconds);
        $this->assertSame(0, (int) $counter->total_requests);
    }

    public function test_the_extension_reserves_for_the_speed_too(): void
    {
        $user = $this->member(['monthly_chars' => 60, 'voice_tier' => 'hd']);
        $this->fakeSpeech();
        $key = 'lk_live_'.str_repeat('s', 32);
        $user->forceFill(['api_key_hash' => User::hashApiKey($key)])->save();

        $this->withHeaders(['X-Listen-Key' => $key])->postJson('/api/reader/queue', [
            'content' => self::TEXT,
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => $this->premiumKey(),
            'speed' => 0.5,
            'prefer_sync' => true,
        ])->assertStatus(422)->assertJsonPath('errors.content.0', __('ui.backend.monthly_chars_not_enough', ['remaining' => '60', 'needed' => '90']));

        $this->assertSame(0, AudioGeneration::query()->count());
    }

    public function test_generate_is_rate_limited_with_a_message_the_studio_shows(): void
    {
        $user = $this->member();
        $other = $this->member();

        for ($i = 0; $i < 20; $i++) {
            // Refused by the form check, yet each one counts.
            $this->actingAs($user)->postJsonLocalizedRoute($i % 2 ? 'dashboard.generate' : 'dashboard.queue', [])->assertStatus(422);
        }

        app()->setLocale('ar');
        $response = $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [])->assertStatus(429)->assertJsonPath('ok', false);
        $this->assertStringContainsString('ثانية', (string) $response->json('message'), 'the studio counts down from the seconds in the message');
        $this->assertSame(__('ui.backend.generate_too_many_requests', ['seconds' => $response->headers->get('Retry-After')]), $response->json('message'));
        app()->setLocale('en');

        $this->actingAs($other)->postJsonLocalizedRoute('dashboard.queue', [])->assertStatus(422);

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [])->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function generate(User $user, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', array_merge([
            'content' => self::TEXT,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice('standard')->id),
        ], $overrides));
    }

    private function premiumKey(): string
    {
        return VoiceCatalog::keyFromId((int) $this->voice('hd')->id);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function member(array $plan = []): User
    {
        $plan = Plan::factory()->create(array_merge([
            'voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0,
        ], $plan));
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(string $tier): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-reserve-'.$tier], [
            'language' => 'en', 'gender' => 'female', 'tier' => $tier, 'display_name' => 'Reserve '.$tier,
            'provider' => $tier === 'hd' ? VoiceCatalog::preferredProviderForTier('hd') : 'google_cloud',
            'provider_voice_id' => 'en-US-reserve-'.$tier, 'is_active' => true,
        ]);
    }

    private function fakeSpeech(bool $fails = false): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $fails
            ? $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException('Speech service unavailable.'))
            : $tts->method('synthesizeAdvanced')->willReturn([
                'audio_path' => 'audio/reserved.mp3', 'duration_seconds' => 3, 'provider' => 'gemini', 'model' => 'premium',
                'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
            ]);
        $this->app->instance(TtsService::class, $tts);
    }
}
