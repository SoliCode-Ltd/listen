<?php

namespace Tests\Feature;

use App\Filament\Resources\AudioGenerationResource;
use App\Filament\Resources\AudioGenerationResource\Pages\ListAudioGenerations;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\AudioDuration;
use App\Support\ShareableAudio;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Each generation stores the real length of its audio file, and Admin →
 * Audio generations lists it. Speech is faked: no real audio is generated.
 */
class AudioLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false,
            'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
    }

    public function test_wav_length_is_read_from_the_file(): void
    {
        $this->assertEqualsWithDelta(2.5, AudioDuration::seconds($this->wav(2.5)), 0.001);
        $this->assertEqualsWithDelta(1.0, AudioDuration::seconds($this->wav(1.0, 48000, 2)), 0.001);
    }

    public function test_constant_bitrate_mp3_length_is_read_from_the_file(): void
    {
        // MPEG-1 Layer III, 128 kbps, 44.1 kHz: 417-byte frames of 1152 samples.
        $frame = "\xFF\xFB\x90\x64".str_repeat("\0", 413);
        $mp3 = str_repeat($frame, 100);

        $this->assertEqualsWithDelta(100 * 417 * 8 / 128000, AudioDuration::seconds($mp3), 0.001);
    }

    public function test_mp3_made_like_the_site_makes_it_has_the_right_length(): void
    {
        if ((new ExecutableFinder)->find('ffmpeg') === null) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        $packaged = ShareableAudio::package($this->wav(7.0));
        $this->assertSame('mp3', $packaged['extension']);
        $this->assertEqualsWithDelta(7.0, AudioDuration::seconds($packaged['bytes']), 0.1);
    }

    public function test_unknown_bytes_and_missing_files_have_no_length(): void
    {
        $this->assertNull(AudioDuration::seconds('not audio'));
        $this->assertNull(AudioDuration::ofStoredFile('audio/missing.mp3'));
        $this->assertNull(AudioDuration::ofStoredFile(null));
    }

    public function test_labels(): void
    {
        $this->assertSame('—', AudioDuration::label(null));
        $this->assertSame('45 s', AudioDuration::label(45));
        $this->assertSame('3:07', AudioDuration::label(187));
        $this->assertSame('1:02:05', AudioDuration::label(3725));
        $this->assertSame('12 min 30 s', AudioGenerationResource::totalLengthLabel(750));
        $this->assertSame('3 h 05 min', AudioGenerationResource::totalLengthLabel(3 * 3600 + 300));
    }

    public function test_generation_stores_the_real_length_not_the_guess_from_the_text(): void
    {
        Storage::disk('public')->put('audio/real.wav', $this->wav(5.0));
        $this->fakeSpeech(['audio_path' => 'audio/real.wav', 'duration_seconds' => 3, 'provider' => 'fake']);

        $user = User::factory()->create();
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued']));
        GenerateAudioJob::dispatchSync($generation->id);

        $generation->refresh();
        $this->assertSame('done', $generation->status);
        $this->assertSame(5, (int) $generation->duration_seconds);
    }

    public function test_provider_figure_is_kept_when_the_file_cannot_be_read(): void
    {
        $this->fakeSpeech(['audio_path' => 'audio/gone.mp3', 'duration_seconds' => 9, 'provider' => 'fake']);

        $user = User::factory()->create();
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued']));
        GenerateAudioJob::dispatchSync($generation->id);

        $this->assertSame(9, (int) $generation->fresh()->duration_seconds);
    }

    public function test_admin_list_shows_each_length_and_the_total(): void
    {
        $user = User::factory()->create();
        AudioGeneration::create($this->audioRow($user, ['duration_seconds' => 65]));
        AudioGeneration::create($this->audioRow($user, ['duration_seconds' => 40]));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAudioGenerations::class)
            ->assertSee('Length')
            ->assertSee('1:05')
            ->assertSee('40 s')
            ->assertSee('1 min 45 s');
    }

    public function test_command_measures_older_generations(): void
    {
        Storage::disk('public')->put('audio/old.wav', $this->wav(12.0));
        $user = User::factory()->create();
        $old = AudioGeneration::create($this->audioRow($user, ['audio_path' => 'audio/old.wav', 'duration_seconds' => 4]));
        $missing = AudioGeneration::create($this->audioRow($user, ['audio_path' => 'audio/gone.mp3', 'duration_seconds' => 6]));

        $this->artisan('listen:measure-audio-lengths')
            ->expectsOutputToContain('Length differs from the file: 1.')
            ->assertSuccessful();
        $this->assertSame(4, (int) $old->fresh()->duration_seconds, 'a dry run changes nothing');

        $this->artisan('listen:measure-audio-lengths --apply')->assertSuccessful();
        $this->assertSame(12, (int) $old->fresh()->duration_seconds);
        $this->assertSame(6, (int) $missing->fresh()->duration_seconds);
    }

    private function wav(float $seconds, int $sampleRate = 24000, int $channels = 1): string
    {
        $data = str_repeat("\0", (int) round($seconds * $sampleRate) * 2 * $channels);
        $byteRate = $sampleRate * $channels * 2;

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $channels * 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fakeSpeech(array $result): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn($result);
        $this->app->instance(TtsService::class, $tts);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function audioRow(User $user, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'input_text' => 'Hello there',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
            'translate' => false,
            'status' => 'done',
        ], $overrides);
    }
}
