<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use App\Models\Voice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\PremiumVoiceUnavailableException;
use App\Services\Tts\TtsRoutingService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\CreditPeriod;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A member is only charged for a voice they can play: a generation whose file
 * was not saved (or was saved empty) fails and gives its credits back, and the
 * engines say so when the disk could not write the file (the public disk
 * returns false instead of throwing). Speech is faked.
 */
class SavedVoiceFileTest extends TestCase
{
    use RefreshDatabase;

    private Voice $voice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.google_tts.audio_encoding' => 'MP3',
        ]);
        $this->voice = Voice::query()->create([
            'internal_voice_id' => 'en-US-saved-standard', 'language' => 'en', 'gender' => 'female', 'tier' => 'standard',
            'display_name' => 'Saved standard', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-saved-standard', 'is_active' => true,
        ]);
    }

    public function test_a_voice_whose_file_was_never_saved_fails_and_is_given_back(): void
    {
        $user = $this->member();
        $this->fakeSpeech('audio/never-saved.mp3');

        $this->generate($user)->assertStatus(422);

        $this->assertFailedAndGivenBack($user);
    }

    public function test_an_empty_voice_file_fails_and_is_given_back(): void
    {
        Storage::disk('public')->put('audio/empty.mp3', '');
        $user = $this->member();
        $this->fakeSpeech('audio/empty.mp3');

        $this->generate($user)->assertStatus(422);

        $this->assertFailedAndGivenBack($user);
    }

    public function test_a_saved_voice_is_still_delivered_and_charged(): void
    {
        Storage::disk('public')->put('audio/saved.mp3', 'audio');
        $user = $this->member();
        $this->fakeSpeech('audio/saved.mp3');

        $this->generate($user)->assertOk();

        $this->assertSame('done', AudioGeneration::query()->latest('id')->value('status'));
        $this->assertSame(1, (int) CreditPeriod::counter((int) $user->id)->total_requests);
    }

    public function test_the_engines_say_so_when_the_disk_cannot_save_the_voice(): void
    {
        $this->diskWhere('put');
        $this->fakeEngines();

        $this->assertThrows(
            fn () => app(GoogleCloudTtsProvider::class)->synthesize('Hello there', 'en', ['tier' => 'standard', 'gender' => 'female']),
            \RuntimeException::class,
            'The voice file could not be saved.',
        );
        // An HD voice: the member is told the premium voices are busy, and nothing is charged.
        $this->assertThrows(
            fn () => app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', [
                'voice_name' => 'Kore', 'provider' => 'gemini', 'tier' => 'hd', 'gender' => 'female',
                'performance_style' => 'neutral', 'emotion' => 'neutral',
            ], Plan::factory()->create(['voice_tier' => 'hd', 'price_cents' => 900])),
            PremiumVoiceUnavailableException::class,
            'The voice file could not be saved.',
        );
        $this->assertThrows(
            fn () => app(TtsService::class)->synthesize('Hello there', 'en'),
            \RuntimeException::class,
            'The voice file could not be saved.',
        );
    }

    public function test_a_cache_copy_that_fails_keeps_the_voice_where_it_was_saved(): void
    {
        $this->diskWhere('copy');
        $this->fakeEngines();

        $path = app(TtsService::class)->synthesize('Hello there', 'en')['audio_path'];

        $this->assertStringStartsNotWith('audio/cache/', $path);
        $this->assertTrue(Storage::disk('public')->exists($path), 'the voice the member gets is there');
        $this->assertSame([], Storage::disk('public')->allFiles('audio/cache'), 'no cut-short copy is kept');
    }

    public function test_an_empty_cached_voice_is_made_again(): void
    {
        $this->fakeEngines();
        $first = app(TtsService::class)->synthesize('Hello there', 'en')['audio_path'];
        $this->assertStringStartsWith('audio/cache/', $first);
        // A copy cut short before this fix left an empty file in the cache.
        Storage::disk('public')->put($first, '');

        $again = app(TtsService::class)->synthesize('Hello there', 'en')['audio_path'];

        $this->assertCount(2, Http::recorded(), 'the voice was made again');
        $this->assertGreaterThan(0, Storage::disk('public')->size($again));
    }

    private function assertFailedAndGivenBack(User $user): void
    {
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $generation->status, 'never "done" without a file');
        $this->assertSame(GenerationMessage::stored('ui.backend.dashboard_voice_generation_failed_try_again'), $generation->error_message);
        $this->assertSame('failed', TextItem::query()->whereKey($generation->text_item_id)->value('status'));

        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(0, (int) $counter->total_requests, 'nothing charged');
        $this->assertSame(0, (int) $counter->total_chars);
        $this->assertSame(0, (int) $counter->total_seconds);
    }

    private function generate(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice->id),
        ]);
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

    private function fakeSpeech(string $audioPath): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn([
            'audio_path' => $audioPath, 'duration_seconds' => 3, 'provider' => 'google_cloud', 'model' => 'standard',
            'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
        ]);
        $this->app->instance(TtsService::class, $tts);
    }

    /** Both engines answer with audio; one Gemini key. */
    private function fakeEngines(): void
    {
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        Http::fake(fn ($request) => str_contains($request->url(), 'texttospeech')
            ? Http::response(['audioContent' => base64_encode('ID3fake-mp3')])
            : Http::response(['candidates' => [['content' => ['parts' => [[
                'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))],
            ]]]]]]));
    }

    /**
     * The public disk with one kind of write failing the way it reports it:
     * false, no exception ('throw' => false).
     */
    private function diskWhere(string $failing): void
    {
        $fake = Storage::disk('public');
        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $failing) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private string $failing)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function put($path, $contents, $options = [])
            {
                return $this->failing === 'put' ? false : parent::put($path, $contents, $options);
            }

            // Long takes are written as a stream: the same kind of write.
            public function writeStream($path, $resource, array $options = [])
            {
                return $this->failing === 'put' ? false : parent::writeStream($path, $resource, $options);
            }

            public function copy($from, $to)
            {
                return $this->failing === 'copy' ? false : parent::copy($from, $to);
            }
        });
    }
}
