<?php

namespace Tests\Unit;

use App\Console\Commands\TranscribeMeasureCommand;
use App\Services\Transcription\Transcriber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioFormats;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TranscriberTest extends TestCase
{
    public function test_a_short_recording_is_heard_in_one_part(): void
    {
        $plan = Transcriber::chunkPlan([[10.0, 11.0]], 120.0);

        $this->assertSame([['from' => 0.0, 'to' => 120.0, 'keep_from' => 0.0, 'keep_to' => 120.0, 'clean' => true]], $plan);
    }

    public function test_a_long_recording_is_cut_in_its_longest_late_pause_and_parts_overlap(): void
    {
        // 11 minutes; pauses at 100 s (too early), 250 s (short) and 280 s (long), and none later in the second part.
        $plan = Transcriber::chunkPlan([[99.5, 100.5], [249.8, 250.2], [279.0, 281.0]], 660.0);

        $this->assertCount(3, $plan);
        $this->assertSame(280.0, $plan[0]['keep_to']);
        $this->assertSame(282.0, $plan[0]['to'], 'the first part hears 2 s past its cut');
        $this->assertSame(278.0, $plan[1]['from'], 'the second part hears 2 s before it');
        $this->assertTrue($plan[1]['clean']);
        // No pause in the second part: cut hard at 300 s after the first cut.
        $this->assertSame(580.0, $plan[1]['keep_to']);
        $this->assertFalse($plan[2]['clean']);
        $this->assertSame(660.0, $plan[2]['to']);
        foreach ($plan as $part) {
            $this->assertLessThanOrEqual(Transcriber::MAX_PART + 2 * Transcriber::OVERLAP, $part['to'] - $part['from']);
        }
    }

    public function test_segments_heard_by_two_parts_are_kept_once_on_the_recordings_clock(): void
    {
        $pieces = [
            ['from' => 0.0, 'keep_from' => 0.0, 'keep_to' => 280.0, 'segments' => [
                ['start' => 270.0, 'end' => 278.0, 'speaker' => 'A', 'text' => 'Last whole sentence.'],
                ['start' => 279.0, 'end' => 282.0, 'speaker' => 'A', 'text' => 'Half a sen'],
            ]],
            ['from' => 278.0, 'keep_from' => 280.0, 'keep_to' => 500.0, 'segments' => [
                ['start' => 0.0, 'end' => 0.5, 'speaker' => 'A', 'text' => 'sentence.'],
                ['start' => 1.0, 'end' => 6.0, 'speaker' => 'B', 'text' => 'Half a sentence, whole.'],
            ]],
        ];

        $all = Transcriber::stitch($pieces);

        $this->assertSame(['Last whole sentence.', 'Half a sentence, whole.'], array_column($all, 'text'));
        $this->assertSame(279.0, $all[1]['start']);
        $this->assertSame("A: Last whole sentence.\n\nB: Half a sentence, whole.", Transcriber::textOf($all, true));
    }

    public function test_plain_text_parts_drop_the_words_both_heard(): void
    {
        $text = Transcriber::stitchText([
            ['text' => 'The clock struck nine and the old man looked up'],
            ['text' => 'and the old man looked up from his bench. Then he smiled.'],
        ]);

        $this->assertSame("The clock struck nine and the old man looked up\n\nfrom his bench. Then he smiled.", $text);
        $this->assertSame('كتير النهارده', Transcriber::withoutRepeat('أنا عايزة أقولك حاجة مهمة أوي', 'حاجة مهمة أوي كتير النهارده'));
        $this->assertSame('nothing shared here', Transcriber::withoutRepeat('one two three', 'nothing shared here'));
    }

    public function test_the_answer_is_read_safely(): void
    {
        $plain = Transcriber::read(['language' => 'AR-eg', 'paragraphs' => ['أهلاً بيك', '', 42, ['x']]], false, false);
        $this->assertSame(['text' => "أهلاً بيك\n\n42", 'segments' => [], 'language' => 'ar'], $plain);

        $timed = Transcriber::read(['language' => 'fr', 'segments' => [
            ['start' => 5, 'end' => 3, 'text' => 'Bonjour', 'speaker' => 'B'],
            ['start' => 'x', 'text' => 'skipped'],
            ['start' => 0, 'end' => 2, 'text' => '  '],
            ['start' => 1, 'end' => 2, 'text' => 'Salut'],
        ]], true, true);
        $this->assertSame([
            ['start' => 1.0, 'end' => 2.0, 'speaker' => 'A', 'text' => 'Salut'],
            ['start' => 5.0, 'end' => 5.0, 'speaker' => 'B', 'text' => 'Bonjour'],
        ], $timed['segments']);
    }

    public function test_the_prompt_keeps_the_dialect_and_asks_only_for_what_the_mode_needs(): void
    {
        $plain = Transcriber::prompt('ar', false, false, false);
        $this->assertStringContainsString('The audio is in Arabic', $plain);
        $this->assertStringContainsString('never rewrite it into Modern Standard Arabic', $plain);
        $this->assertStringContainsString('"paragraphs"', $plain);
        $this->assertStringNotContainsString('"start"', $plain);

        $speakers = Transcriber::prompt('auto', true, true, true);
        $this->assertStringContainsString('Find the language spoken', $speakers);
        $this->assertStringContainsString('"speaker": "A"', $speakers);
        $this->assertStringContainsString('one part cut out of a longer recording', $speakers);
        $this->assertStringContainsString('first three words', Transcriber::prompt('ar', true, false, false, true));
        $this->assertSame('French', Transcriber::languageName('fr'));
        $this->assertSame('', Transcriber::languageName('auto'));
    }

    public function test_cost_uses_the_dubbing_prices_for_audio_text_and_output(): void
    {
        $usage = ['input_tokens' => 2500, 'audio_tokens' => 1920, 'output_tokens' => 400, 'thinking_tokens' => 100];

        $this->assertEqualsWithDelta((1920 * 1.0 + 580 * 0.5 + 500 * 3.0) / 1e6,
            Transcriber::cost($usage, ['audio_in' => 1.0, 'text_in' => 0.5, 'text_out' => 3.0]), 1e-12);
    }

    public function test_pauses_are_read_from_ffmpegs_silence_log(): void
    {
        $log = "[silencedetect @ 0x1] silence_start: -0.01\n[silencedetect @ 0x1] silence_end: 0.8 | silence_duration: 0.81\n"
            ."[silencedetect @ 0x1] silence_start: 12.5\n[silencedetect @ 0x1] silence_end: 13.25 | silence_duration: 0.75\n[silencedetect @ 0x1] silence_start: 59.0\n";

        $this->assertSame([[0.0, 0.8], [12.5, 13.25]], Transcriber::readPauses($log));
    }

    public function test_a_recording_is_written_out_from_the_models_answer(): void
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/transcriber-test-'.getmypid().'.wav';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=4', $file]))->run();
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"language":"en","segments":[{"start":0.2,"end":3.1,"speaker":"A","text":"Hello there."}]}']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 700, 'candidatesTokenCount' => 40, 'thoughtsTokenCount' => 12,
                'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 128], ['modality' => 'TEXT', 'tokenCount' => 572]]],
        ])]);

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'auto', 'speakers' => true]);
        } finally {
            @unlink($file);
        }

        $this->assertSame('en', $out['language']);
        $this->assertSame(1, $out['parts']);
        $this->assertSame('A: Hello there.', $out['text']);
        $this->assertSame(['input_tokens' => 700, 'audio_tokens' => 128, 'output_tokens' => 40, 'thinking_tokens' => 12], $out['usage']);
        $this->assertEqualsWithDelta(4.0, $out['seconds'], 0.2);
        Http::assertSent(fn ($request) => str_contains($request->url(), ':generateContent')
            && data_get($request->data(), 'contents.0.parts.0.inline_data.mime_type') === 'audio/mpeg');
    }

    public function test_error_rates_treat_arabic_spellings_and_punctuation_alike(): void
    {
        $this->assertSame(['wer' => 0.0, 'cer' => 0.0, 'words' => 4], TranscribeMeasureCommand::errorRates('أنا عايزة أقولك حاجة!', 'انا عايزه أقولك، حاجة'));
        $rates = TranscribeMeasureCommand::errorRates('Daisy: the cat sat down', 'the cat sat');
        $this->assertSame(4, $rates['words']);
        $this->assertSame(0.25, $rates['wer']);
    }

    public function test_the_words_of_listens_own_test_recordings_are_known(): void
    {
        $byLabel = [];
        foreach (TranscribeMeasureCommand::recordings() as $r) {
            $byLabel[$r['label']] = $r;
        }

        foreach (['ar-eg', 'ar-sa', 'en-long', 'dialogue-en', 'dialogue-ar', 'noisy-places'] as $label) {
            $this->assertNotEmpty($byLabel[$label]['parts'], $label.' has recordings');
            $this->assertNotNull($byLabel[$label]['reference'], $label.' has its words');
        }
        $this->assertStringContainsString('بص بقى', (string) $byLabel['ar-eg']['reference']);
        $this->assertTrue($byLabel['noisy-room']['noise']);
        $this->assertNull($byLabel['fr-human']['reference']);
        $this->assertGreaterThanOrEqual(4, count(array_filter($byLabel, fn ($r) => ! in_array($r['language'], ['ar', 'en', 'auto'], true))));
    }
}
