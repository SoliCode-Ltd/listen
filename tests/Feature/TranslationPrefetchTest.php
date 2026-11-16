<?php

namespace Tests\Feature;

use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\TranscriptEditor;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A transcript's paragraphs are sent to the quick translator together, not one
 * after another: each good answer is kept where the paragraph-by-paragraph
 * translation looks first, so it then needs no request of its own. An answer
 * left partly in the original script is not kept (the careful path does it).
 */
class TranslationPrefetchTest extends TestCase
{
    use RefreshDatabase;

    /** Where the quick translator does not answer (as on the servers), all the paragraphs go to the text model in one request, never one by one. */
    public function test_without_the_quick_translator_the_paragraphs_go_to_the_model_together(): void
    {
        Cache::flush();
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
        $asked = 0;
        Http::fake(function ($request) use (&$asked) {
            if (str_contains($request->url(), 'translate.googleapis.com')) {
                return Http::response('', 403);
            }
            $asked++;

            $answer = json_encode(['paragraphs' => ['Hello Khadija, what happened?', 'Almost everything is ready.', 'Shall I record it?']]);

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => $answer]]], 'finishReason' => 'STOP']]]);
        });
        $user = User::factory()->create();
        $t = Transcription::query()->create(['user_id' => $user->id, 'title' => 'Call', 'source' => 'upload', 'status' => Transcription::READY, 'seconds' => 12, 'charged_seconds' => 12,
            'language_requested' => 'auto', 'language' => 'ar', 'finished_at' => now(),
            'paragraphs' => [['speaker' => 'A', 'text' => 'مرحبا خديجة، شو صار؟', 'start' => 0.0, 'end' => 2.0], ['speaker' => 'B', 'text' => 'كل شي جاهز تقريباً.', 'start' => 2.5, 'end' => 4.0], ['speaker' => 'A', 'text' => 'أسجّله أنا؟', 'start' => 4.5, 'end' => 6.0]],
            'segments' => [['speaker' => 'A', 'text' => 'مرحبا خديجة، شو صار؟', 'paragraph' => 0, 'start' => 0.0, 'end' => 2.0]]]);

        $out = app(TranscriptEditor::class)->translate($t, 'en');

        $this->assertSame(['Hello Khadija, what happened?', 'Almost everything is ready.', 'Shall I record it?'], array_column($out['paragraphs'], 'text'));
        $this->assertSame(1, $asked, 'one request to the model for all three');
    }

    public function test_paragraphs_are_translated_together_and_then_read_from_what_was_kept(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $out = match ($q['q'] ?? '') {
                'مرحبا خديجة' => 'Hello Khadija',
                'كل شي جاهز' => 'Everything is ready',
                default => 'Half translated يا شباب',
            };

            return Http::response([[[$out, $q['q'] ?? '', null, null, 10]], null, 'ar']);
        });
        $translator = app(TranslationService::class);

        $translator->prefetch(['مرحبا خديجة', 'كل شي جاهز', 'يلا يا شباب', ''], 'ar', 'en');

        Http::assertSentCount(3);
        $this->assertSame('Hello Khadija', $translator->maybeTranslate('مرحبا خديجة', 'ar', 'en', true));
        $this->assertSame('Everything is ready', $translator->maybeTranslate('كل شي جاهز', 'ar', 'en', true));
        Http::assertSentCount(3);
        $this->assertNull(Cache::get('listen:tr:v4:'.sha1('ar|en|يلا يا شباب')), 'an answer still holding Arabic is not kept');

        // Asked again: nothing is sent for what is already kept.
        $translator->prefetch(['مرحبا خديجة', 'كل شي جاهز'], 'ar', 'en');
        Http::assertSentCount(3);
    }
}
