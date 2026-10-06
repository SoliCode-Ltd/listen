<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\User;
use App\Services\TranslationService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClonedVoiceDialectTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_speaks_a_cloned_voice_in_the_chosen_dialect(): void
    {
        Storage::fake('public');
        config([
            'services.gemini_tts.api_key' => 'test-key',
            'services.gemini_tts.voice_retry_pause' => false,
        ]);

        $user = User::factory()->create(['email' => 'solicode.co.uk@gmail.com']);
        $voice = ClonedVoice::factory()->ready()->forUser($user)->create([
            'provider_voice_key' => 'voice_walled',
            'language_code' => 'ar-XA',
        ]);
        $generation = AudioGeneration::create([
            'user_id' => $user->id,
            'input_text' => 'إزيك',
            'source_language' => 'ar',
            'target_language' => 'ar',
            'voice_tier' => 'hd',
            'voice_name' => 'cloned_'.$voice->id,
            'dialect' => 'ar-SA',
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
            'translate' => false,
            'status' => 'processing',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/models/gemini-3.5-flash:generateContent')) {
                $prompt = (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');

                return str_contains($prompt, 'Najdi Saudi') && str_contains($prompt, 'إزيك')
                    ? Http::response([
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'وش لونك']]],
                        ]],
                    ])
                    : Http::response(['error' => ['message' => 'bad prompt']], 500);
            }

            if (str_contains($request->url(), '/interactions')) {
                return Http::response([
                    'output_audio' => ['data' => base64_encode('RIFF....WAVE')],
                ]);
            }

            return Http::response(['error' => ['message' => 'not found']], 404);
        });

        (new GenerateAudioJob($generation->id))->handle(
            app(TranslationService::class),
            app(TtsService::class),
        );

        $generation->refresh();
        $this->assertSame('done', $generation->status, (string) $generation->error_message);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/interactions')) {
                return false;
            }

            $data = $request->data();
            $content = $data['input'][0]['content'][0] ?? [];
            $style = (string) ($content['annotations'][0]['style'] ?? '');

            return ($content['text'] ?? null) === 'وش لونك'
                && str_contains($style, 'Najdi Saudi')
                && ($data['model'] ?? null) === 'gemini-3.8-flash-tts'
                && ($data['response_format']['mime_type'] ?? null) === 'audio/wav'
                && ($data['response_format']['sample_rate'] ?? null) === 24000
                && ($data['generation_config']['speech_config'][0]['voice'] ?? null) === 'voice_walled';
        });
    }

    public function test_standard_tier_does_not_rewrite_a_cloned_voice_into_another_dialect(): void
    {
        Storage::fake('public');
        config([
            'services.gemini_tts.api_key' => 'test-key',
            'services.gemini_tts.voice_retry_pause' => false,
        ]);

        $user = User::factory()->create(['email' => 'solicode.co.uk@gmail.com']);
        $voice = ClonedVoice::factory()->ready()->forUser($user)->create([
            'provider_voice_key' => 'voice_walled',
        ]);
        $generation = AudioGeneration::create([
            'user_id' => $user->id,
            'input_text' => 'إزيك',
            'source_language' => 'ar',
            'target_language' => 'ar',
            'voice_tier' => 'standard',
            'voice_name' => 'cloned_'.$voice->id,
            'dialect' => 'ar-SA',
            'translate' => false,
            'status' => 'processing',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'output_audio' => ['data' => base64_encode('RIFF....WAVE')],
            ]),
        ]);

        (new GenerateAudioJob($generation->id))->handle(
            app(TranslationService::class),
            app(TtsService::class),
        );

        $generation->refresh();
        $this->assertSame('done', $generation->status, (string) $generation->error_message);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'gemini-3.5-flash'));
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/interactions')) {
                return false;
            }

            $content = $request->data()['input'][0]['content'][0] ?? [];

            return ($content['text'] ?? null) === 'إزيك'
                && ! isset($content['annotations']);
        });
    }

    /**
     * @param  callable(string $key): \GuzzleHttp\Promise\PromiseInterface  $speak
     */
    private function clonedGeneration(array $metadata, callable $speak): AudioGeneration
    {
        Storage::fake('public');
        config(['services.gemini_tts.api_key' => '', 'services.gemini_tts.voice_retry_pause' => false]);
        $manager = \Mockery::mock(\App\Services\Tts\GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([
            ['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1],
            ['key' => 'k2', 'name' => 'GEMINI_TTS_API_KEY_2', 'index' => 2],
        ]);
        $manager->shouldReceive('getAvailableKey')->andReturn(['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]);
        $manager->shouldIgnoreMissing();
        $this->app->instance(\App\Services\Tts\GeminiKeyManager::class, $manager);

        $user = User::query()->firstOrCreate(['email' => 'solicode.co.uk@gmail.com'], User::factory()->raw(['email' => 'solicode.co.uk@gmail.com']));
        $voice = ClonedVoice::factory()->ready()->forUser($user)->create([
            'provider_voice_key' => 'voice_x', 'language_code' => 'ar-XA', 'metadata' => $metadata,
        ]);
        $generation = AudioGeneration::create([
            'user_id' => $user->id, 'input_text' => 'مرحبا', 'source_language' => 'ar', 'target_language' => 'ar',
            'voice_tier' => 'hd', 'voice_name' => 'cloned_'.$voice->id, 'performance_style' => 'neutral',
            'emotion' => 'neutral', 'translate' => false, 'status' => 'processing',
        ]);
        Http::fake(fn ($request) => str_contains($request->url(), '/interactions') || str_contains($request->url(), 'tts:generateContent')
            ? $speak($request->header('x-goog-api-key')[0] ?? '')
            : Http::response(['error' => ['message' => 'not found']], 404));

        try {
            (new GenerateAudioJob($generation->id))->handle(app(TranslationService::class), app(TtsService::class));
        } catch (\Throwable) {
            // The job records the failure on the generation.
        }

        return $generation->refresh();
    }

    public function test_cloned_voice_uses_the_key_that_made_it_first(): void
    {
        $generation = $this->clonedGeneration(['key_name' => 'GEMINI_TTS_API_KEY_2'], fn (string $key) => $key === 'k2'
            ? Http::response(['output_audio' => ['data' => base64_encode('RIFF....WAVE')]])
            : Http::response(['error' => ['message' => 'Voice not found']], 404));

        $this->assertSame('done', $generation->status, (string) $generation->error_message);
        $first = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($r) => str_contains($r->url(), '/interactions'));
        $this->assertSame('k2', $first->header('x-goog-api-key')[0], 'the voice\'s own key goes first');
    }

    public function test_cloned_voice_moves_to_the_next_key_when_one_cannot_serve_it(): void
    {
        foreach ([404 => 'Voice not found', 429 => 'Rate limit exceeded'] as $status => $message) {
            $generation = $this->clonedGeneration([], fn (string $key) => $key === 'k2'
                ? Http::response(['output_audio' => ['data' => base64_encode('RIFF....WAVE')]])
                : Http::response(['error' => ['message' => $message]], $status));

            $this->assertSame('done', $generation->status, $status.': '.$generation->error_message);
        }
    }

    public function test_cloned_voice_out_of_daily_quota_says_so(): void
    {
        $generation = $this->clonedGeneration([], fn () => Http::response(['error' => ['code' => 429, 'message' => 'Rate limit exceeded for model gemini-3.8-flash-tts (limit: 100 requests per day on Tier 1).', 'status' => 'RESOURCE_EXHAUSTED']], 429));

        $this->assertSame('failed', $generation->status);
        $this->assertSame(__('ui.backend.cloned_voice_daily_limit'), $generation->error_message);
    }
}
