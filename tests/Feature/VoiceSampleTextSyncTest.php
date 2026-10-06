<?php

namespace Tests\Feature;

use App\Models\VoiceSample;
use App\Services\Tts\GeminiKeyManager;
use App\Support\NewDesignSamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceSampleTextSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAvailableKey')->andReturn(['key' => 'k', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]);
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_a_replaced_audio_gets_its_spoken_text_and_close_ones_are_kept(): void
    {
        $wrong = $this->sample('en', 'en-US', 'Say goodbye to scattered workflows. FlowSync brings your team\'s tasks together.', 'wrong.mp3');
        $close = $this->sample('en', 'en-GB', 'On October twelfth, a neighbor saw a light in the attic.', 'close.mp3');
        $arabic = $this->sample('ar', 'ar-EG', 'نص قديم لا علاقة له بالصوت أبداً.', 'arabic.mp3', 'نص قديم لا علاقة له بالصوت أبداً.');
        $this->fakeTranscripts([
            'wrong.mp3' => 'Welcome. Turn your words into natural voice in seconds.',
            'close.mp3' => 'On October 12th, a neighbor saw a light in the attic.',
            'arabic.mp3' => 'أهلاً بيك في إسمع، حوّل كلامك لصوت طبيعي.',
        ]);

        $this->artisan('voice-samples:sync-text')
            ->expectsOutputToContain('Matching: 1, not matching: 2, fixed: 0')
            ->assertSuccessful();
        $this->assertStringContainsString('FlowSync', $wrong->fresh()->sample_text, 'a check alone saves nothing');

        $this->artisan('voice-samples:sync-text', ['--fix' => true])
            ->expectsOutputToContain('fixed: 2')
            ->assertSuccessful();

        $this->assertSame('Welcome. Turn your words into natural voice in seconds.', $wrong->fresh()->sample_text);
        $this->assertStringContainsString('FlowSync', $wrong->fresh()->metadata['text_before_sync']);
        $this->assertSame('On October twelfth, a neighbor saw a light in the attic.', $close->fresh()->sample_text);
        $this->assertSame('أهلاً بيك في إسمع، حوّل كلامك لصوت طبيعي.', $arabic->fresh()->sample_text_ar);
        $this->assertSame('أهلاً بيك في إسمع، حوّل كلامك لصوت طبيعي.', $arabic->fresh()->sample_text);
    }

    public function test_sign_in_samples_show_the_fixed_text_straight_away(): void
    {
        $sample = $this->sample('en', 'en-US', 'Old FlowSync script.', 'wrong.mp3');
        $this->assertSame('Old FlowSync script.', NewDesignSamples::forLocale('en', 5)[0]['text']);

        $this->fakeTranscripts(['wrong.mp3' => 'Welcome. Turn your words into natural voice in seconds.']);
        $this->artisan('voice-samples:sync-text', ['--fix' => true])->assertSuccessful();

        $this->assertSame('Welcome. Turn your words into natural voice in seconds.', NewDesignSamples::forLocale('en', 5)[0]['text']);
        $this->assertSame($sample->id, $sample->fresh()->id);
    }

    public function test_missing_audio_is_skipped(): void
    {
        $sample = VoiceSample::create([
            'title' => 'x', 'category' => 'hero', 'language' => 'en', 'dialect' => 'en-US',
            'sample_text' => 'Hello.', 'audio_path' => 'samples/none.mp3', 'is_active' => true,
        ]);
        Http::fake();

        $this->artisan('voice-samples:sync-text', ['--fix' => true])
            ->expectsOutputToContain('audio file missing')
            ->assertSuccessful();
        $this->assertSame('Hello.', $sample->fresh()->sample_text);
        Http::assertNothingSent();
    }

    public function test_sign_in_wave_has_a_smooth_fill_layer(): void
    {
        $this->sample('en', 'en-US', 'Hello there.', 'a.mp3');

        $html = $this->getLocalizedRoute('login')->assertOk()->getContent();

        $this->assertStringContainsString('auth-wave-fill', $html);
        $this->assertSame(2, substr_count($html, 'class="auth-wave-bars'));
        $this->assertStringContainsString("setProperty('--nt-progress'", $html);
    }

    public function test_sign_in_player_has_voice_tabs_and_a_word_by_word_script(): void
    {
        $this->sample('en', 'en-US', 'Welcome. Turn your words into natural voice.', 'a.mp3');
        $this->sample('en', 'en-GB', 'For over a century, Ellsworth has curated timeless elegance.', 'b.mp3');

        $html = $this->getLocalizedRoute('login')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="auth-tab"'));
        $this->assertStringContainsString('data-nt-words', $html);
        $this->assertStringContainsString('Welcome. Turn your words into natural voice.', $html);
        $this->assertStringContainsString('dir="ltr" data-nt-time', $html);
        $this->assertStringContainsString('function wordify', $html);
    }

    private function sample(string $language, string $dialect, string $text, string $file, ?string $textAr = null): VoiceSample
    {
        Storage::disk('public')->put('samples/'.$file, 'ID3'.$file);

        return VoiceSample::create([
            'title' => $file, 'category' => 'hero', 'language' => $language, 'dialect' => $dialect,
            'sample_text' => $text, 'sample_text_ar' => $textAr, 'audio_path' => 'samples/'.$file, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $byFile
     */
    private function fakeTranscripts(array $byFile): void
    {
        Http::fake(function ($request) use ($byFile) {
            $audio = base64_decode($request->data()['contents'][0]['parts'][0]['inline_data']['data'] ?? '');

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => $byFile[substr($audio, 3)] ?? '']]]]]]);
        });
    }
}
