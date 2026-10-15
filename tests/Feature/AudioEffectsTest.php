<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\AudioEffects;
use App\Support\AudioFormats;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioEffectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_effect_has_both_names_a_group_and_a_drawn_icon(): void
    {
        $script = (string) file_get_contents(public_path('js/voice-studio-icons.js'));
        $glyphs = substr($script, strpos($script, 'var GLYPHS = {'));
        $glyphs = substr($glyphs, 0, strpos($glyphs, "\n  };"));

        foreach (AudioEffects::forPicker(true) as $key => $row) {
            $this->assertArrayHasKey($row['g'], AudioEffects::GROUPS, $key);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $row['d'], $key);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', AudioEffects::label($key, true), $key);
            $this->assertNotSame('', AudioEffects::label($key, false), $key);
            $this->assertMatchesRegularExpression('/(?:^|[\s{,])\'?'.preg_quote(substr($row['i'], 2), '/').'\'?:/m', $glyphs, $row['i'].' has a drawing');
        }
    }

    public function test_each_effect_makes_a_new_recording_and_leaves_the_clean_one_alone(): void
    {
        $this->requireFfmpeg();
        Storage::fake('public');
        $this->tone('audio/cache/clean.mp3', 2);
        $before = md5(Storage::disk('public')->get('audio/cache/clean.mp3'));

        foreach (AudioEffects::keys() as $effect) {
            $path = AudioEffects::apply('audio/cache/clean.mp3', $effect);
            $this->assertNotNull($path, $effect);
            $this->assertStringStartsWith('audio/effects/clean-'.$effect.'-', $path);
            $this->assertStringEndsWith('.mp3', $path);
            $seconds = $this->seconds($path);
            $this->assertGreaterThan(1.9, $seconds, $effect.' keeps the whole recording');
            $this->assertLessThan(5.2, $seconds, $effect.' adds at most a short tail');
        }
        $this->assertSame($before, md5(Storage::disk('public')->get('audio/cache/clean.mp3')), 'the shared clean voice is never changed');

        $this->assertNull(AudioEffects::apply('audio/cache/clean.mp3', 'nope'));
        $this->assertNull(AudioEffects::apply('audio/cache/missing.mp3', 'echo'));
    }

    public function test_rooms_are_stereo_around_a_centred_voice_and_every_effect_has_one_loudness(): void
    {
        $this->requireFfmpeg();
        Storage::fake('public');
        $this->tone('audio/cache/clean.mp3', 2);

        $ffprobe = dirname((string) AudioFormats::ffmpegPath()).'/ffprobe';
        foreach (['hall', 'phone'] as $effect) {
            $path = AudioEffects::apply('audio/cache/clean.mp3', $effect);
            $probe = new Process([$ffprobe, '-v', 'error', '-show_entries', 'stream=channels', '-of', 'csv=p=0', Storage::disk('public')->path($path)]);
            $this->assertSame('2', trim($probe->mustRun()->getOutput()), $effect);
        }
        $this->assertStringContainsString('loudnorm=I=-16', AudioEffects::graph('[in]anull[out]', 0.0));

        $inputs = [];
        $graph = AudioEffects::graph('[in]ROOM(hall,-4),BED(room_tone,-20)[out]', 1.0, $inputs, 3.0, -2.5);
        $this->assertStringContainsString('afir=', $graph);
        $this->assertStringContainsString('volume=-2.5dB,alimiter', $graph, 'one fixed gain, no swelling');
        $this->assertStringContainsString('sidechaincompress', $graph, 'the background dips while the voice speaks');
        $this->assertSame([false, true], array_column($inputs, 'loop'));
        $this->assertStringEndsWith('ir/hall.flac', $inputs[0]['path']);
        $this->assertStringEndsWith('bed/room_tone.flac', $inputs[1]['path']);
    }

    public function test_every_room_and_background_an_effect_uses_is_shipped(): void
    {
        foreach (AudioEffects::ALL as $key => $row) {
            preg_match_all('/(ROOM|BED)\(([a-z_]+),/', $row[6], $m, PREG_SET_ORDER);
            foreach ($m as [, $kind, $name]) {
                $this->assertFileExists(AudioEffects::assetPath($kind === 'ROOM' ? 'ir' : 'bed', $name), $key);
            }
        }
    }

    public function test_a_premium_generation_keeps_the_effect_and_gets_the_effected_recording(): void
    {
        $this->requireFfmpeg();
        Storage::fake('public');
        $this->tone('audio/voice.mp3', 2);
        $this->fakeSpeech('audio/voice.mp3');
        $user = $this->memberOn(VoiceTier::HD);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there, welcome to the show.',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice('hd')->id),
            'audio_effect' => 'hall',
        ])->assertOk();

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('done', $generation->status);
        $this->assertSame('hall', $generation->settings_snapshot['audio_effect']);
        $this->assertStringStartsWith('audio/effects/voice-hall-', $generation->audio_path);
        $this->assertSame(2, (int) $generation->duration_seconds, 'the echo tail is not charged');
        $this->assertSame('hall', $generation->reusableSettings()['audio_effect']);
    }

    public function test_an_unknown_effect_is_refused_and_plans_without_sound_environments_get_none(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/voice.mp3', 'audio');
        $this->fakeSpeech('audio/voice.mp3');

        $this->actingAs($this->memberOn(VoiceTier::HD))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there.',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice('hd')->id),
            'audio_effect' => 'nightclub',
        ])->assertStatus(422)->assertJsonValidationErrors('audio_effect');

        $this->actingAs($this->memberOn(VoiceTier::HD, effects: false))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there.',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice('hd')->id),
            'audio_effect' => 'echo',
        ])->assertOk();
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertNull(($generation->settings_snapshot ?? [])['audio_effect'] ?? null);
        $this->assertSame('audio/voice.mp3', $generation->audio_path);
    }

    public function test_a_standard_plan_with_sound_environments_switched_on_gets_them(): void
    {
        $this->requireFfmpeg();
        Storage::fake('public');
        $this->tone('audio/voice.mp3', 2);
        $this->fakeSpeech('audio/voice.mp3');

        $this->actingAs($this->memberOn(VoiceTier::STANDARD))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hello there.',
            'target_language' => 'en',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->voice('standard')->id),
            'audio_effect' => 'phone',
        ])->assertOk();

        $this->assertStringStartsWith('audio/effects/voice-phone-', AudioGeneration::query()->latest('id')->firstOrFail()->audio_path);
    }

    public function test_the_premium_dashboard_offers_the_effects_when_the_server_can_add_them(): void
    {
        $this->requireFfmpeg();
        app()->setLocale('ar');

        $html = $this->actingAs($this->memberOn(VoiceTier::HD))->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-vs-pick="audio_effect"', $html);
        $this->assertStringContainsString('<option value="">بدون مؤثر (صوت نقي)</option>', $html);
        $this->assertStringContainsString('<option value="hall">قاعة كبيرة</option>', $html);
        $this->assertSame(1, preg_match('/<script type="application\/json" data-vs-meta>(.*?)<\/script>/s', $html, $m));
        $meta = json_decode($m[1], true);
        $this->assertSame('g-hall', $meta['audio_effect']['hall']['i']);
        $this->assertSame('أماكن', $meta['audio_effectGroups']['space']);
    }

    private function requireFfmpeg(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
    }

    private function tone(string $path, int $seconds): void
    {
        $disk = Storage::disk('public');
        @mkdir(dirname($disk->path($path)), 0775, true);
        (new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration='.$seconds, '-ar', '24000', '-codec:a', 'libmp3lame', $disk->path($path)]))->mustRun();
    }

    private function seconds(string $path): float
    {
        $ffprobe = dirname((string) AudioFormats::ffmpegPath()).'/ffprobe';
        $process = new Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', Storage::disk('public')->path($path)]);
        $process->mustRun();

        return (float) trim($process->getOutput());
    }

    private function fakeSpeech(string $path): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn([
            'audio_path' => $path, 'duration_seconds' => 2, 'provider' => 'gemini', 'model' => 'premium',
            'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
        ]);
        $this->app->instance(TtsService::class, $tts);
    }

    private function memberOn(string $tier, bool $effects = true): User
    {
        $plan = Plan::factory()->create(['voice_tier' => $tier, 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0, 'sound_effects_enabled' => $effects]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(string $tier): Voice
    {
        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-test-'.$tier], [
            'language' => 'en', 'gender' => 'female', 'tier' => $tier, 'display_name' => 'Kore '.$tier,
            'provider' => $tier === 'hd' ? VoiceCatalog::preferredProviderForTier('hd') : 'google_cloud',
            'provider_voice_id' => 'en-US-test-'.$tier, 'is_active' => true,
        ]);
    }
}
