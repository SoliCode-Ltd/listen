<?php

namespace Tests\Unit;

use App\Console\Commands\TranscribeMeasureCommand;
use App\Services\Transcription\Transcriber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
use App\Support\AudioFormats;
use GuzzleHttp\Promise\PromiseInterface;
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
        $this->assertSame(580.0, $plan[1]['keep_to'], 'no pause in the second part: cut at 300 s');
        $this->assertFalse($plan[2]['clean']);
        foreach ($plan as $part) {
            $this->assertLessThanOrEqual(Transcriber::MAX_PART + 2 * Transcriber::OVERLAP, $part['to'] - $part['from']);
        }
    }

    public function test_the_words_two_parts_both_heard_are_written_once(): void
    {
        $this->assertSame('from his bench. Then he smiled.', Transcriber::withoutRepeat('The clock struck nine and the old man looked up', 'and the old man looked up from his bench. Then he smiled.'));
        $this->assertSame('كتير النهارده', Transcriber::withoutRepeat('أنا عايزة أقولك حاجة مهمة أوي', 'حاجة مهمة أوي كتير النهارده'));
        $this->assertSame('nothing shared here', Transcriber::withoutRepeat('one two three', 'nothing shared here'));

        $next = Transcriber::withoutRepeatedStart('he looked up', [['speaker' => 'A', 'text' => 'he looked up'], ['speaker' => 'B', 'text' => 'Hello.']]);
        $this->assertSame([['speaker' => 'B', 'text' => 'Hello.']], $next, 'a paragraph heard twice whole goes');
    }

    public function test_the_answer_is_read_safely(): void
    {
        $heard = Transcriber::read(['language' => 'AR-eg', 'speakers' => [['id' => 'A', 'voice' => 'deep male'], ['id' => '', 'voice' => 'x']], 'paragraphs' => [
            ['speaker' => 'A', 'text' => 'أهلاً بيك'],
            ['speaker' => '', 'text' => 'بدون حرف'],
            ['speaker' => 'B', 'text' => '  '],
            'نص فقط',
            ['x'],
        ]]);

        $this->assertSame('ar', $heard['language']);
        $this->assertSame([['id' => 'A', 'voice' => 'deep male']], $heard['voices']);
        $this->assertSame([
            ['speaker' => 'A', 'text' => 'أهلاً بيك'],
            ['speaker' => 'A', 'text' => 'بدون حرف'],
            ['speaker' => 'A', 'text' => 'نص فقط'],
        ], $heard['paragraphs']);
        $this->assertSame([['speaker' => 'A', 'text' => 'Whole text']], Transcriber::read(['transcript' => 'Whole text'])['paragraphs']);
    }

    public function test_the_prompt_asks_for_words_and_speakers_never_times_and_keeps_the_dialect(): void
    {
        $arabic = Transcriber::prompt(['language' => 'ar']);
        $this->assertStringContainsString('The audio is in Arabic', $arabic);
        $this->assertStringContainsString('never rewrite it into Modern Standard Arabic', $arabic);
        $this->assertStringContainsString('أوي (never قوي)', $arabic);
        $this->assertStringContainsString('ف joined to the next word', $arabic);
        $this->assertStringContainsString('never in Latin letters', $arabic);
        $this->assertStringContainsString('do not write any times', $arabic);
        $this->assertStringContainsString('"paragraphs": [{"speaker": "A", "text": "..."}]', $arabic);
        $this->assertStringContainsString('One person reading or talking alone is A all through', $arabic);
        $this->assertStringNotContainsString('Gulf: وايد', Transcriber::prompt(['language' => 'fr']), 'the Arabic guide only where Arabic may be spoken');

        $later = Transcriber::prompt(['language' => 'auto', 'part' => true, 'known' => [['id' => 'A', 'voice' => 'deep male, slow'], ['id' => 'B', 'voice' => 'bright female']]]);
        $this->assertStringContainsString('Find the language spoken', $later);
        $this->assertStringContainsString('one part cut out of a longer recording', $later);
        $this->assertStringContainsString('A: deep male, slow; B: bright female', $later);
        $this->assertStringContainsString('first three words', Transcriber::prompt(['language' => 'ar', 'careful' => true]));
        $this->assertStringContainsString((string) ArabicDialects::markers('ar-EG'), Transcriber::prompt(['language' => 'ar', 'dialect' => 'ar-EG']));
        $this->assertStringContainsString('Scottish', Transcriber::prompt(['language' => 'en', 'dialect' => 'en-GB-SC']));
        $this->assertStringContainsString('Here is a first transcript', Transcriber::prompt(['language' => 'ar', 'draft' => '{"paragraphs":[]}']));
        $this->assertSame('French', Transcriber::languageName('fr'));
        $this->assertSame(['language', 'speakers', 'paragraphs'], Transcriber::schema()['required']);
    }

    public function test_paragraphs_are_cut_into_sentences_and_a_very_long_one_at_its_commas(): void
    {
        $long = implode('، ', array_fill(0, 12, 'كلام طويل من غير نقطة'));
        $sentences = Transcriber::sentences([
            ['speaker' => 'A', 'text' => 'Hello there. How are you? Fine!'],
            ['speaker' => 'B', 'text' => $long],
        ], 3);

        $this->assertSame(['Hello there.', 'How are you?', 'Fine!'], array_column(array_slice($sentences, 0, 3), 'text'));
        $this->assertSame([3, 3, 3], array_column(array_slice($sentences, 0, 3), 'paragraph'));
        $this->assertGreaterThan(1, count(array_slice($sentences, 3)));
        foreach (array_slice($sentences, 3) as $piece) {
            $this->assertSame('B', $piece['speaker']);
            $this->assertLessThanOrEqual(170, mb_strlen($piece['text']));
        }
    }

    public function test_each_sentence_is_placed_on_the_speech_and_ends_on_a_pause(): void
    {
        $speech = Transcriber::speechIn([[4.0, 4.6], [9.0, 9.8]], 0, 15);
        $this->assertSame([[0.0, 4.0], [4.6, 9.0], [9.8, 15.0]], $speech);

        // The middle sentence is long for its letters: still ends on the pause, and the next starts after it.
        $placed = Transcriber::placeOnSpeech(Transcriber::sentences([['speaker' => 'A', 'text' => 'Hello there my friend. How are you doing today my dear? I am fine thanks.']]), $speech, 0, 15);
        $this->assertSame([[0.0, 4.0], [4.6, 9.0], [9.8, 15.0]], array_map(fn ($s) => [$s['start'], $s['end']], $placed));

        // More sentences than pauses: the one pause is used, the rest shared out by letters.
        $four = Transcriber::sentences([['speaker' => 'A', 'text' => 'One two three. Four five six. Seven eight nine. Ten eleven twelve.']]);
        $placed = Transcriber::placeOnSpeech($four, Transcriber::speechIn([[6.0, 6.8]], 0, 12), 0, 12);
        $this->assertSame(6.0, $placed[1]['end']);
        $this->assertSame(6.8, $placed[2]['start']);
        $this->assertSame(12.0, $placed[3]['end']);

        // No pause at all (music under the voice): shared out by letters, in order, over the whole stretch.
        $placed = Transcriber::placeOnSpeech($four, Transcriber::speechIn([], 0, 8), 0, 8);
        $this->assertSame(0.0, $placed[0]['start']);
        $this->assertSame(8.0, $placed[3]['end']);
        for ($i = 1; $i < 4; $i++) {
            $this->assertSame($placed[$i - 1]['end'], $placed[$i]['start']);
        }
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

    public function test_far_too_few_words_for_the_speech_is_noticed(): void
    {
        $this->assertTrue(Transcriber::tooShort(4, 280.0));
        $this->assertFalse(Transcriber::tooShort(500, 280.0));
        $this->assertFalse(Transcriber::tooShort(2, 10.0), 'a short part may hold few words');
        $this->assertSame(4, Transcriber::wordCount('Hello there, عايزة أقولك'));
        $this->assertSame(85.0, Transcriber::speechSeconds([[10.0, 20.0], [95.0, 110.0]], 0.0, 100.0));
    }

    public function test_two_speakers_come_back_as_paragraphs_with_their_sentences_on_the_speech(): void
    {
        // 3 s of voice, 1 s of silence, 3 s of voice.
        $file = $this->tone('two', '3', '1');
        $this->fakeKeys();
        Http::fake(['*' => Http::sequence()->pushResponse($this->answer([
            'language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'deep male'], ['id' => 'B', 'voice' => 'bright female']],
            'paragraphs' => [['speaker' => 'A', 'text' => 'مرحبا خديجة، شو صار؟'], ['speaker' => 'B', 'text' => 'كل شي جاهز تقريباً.']],
        ]))]);

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'ar']);
        } finally {
            @unlink($file);
        }

        $this->assertSame("A: مرحبا خديجة، شو صار؟\n\nB: كل شي جاهز تقريباً.", $out['text']);
        $this->assertSame(2, $out['speakers']);
        $this->assertSame(['A', 'B'], array_column($out['segments'], 'speaker'));
        $this->assertEqualsWithDelta(0.0, $out['segments'][0]['start'], 0.1);
        $this->assertEqualsWithDelta(3.0, $out['segments'][0]['end'], 0.15);
        $this->assertEqualsWithDelta(4.0, $out['segments'][1]['start'], 0.15, 'the second speaker starts after the pause');
        $this->assertEqualsWithDelta(4.0, $out['paragraphs'][1]['start'], 0.15);
        $this->assertSame([['id' => 'A', 'voice' => 'deep male'], ['id' => 'B', 'voice' => 'bright female']], $out['voices']);
        Http::assertSent(fn ($request) => data_get($request->data(), 'generationConfig.responseSchema.properties.paragraphs.type') === 'ARRAY'
            && data_get($request->data(), 'contents.0.parts.0.inline_data.mime_type') === 'audio/mpeg');
    }

    public function test_the_language_found_in_the_first_part_is_given_for_the_rest(): void
    {
        // 320 s: two parts.
        $file = $this->tone('long', '320');
        $this->fakeKeys();
        $words = implode(' ', array_fill(0, 400, 'كلام'));
        Http::fake(['*' => Http::sequence()
            ->pushResponse($this->answer(['language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'calm male']], 'paragraphs' => [['speaker' => 'A', 'text' => $words.'.']]]))
            ->pushResponse($this->answer(['language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'calm male']], 'paragraphs' => [['speaker' => 'A', 'text' => implode(' ', array_fill(0, 40, 'آخر')).' الكلام.']]]))]);

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'auto']);
        } finally {
            @unlink($file);
        }

        $this->assertSame(2, $out['parts']);
        $this->assertSame('ar', $out['language']);
        $prompts = [];
        Http::assertSent(function ($request) use (&$prompts): bool {
            $prompts[] = (string) data_get($request->data(), 'contents.0.parts.1.text');

            return true;
        });
        $this->assertStringContainsString('Find the language spoken', $prompts[0]);
        $this->assertStringContainsString('The audio is in Arabic', $prompts[1]);
        $this->assertStringContainsString('Voices heard earlier in this recording: A: calm male', $prompts[1]);
        $this->assertSame(320.0, round(end($out['segments'])['end']));
    }

    public function test_a_short_or_unreadable_answer_is_asked_for_again_and_the_fuller_one_kept(): void
    {
        // 40 seconds of steady tone: no pauses, so all of it counts as speech.
        $file = $this->tone('retry', '40');
        $this->fakeKeys();
        $full = implode(' ', array_fill(0, 60, 'word'));
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]], 'finishReason' => 'STOP']]])
            ->pushResponse($this->answer(['language' => 'en', 'speakers' => [], 'paragraphs' => [['speaker' => 'A', 'text' => 'which she was capable.']]]))
            ->pushResponse($this->answer(['language' => 'en', 'speakers' => [], 'paragraphs' => [['speaker' => 'A', 'text' => 'which she was capable.']]]))
            ->pushResponse($this->answer(['language' => 'en', 'speakers' => [], 'paragraphs' => [['speaker' => 'A', 'text' => $full]]]))]);

        try {
            $short = app(Transcriber::class)->transcribe($file, ['language' => 'en']);
            $whole = app(Transcriber::class)->transcribe($file, ['language' => 'en']);
        } finally {
            @unlink($file);
        }

        $this->assertSame('which she was capable.', $short['text'], 'two tries at most: the unreadable one, then the short one');
        $this->assertSame(2, $short['requests']);
        $this->assertStringContainsString('no answer to read', $short['notes'][0]);
        $this->assertStringContainsString('still only 4 words', $short['notes'][1]);
        $this->assertSame($full, $whole['text']);
        $this->assertStringContainsString('only 4 words', $whole['notes'][0]);
        Http::assertSent(fn ($request) => str_contains((string) data_get($request->data(), 'contents.0.parts.1.text'), 'not only its opening'));
    }

    public function test_a_check_that_drops_words_is_not_kept(): void
    {
        $file = $this->tone('review', '8');
        $this->fakeKeys();
        $say = fn (string $t) => $this->answer(['language' => 'ar', 'speakers' => [], 'paragraphs' => [['speaker' => 'A', 'text' => $t]]]);
        Http::fake(['*' => Http::sequence()
            ->pushResponse($say('بص بقى أنا عايزة أقولك حاجة مهمة قوي'))
            ->pushResponse($say('بص بقى أنا عايزة أقولك حاجة مهمة أوي'))
            ->pushResponse($say('بص بقى أنا عايزة أقولك حاجة مهمة قوي'))
            ->pushResponse($say('بص'))]);

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
    }

    public function test_every_dialect_is_measured_in_a_file_of_its_own_country_with_each_clip_scored_and_timed(): void
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
        $this->assertGreaterThanOrEqual(140, count(array_unique(array_merge(...array_values(array_map(fn ($r) => array_column($r['clips'], 'dialect'),
            array_filter($byLabel, fn ($r) => $r['language'] === 'ar')))))), 'about every Arabic variety the site has');
        $this->assertContains('tr', array_column(TranscribeMeasureCommand::languageRecordings(), 'language'));

        // Clips found by their words, whatever the times say.
        $r = ['clips' => [['slug' => 'one', 'dialect' => 'ar-EG', 'said' => 'أنا عايزة أقولك'], ['slug' => 'two', 'dialect' => 'ar-EG-SA', 'said' => 'يا ولدي اسمع']]];
        $scores = TranscribeMeasureCommand::clipScores($r, [['start' => 0.1, 'end' => 1.0, 'text' => 'أنا عايزة أقولك'], ['start' => 1.0, 'end' => 2.0, 'text' => 'يا ولدي']]);
        $this->assertSame([0.0, 0.3333], array_column($scores, 'wer'));

        // Times: clip starts at 5.5 and 11 s; sentences start at 5.7 and 13 s.
        $this->assertSame(['clips' => 2, 'within' => 1, 'mean' => 1.1],
            TranscribeMeasureCommand::timing([[0.0, 3.0], [5.5, 9.0], [11.0, 14.0]], [['start' => 0.0], ['start' => 5.7], ['start' => 13.0]]));

        $this->assertSame(['language' => 'auto'], TranscribeMeasureCommand::variant('auto', ['language' => 'ar', 'dialect' => 'ar-EG']));
        $this->assertSame(['language' => 'ar', 'dialect' => 'ar-EG', 'review' => true, 'thinking' => 'high'],
            TranscribeMeasureCommand::variant('lang+dialect+review+think', ['language' => 'ar', 'dialect' => 'ar-EG']));
    }

    /** A test recording of steady tone: $seconds long, or two $seconds tones with $gap seconds of silence between. */
    /**
     * The times heard for each sentence's start put the sentences on the clock, each start on
     * the speech that begins nearby and each end where its speech ends; times that do not hold
     * together are refused, so the sentences are shared out by their length instead.
     */
    public function test_sentences_go_where_they_are_heard_to_start(): void
    {
        $sentences = [
            ['speaker' => 'A', 'text' => 'Welcome to the shop.', 'paragraph' => 0],
            ['speaker' => 'A', 'text' => 'We have laptops and printers.', 'paragraph' => 0],
            ['speaker' => 'B', 'text' => 'Thank you.', 'paragraph' => 1],
        ];
        // Music until 3.0 s, then speech with pauses at 6.1-6.8 and 11.2-12.0.
        $speech = [[3.0, 6.1], [6.8, 11.2], [12.0, 13.5]];

        $placed = Transcriber::placeOnStarts($sentences, [3.3, 6.5, 12.2], $speech, 0.0, 14.0);
        $this->assertSame([[3.0, 6.1], [6.8, 11.2], [12.0, 13.5]], array_map(fn ($s) => [$s['start'], $s['end']], $placed));
        $this->assertSame('We have laptops and printers.', $placed[1]['text']);

        // A time far from any speech start is kept as heard.
        $this->assertSame(9.0, Transcriber::placeOnStarts($sentences, [3.3, 9.0, 12.2], $speech, 0.0, 14.0)[1]['start']);
        // Out of the audio, mostly going back, all the same, or missing one: refused.
        $this->assertNull(Transcriber::placeOnStarts($sentences, [3.3, 6.5, 30.0], $speech, 0.0, 14.0));
        $this->assertNull(Transcriber::placeOnStarts($sentences, [9.0, 5.0, 2.0], $speech, 0.0, 14.0));
        $this->assertNull(Transcriber::placeOnStarts($sentences, [4.0, 4.0, 4.0], $speech, 0.0, 14.0));
        $this->assertNull(Transcriber::placeOnStarts($sentences, [3.3, 6.5], $speech, 0.0, 14.0));
    }

    /** A part is timed in windows of about 30 s, cut on pauses, end to end; with no pause (music under the voice), every 30 s. */
    public function test_a_part_is_timed_in_short_windows_cut_on_pauses(): void
    {
        $this->assertSame([[0.0, 40.0]], Transcriber::timingWindows([], 0.0, 40.0), 'short: one window');

        $windows = Transcriber::timingWindows([[12.0, 12.4], [29.0, 29.8], [33.0, 33.2], [61.5, 62.5]], 0.0, 100.0);
        $this->assertSame([[0.0, 29.4], [29.4, 62.0], [62.0, 100.0]], $windows, 'on the pause nearest 30 s, a longer one first');

        $none = Transcriber::timingWindows([], 10.0, 130.0);
        $this->assertSame([[10.0, 40.0], [40.0, 70.0], [70.0, 100.0], [100.0, 130.0]], $none);
        foreach ([$windows, $none] as $list) {
            foreach ($list as $i => [$start, $end]) {
                $this->assertLessThanOrEqual(42.0, $end - $start);
                if ($i > 0) {
                    $this->assertSame($list[$i - 1][1], $start, 'end to end');
                }
            }
        }
    }

    /** A longer recording is timed window by window, all asked at once, each on its own clock; a start in the middle of speech comes from the window that heard it. */
    public function test_a_longer_recording_is_timed_window_by_window(): void
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        // Voice 0-20 s, a pause, voice 21-45 s, a pause, voice 46-70 s.
        $file = sys_get_temp_dir().'/transcriber-windows-'.getmypid().'.wav';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=20', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono:d=1',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=24', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono:d=1',
            '-f', 'lavfi', '-i', 'sine=frequency=300:duration=24',
            '-filter_complex', '[0:a]aformat=sample_rates=44100:channel_layouts=mono[a];[2:a]aformat=sample_rates=44100:channel_layouts=mono[c];[4:a]aformat=sample_rates=44100:channel_layouts=mono[e];[a][1:a][c][3:a][e]concat=n=5:v=0:a=1',
            $file]))->mustRun();
        $this->fakeKeys();
        $timing = [];
        Http::fake(function ($request) use (&$timing) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.1.text');
            if (! str_contains($prompt, 'cut from a longer recording')) {
                return $this->answer(['language' => 'en', 'speakers' => [['id' => 'A', 'voice' => 'male'], ['id' => 'B', 'voice' => 'female']], 'paragraphs' => [
                    ['speaker' => 'A', 'text' => 'Welcome to the shop. We have laptops, printers and screens for every desk.'],
                    ['speaker' => 'B', 'text' => 'Thank you, I am looking for a quiet keyboard.'],
                    ['speaker' => 'B', 'text' => 'And a mouse for gaming, please.'],
                ]]);
            }
            preg_match('/This audio is ([\d.]+) seconds long/', $prompt, $m);
            $timing[] = $m[1];

            // Each window answers on its own clock: 0-20.5 s, 20.5-45.5 s, 45.5-70 s.
            return $this->answer(['sentences' => match ($m[1]) {
                '21.5' => [['n' => 1, 'start' => 0.0], ['n' => 2, 'start' => 10.0]],
                '26.0' => [['n' => 3, 'start' => 0.6]],
                default => [['n' => 4, 'start' => 0.4]],
            }]);
        });

        try {
            $out = app(Transcriber::class)->transcribe($file, ['language' => 'en']);
        } finally {
            @unlink($file);
        }

        sort($timing);
        $this->assertSame(['21.5', '24.5', '26.0'], $timing, 'three windows, cut in the two pauses');
        $this->assertSame(['Welcome to the shop.', 'We have laptops, printers and screens for every desk.', 'Thank you, I am looking for a quiet keyboard.', 'And a mouse for gaming, please.'], array_column($out['segments'], 'text'));
        $starts = array_column($out['segments'], 'start');
        $this->assertEqualsWithDelta(0.0, $starts[0], 0.1);
        $this->assertEqualsWithDelta(10.0, $starts[1], 0.05, 'in the middle of the speech: as the window heard it');
        $this->assertEqualsWithDelta(21.0, $starts[2], 0.15, 'moved onto where the speech begins after the pause');
        $this->assertEqualsWithDelta(46.0, $starts[3], 0.15);
        $this->assertStringContainsString('times: 3 windows, 3 answered, 4 of 4 sentences heard', implode(' | ', $out['notes']));
    }

    /** The windows' answers make one start for each sentence: the earlier window's when two claim one, none out of order, the rest by length. */
    public function test_the_windows_answers_make_one_start_for_each_sentence(): void
    {
        $sentences = array_map(fn ($t) => ['text' => $t], ['One two three.', 'Four five six.', 'Seven eight nine ten eleven.', 'Twelve.', 'Thirteen fourteen.']);

        // Sentence 3 (index 2) begins at the cut: the first window hears it at 29.6, the second at 30.0.
        $claims = [
            ['i' => 0, 'at' => 0.5, 'window' => 0], ['i' => 1, 'at' => 12.0, 'window' => 0], ['i' => 2, 'at' => 29.6, 'window' => 0],
            ['i' => 2, 'at' => 30.0, 'window' => 1], ['i' => 3, 'at' => 41.0, 'window' => 1], ['i' => 4, 'at' => 50.0, 'window' => 1],
        ];
        $this->assertSame([0.5, 12.0, 29.6, 41.0, 50.0], Transcriber::startsFromClaims($claims, $sentences, 0.0, 60.0));

        // A claim out of order is dropped, and the sentence without a time goes between its neighbours by length.
        $wrong = [['i' => 0, 'at' => 0.5, 'window' => 0], ['i' => 1, 'at' => 44.0, 'window' => 0], ['i' => 2, 'at' => 20.0, 'window' => 0],
            ['i' => 3, 'at' => 41.0, 'window' => 1], ['i' => 4, 'at' => 50.0, 'window' => 1]];
        $starts = Transcriber::startsFromClaims($wrong, $sentences, 0.0, 60.0);
        $this->assertSame([0.5, 20.0, 41.0, 50.0], [$starts[0], $starts[2], $starts[3], $starts[4]]);
        $this->assertSame(round(0.5 + 19.5 * 14 / 28, 2), $starts[1], 'between 0.5 and 20.0, after a sentence as long as itself');

        // Too few timed: none.
        $this->assertNull(Transcriber::startsFromClaims([['i' => 0, 'at' => 1.0, 'window' => 0]], $sentences, 0.0, 60.0));
    }

    /** A browser recording has no length in its header ("Duration: N/A"): it is read to its end, never taken as 0 s. */
    public function test_a_browser_recording_without_a_length_in_its_header_is_measured(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/rec-'.bin2hex(random_bytes(4)).'.webm';
        // Written to a pipe, as a browser writes it while recording: the header cannot hold the length.
        Process::fromShellCommandline('"$FF" -v error -f lavfi -i sine=frequency=300:duration=7.5 -c:a libopus -f webm - > "$OUT"')
            ->setTimeout(60)->mustRun(null, ['FF' => $ffmpeg, 'OUT' => $file]);
        $header = new Process([$ffmpeg, '-hide_banner', '-i', $file]);
        $header->run();
        $this->assertStringContainsString('Duration: N/A', $header->getErrorOutput(), 'the case a browser makes');

        $this->assertEqualsWithDelta(7.5, Transcriber::lengthOf($file), 0.1);
        @unlink($file);
    }

    private function tone(string $name, string $seconds, ?string $gap = null): string
    {
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $file = sys_get_temp_dir().'/transcriber-'.$name.'-'.getmypid().'.wav';
        $make = $gap === null
            ? [AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, $file]
            : [AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono:d='.$gap,
                '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-filter_complex', '[0:a]aformat=sample_rates=44100:channel_layouts=mono[a];[2:a]aformat=sample_rates=44100:channel_layouts=mono[c];[a][1:a][c]concat=n=3:v=0:a=1', $file];
        (new Process($make))->run();

        return $file;
    }

    private function fakeKeys(): void
    {
        $this->mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('keysInOrder')->andReturn([['name' => 'GEMINI_TTS_API_KEY', 'key' => 'test-key']]);
            $mock->shouldReceive('recordUsage');
        });
    }

    /** @param  array<string, mixed>  $data */
    private function answer(array $data): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 700, 'candidatesTokenCount' => 40,
                'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 128], ['modality' => 'TEXT', 'tokenCount' => 572]]]]);
    }
}
