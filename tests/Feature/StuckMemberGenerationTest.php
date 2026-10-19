<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\CharPackWallet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\MemberUsageMeter;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\GenerationMessage;
use App\Support\UsageEstimator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A member's generation that gets stuck (the web request or the worker died)
 * is failed and its credits given back exactly once, by the sweep, the job's
 * failed() hook, the request or deleting it; a late retry never makes it.
 */
class StuckMemberGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Hello there, this voice got stuck.';

    public function test_the_sweep_fails_and_refunds_stuck_member_generations_exactly_once(): void
    {
        $user = User::factory()->create();
        $stuckProcessing = $this->reserved($user, 'processing', 31);
        $stuckQueued = $this->reserved($user, 'queued', 61);
        $running = $this->reserved($user, 'processing', 5);
        $waiting = $this->reserved($user, 'queued', 45);
        $api = $this->reserved($user, 'processing', 90, ['api_key_id' => 999]);
        $counter = $this->counter($user);
        $usedBefore = (int) $counter->total_chars;
        $reserved = UsageEstimator::estimate(self::TEXT);

        $this->artisan('listen:expire-stuck-generations')->expectsOutputToContain('Expired 2')->assertSuccessful();
        $this->artisan('listen:expire-stuck-generations')->expectsOutputToContain('Expired 0')->assertSuccessful();

        foreach ([$stuckProcessing, $stuckQueued] as $generation) {
            $generation->refresh();
            $this->assertSame('failed', $generation->status);
            $this->assertSame(GenerationMessage::stored('ui.backend.generation_stuck_refunded'), $generation->error_message);
            $this->assertSame('failed', TextItem::query()->whereKey($generation->text_item_id)->value('status'));
        }
        $this->assertSame('processing', $running->fresh()->status, 'still within its time');
        $this->assertSame('queued', $waiting->fresh()->status, 'still waiting for a worker');
        $this->assertSame('processing', $api->fresh()->status, 'developer API generations have their own sweep');

        $counter->refresh();
        $this->assertSame($usedBefore - 2 * $reserved['credits'], (int) $counter->total_chars, 'two reservations given back, once each');
        $this->assertSame(3, (int) $counter->total_requests);
        $this->assertSame(3 * $reserved['seconds'], (int) $counter->total_seconds);
    }

    public function test_a_stuck_pack_generation_gives_the_pack_characters_back(): void
    {
        $user = User::factory()->create();
        CharPackWallet::query()->create(['user_id' => $user->id, 'chars_total' => 20000, 'chars_used' => 1000, 'expires_at' => now()->addMonth()]);
        $generation = $this->reserved($user, 'processing', 40, ['billed_to_pack' => true]);

        $this->artisan('listen:expire-stuck-generations')->assertSuccessful();

        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(1000 - UsageEstimator::estimate(self::TEXT)['credits'], (int) CharPackWallet::query()->where('user_id', $user->id)->value('chars_used'));
    }

    public function test_a_late_retry_never_makes_a_generation_the_sweep_failed(): void
    {
        $user = User::factory()->create();
        $generation = $this->reserved($user, 'processing', 31);
        $this->artisan('listen:expire-stuck-generations')->assertSuccessful();
        $tts = $this->createMock(TtsService::class);
        $tts->expects($this->never())->method('synthesizeAdvanced');
        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->never())->method('maybeTranslate');

        (new GenerateAudioJob($generation->id))->handle($translation, $tts);

        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->audio_path);
    }

    public function test_failed_gives_the_credits_back_only_once(): void
    {
        $user = User::factory()->create();
        // Other usage this month, so a second refund would show.
        $counter = $this->counter($user);
        $counter->update(['total_chars' => 500, 'total_requests' => 3]);
        $generation = $this->reserved($user, 'queued', 1);

        $job = new GenerateAudioJob($generation->id);
        $job->connection = 'database';
        $job->failed(new \RuntimeException('worker died'));
        $job->failed(new \RuntimeException('worker died again'));
        app(MemberUsageMeter::class)->failAndRefund($generation->id, 'swept', ['queued', 'processing']);

        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again'), $generation->fresh()->error_message);
        $this->assertSame(500, (int) $counter->fresh()->total_chars);
        $this->assertSame(3, (int) $counter->fresh()->total_requests);
    }

    public function test_a_studio_generation_that_fails_gives_its_credits_back_once(): void
    {
        $user = $this->member();
        // Other usage this month, so a second refund would show.
        $counter = UsageCounter::query()->create(['user_id' => $user->id, 'period_ym' => now()->format('Y-m'), 'total_chars' => 900, 'total_requests' => 4, 'total_seconds' => 60, 'total_minutes' => 1]);
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);
        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException('Speech service unavailable.'));
        $this->app->instance(TtsService::class, $tts);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => self::TEXT,
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => \App\Support\VoiceCatalog::keyFromId((int) $this->voice()->id),
        ])->assertStatus(422);

        $counter->refresh();
        $this->assertSame(900, (int) $counter->total_chars);
        $this->assertSame(4, (int) $counter->total_requests);
        $this->assertSame(60, (int) $counter->total_seconds);
    }

    public function test_deleting_a_stuck_generation_gives_its_credits_back_first(): void
    {
        $user = User::factory()->create();
        $stuck = $this->reserved($user, 'processing', 31);
        $recent = $this->reserved($user, 'processing', 5);
        $counter = $this->counter($user);
        $usedBefore = (int) $counter->total_chars;

        $delete = fn (AudioGeneration $generation) => $this->actingAs($user)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->deleteJson($this->localizedRoute('dashboard.jobs.destroy', ['job' => $generation->id]));

        $delete($recent)->assertStatus(409);
        $delete($stuck)->assertOk();

        $this->assertNull(AudioGeneration::query()->find($stuck->id));
        $this->assertSame($usedBefore - UsageEstimator::estimate(self::TEXT)['credits'], (int) $counter->fresh()->total_chars);
        $this->assertSame('processing', $recent->fresh()->status);
    }

    public function test_the_sweep_runs_every_five_minutes_one_at_a_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'listen:expire-stuck-generations'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * A member's generation whose request reserved its estimate, last touched
     * that many minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reserved(User $user, string $status, int $minutesAgo, array $attributes = []): AudioGeneration
    {
        $item = TextItem::query()->create(['user_id' => $user->id, 'source_type' => 'message', 'content' => self::TEXT, 'language' => 'en', 'status' => $status]);
        $generation = AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id,
            'text_item_id' => $item->id,
            'user_sequence_number' => (int) AudioGeneration::query()->where('user_id', $user->id)->max('user_sequence_number') + 1,
            'input_text' => self::TEXT,
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
            'status' => $status,
        ], $attributes));

        if (! ($attributes['billed_to_pack'] ?? false)) {
            $estimate = UsageEstimator::estimate(self::TEXT);
            $counter = $this->counter($user);
            $counter->increment('total_requests');
            $counter->increment('total_chars', $estimate['credits']);
            $counter->addSeconds($estimate['seconds']);
        }
        AudioGeneration::query()->whereKey($generation->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $generation->fresh();
    }

    private function counter(User $user): UsageCounter
    {
        return UsageCounter::query()->firstOrCreate(
            ['user_id' => $user->id, 'period_ym' => now()->format('Y-m')],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0],
        );
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(): \App\Models\Voice
    {
        return \App\Models\Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }
}
