<?php

namespace Tests\Feature;

use App\Filament\Pages\TtsSettings;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Voice;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\StandardVoiceEngine;
use App\Support\VoiceCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StandardVoiceEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.google_tts.audio_encoding' => 'MP3',
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        Http::fake([
            'https://texttospeech.googleapis.com/*' => Http::response(['audioContent' => base64_encode('ID3fake-mp3')], 200),
        ]);
    }

    private function useEngine(string $engine): void
    {
        SiteSetting::set(StandardVoiceEngine::SETTING_KEY, $engine, 'tts');
        Cache::flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sentVoices(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0]->data())
            ->filter(fn (array $data) => isset($data['voice']))
            ->values()
            ->all();
    }

    public function test_wavenet_stays_the_default(): void
    {
        $this->assertSame(StandardVoiceEngine::WAVENET, StandardVoiceEngine::current());

        $result = app(GoogleCloudTtsProvider::class)->synthesize('مرحبا بكم', 'ar', [
            'voice_name' => 'ar-XA-Wavenet-B',
            'tier' => 'standard',
            'gender' => 'male',
            'pitch' => 2.0,
        ]);

        $sent = $this->sentVoices()[0];
        $this->assertSame('ar-XA-Wavenet-B', $sent['voice']['name']);
        $this->assertSame(2.0, $sent['audioConfig']['pitch']);
        $this->assertSame(round(mb_strlen('مرحبا بكم.') * 16 / 1_000_000, 6), $result['cost_estimate_usd']);
    }

    public function test_chirp_engine_reads_standard_voices_with_matching_chirp_voices(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);

        $provider = app(GoogleCloudTtsProvider::class);
        $cases = [
            ['ar', 'ar-XA-Wavenet-A', 'female', 'ar-XA-Chirp3-HD-Aoede'],
            ['ar', 'ar-XA-Wavenet-B', 'male', 'ar-XA-Chirp3-HD-Puck'],
            ['ar', 'ar-XA-Standard-C', 'male', 'ar-XA-Chirp3-HD-Fenrir'],
            ['ar', 'ar-XA-Wavenet-D', 'female', 'ar-XA-Chirp3-HD-Zephyr'],
            ['en', 'en-US-Wavenet-C', 'female', 'en-US-Chirp3-HD-Leda'],
            ['en', 'en-US-Wavenet-D', 'male', 'en-US-Chirp3-HD-Orus'],
        ];

        foreach ($cases as [$language, $voice, $gender, $expected]) {
            $provider->synthesize('Hello there', $language, [
                'voice_name' => $voice,
                'tier' => 'standard',
                'gender' => $gender,
                'pitch' => 3.0,
            ]);
        }

        $sent = $this->sentVoices();
        $this->assertCount(count($cases), $sent);
        foreach ($cases as $i => [$language, $voice, $gender, $expected]) {
            $this->assertSame($expected, $sent[$i]['voice']['name'], "{$voice} should be read by {$expected}");
            $this->assertSame(strtoupper($gender), $sent[$i]['voice']['ssmlGender']);
            $this->assertArrayNotHasKey('pitch', $sent[$i]['audioConfig'], 'Chirp 3 HD rejects pitch');
            $this->assertSame(str_starts_with($expected, 'ar') ? 'ar-XA' : 'en-US', $sent[$i]['voice']['languageCode']);
        }
    }

    public function test_chirp_engine_also_covers_the_fallback_voice_and_records_the_chirp_rate(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);

        $result = app(GoogleCloudTtsProvider::class)->synthesize('Hello there.', 'en', [
            'tier' => 'standard',
            'gender' => 'female',
        ]);

        $name = $this->sentVoices()[0]['voice']['name'];
        $this->assertStringStartsWith('en-US-Chirp3-HD-', $name);
        $this->assertContains($name, StandardVoiceEngine::allChirpVoices('en-US'));
        $this->assertSame(round(12 * 30 / 1_000_000, 6), $result['cost_estimate_usd']);
    }

    public function test_hd_voices_are_not_touched_by_the_standard_engine(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);

        app(GoogleCloudTtsProvider::class)->synthesize('Hello there.', 'en', [
            'voice_name' => 'en-US-Chirp3-HD-Charon',
            'tier' => 'hd',
            'gender' => 'male',
        ]);

        $this->assertSame('en-US-Chirp3-HD-Charon', $this->sentVoices()[0]['voice']['name']);
    }

    public function test_switching_engine_does_not_replay_cached_wavenet_audio(): void
    {
        $options = ['voice_name' => 'ar-XA-Wavenet-A', 'tier' => 'standard', 'gender' => 'female'];

        $first = app(TtsService::class)->synthesizeAdvanced('نص قصير للتجربة', 'ar', $options);
        $again = app(TtsService::class)->synthesizeAdvanced('نص قصير للتجربة', 'ar', $options);
        $this->assertSame('google_cloud', $first['provider']);
        $this->assertSame('cache', $again['provider']);

        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);
        $chirp = app(TtsService::class)->synthesizeAdvanced('نص قصير للتجربة', 'ar', $options);

        $this->assertSame('google_cloud', $chirp['provider']);
        $voices = array_column(array_column($this->sentVoices(), 'voice'), 'name');
        $this->assertSame(['ar-XA-Wavenet-A', 'ar-XA-Chirp3-HD-Aoede'], $voices);
    }

    public function test_every_mapped_voice_is_a_known_chirp_voice(): void
    {
        foreach (['ar-XA' => range('A', 'D'), 'en-US' => range('A', 'J')] as $locale => $letters) {
            foreach ($letters as $letter) {
                $chirp = StandardVoiceEngine::chirpVoiceFor("{$locale}-Wavenet-{$letter}", substr($locale, 0, 2));
                $this->assertContains($chirp, StandardVoiceEngine::allChirpVoices($locale));
            }
        }
    }

    public function test_admin_can_switch_the_standard_engine_and_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TtsSettings::class)
            ->assertFormSet([StandardVoiceEngine::SETTING_KEY => StandardVoiceEngine::WAVENET])
            ->fillForm([StandardVoiceEngine::SETTING_KEY => StandardVoiceEngine::CHIRP3_HD])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        $this->assertTrue(StandardVoiceEngine::usesChirp());

        Livewire::test(TtsSettings::class)
            ->assertFormSet([StandardVoiceEngine::SETTING_KEY => StandardVoiceEngine::CHIRP3_HD])
            ->fillForm([StandardVoiceEngine::SETTING_KEY => StandardVoiceEngine::WAVENET])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        $this->assertFalse(StandardVoiceEngine::usesChirp());
    }

    public function test_blank_gender_is_never_sent_to_google(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);

        app(GoogleCloudTtsProvider::class)->synthesize('Hello there.', 'en', [
            'voice_name' => 'en-US-Wavenet-C',
            'tier' => 'standard',
            'gender' => '',
        ]);

        $voice = $this->sentVoices()[0]['voice'];
        $this->assertSame('en-US-Chirp3-HD-Leda', $voice['name']);
        $this->assertContains($voice['ssmlGender'], ['MALE', 'FEMALE', 'NEUTRAL']);
    }

    public function test_long_text_pieces_go_out_in_parallel_and_stay_in_order(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);
        config(['services.google_tts.max_parallel_requests' => 3]);

        $attempts = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$attempts) {
            $text = (string) ($request->data()['input']['text'] ?? '');
            preg_match('/جملة رقم (\d+)/u', $text, $m);
            $first = (int) ($m[1] ?? 0);
            $attempts[$first] = ($attempts[$first] ?? 0) + 1;

            // The second piece fails once and must be sent again on its own.
            if ($first > 1 && $attempts[$first] === 1 && count($attempts) === 2) {
                return Http::response(['error' => ['message' => 'busy']], 503);
            }

            return Http::response(['audioContent' => base64_encode("[{$first}]")], 200);
        });

        $sentences = [];
        for ($i = 1; $i <= 200; $i++) {
            $sentences[] = "هذه جملة رقم {$i} في نص طويل للتجربة.";
        }

        $result = app(GoogleCloudTtsProvider::class)->synthesize(implode(' ', $sentences), 'ar', [
            'voice_name' => 'ar-XA-Wavenet-A',
            'tier' => 'standard',
            'gender' => 'female',
        ]);

        $audio = Storage::disk('public')->get($result['audio_path']);
        preg_match_all('/\[(\d+)\]/', $audio, $m);
        $order = array_map('intval', $m[1]);

        $this->assertGreaterThan(3, count($order), 'text should need several pieces');
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'pieces must be joined in reading order');
        $this->assertSame(1, $order[0]);
        $this->assertContains(2, array_values($attempts), 'the failed piece was sent again');
        foreach ($this->sentVoices() as $data) {
            $this->assertSame('ar-XA-Chirp3-HD-Aoede', $data['voice']['name']);
        }
    }

    public function test_demo_stays_on_gemini_hd_when_standard_voices_use_chirp(): void
    {
        $this->useEngine(StandardVoiceEngine::CHIRP3_HD);

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        Plan::query()->create([
            'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10,
            'monthly_chars' => 10000, 'monthly_requests' => 60, 'max_chars_per_request' => 1200,
            'voice_tier' => 'standard', 'tts_provider_policy' => 'google_cloud', 'is_active' => true,
        ]);
        $gemini = Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Serene',
            'internal_voice_id' => 'en-Gemini-HD-Kore', 'provider' => 'gemini', 'provider_voice_id' => 'Kore', 'is_active' => true,
        ]);
        $standard = Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);

        $calls = ['gemini' => 0, 'google' => []];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$calls) {
            if (str_contains($request->url(), 'texttospeech')) {
                $calls['google'][] = $request->data()['voice']['name'] ?? null;

                return Http::response(['audioContent' => base64_encode('ID3google-audio')]);
            }
            $calls['gemini']++;

            return Http::response(['candidates' => [['content' => ['parts' => [[
                'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))],
            ]]]]]]);
        });

        $this->actingAs(User::factory()->create());

        // The demo page opens on HD.
        $this->getLocalizedRoute('demo.page')->assertOk()->assertSee('value="hd" selected', false);
        $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello world',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $gemini->id),
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
        ])->assertOk()->assertJson(['voice_tier' => 'hd']);

        $this->assertSame(1, $calls['gemini'], 'HD demo must be spoken by Gemini');
        $this->assertSame([], $calls['google'], 'HD demo must not touch Google');

        // Only a visitor who picks Standard in the demo hears the standard engine.
        $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello again',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $standard->id),
        ])->assertOk();

        $this->assertSame(1, $calls['gemini']);
        $this->assertSame(['en-US-Chirp3-HD-Leda'], $calls['google']);
    }
}
