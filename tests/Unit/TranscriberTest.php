<?php

namespace Tests\Unit;

use App\Console\Commands\TranscribeMeasureCommand;
use App\Services\Transcription\Transcriber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
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
        $this->assertSame(['text' => "أهلاً بيك\n\n42", 'segments' => [], 'language' => 'ar', 'voices' => []], $plain);

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
        $plain = Transcriber::prompt(['language' => 'ar']);
        $this->assertStringContainsString('The audio is in Arabic', $plain);
        $this->assertStringContainsString('never rewrite it into Modern Standard Arabic', $plain);
        $this->assertStringContainsString('أوي (never قوي)', $plain);
        $this->assertStringContainsString('"paragraphs"', $plain);
        $this->assertStringNotContainsString('"start"', $plain);
        $this->assertStringNotContainsString('Gulf: وايد', Transcriber::prompt(['language' => 'fr']), 'the Arabic guide only where Arabic may be spoken');

        $speakers = Transcriber::prompt(['language' => 'auto', 'timings' => true, 'speakers' => true, 'part' => true,
            'known' => [['id' => 'A', 'voice' => 'deep male, slow'], ['id' => 'B', 'voice' => 'bright female']]]);
        $this->assertStringContainsString('Find the language spoken', $speakers);
        $this->assertStringContainsString('"speaker": "A"', $speakers);
        $this->assertStringContainsString('one part cut out of a longer recording', $speakers);
        $this->assertStringContainsString('A: deep male, slow; B: bright female', $speakers);
        $this->assertStringContainsString('One person reading or talking alone is A all through', $speakers);
        $this->assertStringContainsString('first three words', Transcriber::prompt(['language' => 'ar', 'timings' => true, 'careful' => true]));

        $egyptian = Transcriber::prompt(['language' => 'ar', 'dialect' => 'ar-EG', 'timings' => true]);
        $this->assertStringContainsString('The speaker speaks', $egyptian);
        $this->assertStringContainsString((string) ArabicDialects::markers('ar-EG'), $egyptian);
        $this->assertStringContainsString('Scottish', Transcriber::prompt(['language' => 'en', 'dialect' => 'en-GB-SC']));
        $this->assertStringContainsString('Here is a first transcript', Transcriber::prompt(['language' => 'ar', 'draft' => '{"segments":[]}']));
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

    public function test_times_written_as_a_clock_are_read_as_seconds(): void
    {
        $this->assertSame(75.4, Transcriber::seconds('1:15.4'));
        $this->assertSame(3675.0, Transcriber::seconds('1:01:15'));
        $this->assertSame(12.5, Transcriber::seconds('12.5'));
        $this->assertNull(Transcriber::seconds('soon'));
        $read = Transcriber::read([['start' => '0:01.0', 'end' => '0:03,5', 'text' => 'Hi']], true, false);
        $this->assertSame([['start' => 1.0, 'end' => 3.5, 'speaker' => null, 'text' => 'Hi']], $read['segments']);
        $this->assertSame('Whole text', Transcriber::read(['transcript' => 'Whole text'], false, false)['text']);
    }

    public function test_far_too_few_words_for_the_speech_is_noticed(): void
    {
        $this->assertTrue(Transcriber::tooShort(4, 280.0));
        $this->assertFalse(Transcriber::tooShort(500, 280.0));
        $this->assertFalse(Transcriber::tooShort(2, 10.0), 'a short part may hold few words');
        $this->assertSame(4, Transcriber::wordCount('Hello there, عايزة أقولك'));
        $this->assertSame(85.0, Transcriber::speechSeconds([[10.0, 20.0], [95.0, 110.0]], 0.0, 100.0));
    }

    public function test_a_short_or_unreadable_answer_is_asked_for_again_and_the_fuller_one_kept(): void
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/transcriber-retry-'.getmypid().'.wav';
        // 40 seconds of steady tone: no pauses, so all of it counts as speech.
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=40', $file]))->run();
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
        $answer = fn (string $json) => Http::response(['candidates' => [['content' => ['parts' => [['text' => $json]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10]]);
        $full = implode(' ', array_fill(0, 60, 'word'));
        Http::fake(['*' => Http::sequence()
            ->pushResponse($answer('not json at all'))
            ->pushResponse($answer('{"language":"en","paragraphs":["which she was capable."]}'))
            // The second recording: short first, whole when asked again.
            ->pushResponse($answer('{"language":"en","paragraphs":["which she was capable."]}'))
            ->pushResponse($answer('{"language":"en","paragraphs":["'.$full.'"]}'))]);

        $short = app(Transcriber::class)->transcribe($file, ['language' => 'en']);
        $this->assertSame('which she was capable.', $short['text'], 'two tries at most: the unreadable one, then the short one');
        $this->assertSame(2, $short['requests']);
        $this->assertStringContainsString('no answer to read', $short['notes'][0]);
        $this->assertStringContainsString('still only 4 words', $short['notes'][1]);

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'en']);
        } finally {
            @unlink($file);
        }

        $this->assertSame($full, $out['text']);
        $this->assertSame(2, $out['requests']);
        $this->assertStringContainsString('only 4 words', $out['notes'][0]);
        Http::assertSent(fn ($request) => data_get($request->data(), 'generationConfig.responseSchema.properties.paragraphs.type') === 'ARRAY'
            && str_contains((string) data_get($request->data(), 'contents.0.parts.1.text'), 'not only its opening'));
    }

    public function test_speech_no_segment_covers_is_found(): void
    {
        // Speech from 0 to 60 s with pauses at 20-21 and 40-41; segments cover 0-19 and 41-60.
        $segments = [['start' => 0.2, 'end' => 19.6], ['start' => 41.3, 'end' => 59.8]];
        $this->assertSame([[21.0, 40.0]], Transcriber::unheard($segments, [[20.0, 21.0], [40.0, 41.0]], 60.0));
        $this->assertSame([], Transcriber::unheard([['start' => 0.0, 'end' => 60.0]], [], 60.0));
        $this->assertSame([], Transcriber::unheard([['start' => 0.0, 'end' => 28.6], ['start' => 31.0, 'end' => 60.0]], [], 60.0),
            'a gap shorter than 2.5 s is a moment off, not a lost sentence');
    }

    public function test_a_stretch_left_out_of_a_timed_transcript_is_heard_again(): void
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/transcriber-gap-'.getmypid().'.wav';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=30', $file]))->run();
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
        $answer = fn (array $data) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10]]);
        $words = fn (int $n) => implode(' ', array_fill(0, $n, 'كلام'));
        Http::fake(['*' => Http::sequence()
            // The whole recording: the model leaves out 10-20 s.
            ->pushResponse($answer(['language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'deep male']], 'segments' => [
                ['start' => 0.0, 'end' => 10.0, 'speaker' => 'A', 'text' => $words(25)],
                ['start' => 20.0, 'end' => 30.0, 'speaker' => 'A', 'text' => $words(25)],
            ]]))
            // 10.5-19.5 s heard again (cut from 9.9 s on), with the voices known.
            ->pushResponse($answer(['language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'deep male']], 'segments' => [
                ['start' => 1.5, 'end' => 10.0, 'speaker' => 'A', 'text' => 'الجملة اللي راحت'],
            ]]))]);

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'ar', 'speakers' => true]);
        } finally {
            @unlink($file);
        }

        $this->assertSame(['A', 'A', 'A'], array_column($out['segments'], 'speaker'));
        $this->assertSame('الجملة اللي راحت', $out['segments'][1]['text']);
        $this->assertEqualsWithDelta(11.4, $out['segments'][1]['start'], 0.05);
        $this->assertSame(2, $out['requests']);
        $this->assertStringContainsString('had no words: heard again, 1 segment(s) found', implode(' ', $out['notes']));
        $this->assertSame([['id' => 'A', 'voice' => 'deep male']], $out['voices']);
        Http::assertSent(fn ($request) => str_contains((string) data_get($request->data(), 'contents.0.parts.1.text'), 'Voices heard earlier in this recording: A: deep male'));
    }

    public function test_a_check_that_drops_words_is_not_kept(): void
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/transcriber-review-'.getmypid().'.wav';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=8', $file]))->run();
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
        $answer = fn (string $text) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['language' => 'ar', 'paragraphs' => [$text]], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10]]);
        Http::fake(['*' => Http::sequence()
            ->pushResponse($answer('بص بقى أنا عايزة أقولك حاجة مهمة قوي'))
            ->pushResponse($answer('بص بقى أنا عايزة أقولك حاجة مهمة أوي'))
            ->pushResponse($answer('بص بقى أنا عايزة أقولك حاجة مهمة قوي'))
            ->pushResponse($answer('بص'))]);

        try {
            $fixed = app(Transcriber::class)->transcribe($file, ['language' => 'ar', 'review' => true]);
            $kept = app(Transcriber::class)->transcribe($file, ['language' => 'ar', 'review' => true]);
        } finally {
            @unlink($file);
        }

        $this->assertSame('بص بقى أنا عايزة أقولك حاجة مهمة أوي', $fixed['text']);
        $this->assertSame(2, $fixed['requests']);
        $this->assertSame('بص بقى أنا عايزة أقولك حاجة مهمة قوي', $kept['text']);
        $this->assertStringContainsString('the first answer kept', implode(' ', $kept['notes']));
    }

    public function test_every_dialect_is_measured_in_a_file_of_its_own_country_with_each_clip_scored(): void
    {
        $byLabel = [];
        foreach (TranscribeMeasureCommand::dialectRecordings() as $r) {
            $byLabel[$r['label']] = $r;
        }
        foreach (['dia-ar-EG', 'dia-ar-SA', 'dia-ar-MA', 'dia-ar-IQ', 'dia-ar-LB', 'dia-ar-XA', 'dia-en-GB', 'dia-en-world'] as $label) {
            $this->assertArrayHasKey($label, $byLabel);
            $this->assertCount(count($byLabel[$label]['parts']), $byLabel[$label]['clips']);
        }
        $this->assertSame('ar-EG', $byLabel['dia-ar-EG']['dialect']);
        $this->assertSame('', $byLabel['dia-en-world']['dialect']);
        $this->assertGreaterThanOrEqual(140, count(array_unique(array_merge(...array_values(array_map(fn ($r) => array_column($r['clips'], 'dialect'),
            array_filter($byLabel, fn ($r) => $r['language'] === 'ar')))))), 'about every Arabic variety the site has');
        $languages = array_column(TranscribeMeasureCommand::languageRecordings(), 'language');
        $this->assertContains('tr', $languages);
        $this->assertNotContains('ar', $languages);

        $r = ['clips' => [['slug' => 'one', 'dialect' => 'ar-EG', 'said' => 'أنا عايزة أقولك'], ['slug' => 'two', 'dialect' => 'ar-EG-SA', 'said' => 'يا ولدي اسمع']]];
        // The times are wrong (all in the first clip's stretch): the words still find their clip.
        $scores = TranscribeMeasureCommand::clipScores($r, [
            ['start' => 0.1, 'end' => 1.0, 'text' => 'أنا عايزة أقولك'],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'يا ولدي'],
        ]);
        $this->assertSame([0.0, 0.3333], array_column($scores, 'wer'));
        $this->assertSame('يا ولدي', $scores[1]['heard']);

        $this->assertSame(['language' => 'auto'], TranscribeMeasureCommand::variant('auto', ['language' => 'ar', 'dialect' => 'ar-EG']));
        $this->assertSame(['language' => 'ar', 'dialect' => 'ar-EG', 'review' => true, 'thinking' => 'high'],
            TranscribeMeasureCommand::variant('lang+dialect+review+think', ['language' => 'ar', 'dialect' => 'ar-EG']));
    }

    public function test_drifted_times_are_drawn_back_in_and_words_already_written_are_not_added_again(): void
    {
        // A 90 s part whose times run on to 100 s: scaled back, nothing dropped.
        $fitted = Transcriber::fitTimes([['start' => 10.0, 'end' => 20.0, 'text' => 'a'], ['start' => 90.0, 'end' => 100.0, 'text' => 'b']], 90.0);
        $this->assertCount(2, $fitted);
        $this->assertSame(90.0, $fitted[1]['end']);
        $this->assertSame(81.0, $fitted[1]['start']);
        // Written as a clock past the first minute (1:15 as 115): read as 75 s.
        $this->assertSame(75.0, Transcriber::fitTimes([['start' => 115.0, 'end' => 118.0, 'text' => 'c']], 90.0)[0]['start']);

        $segments = [['start' => 40.0, 'end' => 48.0, 'text' => 'هلا والله يا ولد، وش علومك؟ هقيت إنك نسيت البر']];
        $this->assertTrue(Transcriber::alreadyWritten($segments, 'هلا والله يا ولد وش علومك', 62.0));
        $this->assertFalse(Transcriber::alreadyWritten($segments, 'تعال الليلة نشب النار ونسولف تحت النجوم', 62.0));
        $this->assertFalse(Transcriber::alreadyWritten($segments, 'هلا والله يا ولد وش علومك', 200.0), 'only near where it was heard');
    }

    public function test_a_timed_transcript_is_heard_in_short_parts(): void
    {
        $plan = Transcriber::chunkPlan([], 200.0, Transcriber::MAX_TIMED_PART, 45.0);
        $this->assertCount(3, $plan);
        $this->assertStringContainsString('ف joined to the next word', Transcriber::dialectGuide());
        $this->assertStringContainsString('never in Latin letters', Transcriber::dialectGuide());
    }
}
