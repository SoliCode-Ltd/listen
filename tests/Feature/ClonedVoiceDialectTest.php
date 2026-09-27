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
}
