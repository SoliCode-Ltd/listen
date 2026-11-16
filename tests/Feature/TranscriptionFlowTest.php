<?php

namespace Tests\Feature;

use App\Jobs\TranscribeJob;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\Transcription\TranscriptionRefused;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioFormats;
use App\Support\Transcription\Minutes;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionPresenter;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Transcribe's minutes: held when a file starts, what it uses when it is
 * done, given back when it fails, counted by the second, from the plan only,
 * starting again with the billing month; the free try; and every rule
 * checked before anything is used, naming the plan that fits.
 */
class TranscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        Storage::fake('local');
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        $this->app->instance(GeminiKeyManager::class, $keys);
    }

    public function test_the_seconds_shown_before_it_starts_are_the_seconds_it_uses(): void
    {
        $this->answers('هلا والله، شلونكم؟');
        $user = $this->member(minutes: 120);

        $t = app(TranscriptionFlow::class)->start($user, $this->audio(12.4), ['title' => 'voice note.m4a', 'language' => 'ar']);

        $t->refresh();
        $this->assertSame(Transcription::READY, $t->status);
        $this->assertSame(12, $t->charged_seconds, 'its length to the nearest second');
        $this->assertSame(TranscriptionAccess::chargeFor(12.4), $t->charged_seconds);
        // What it uses is what its length shows, to the second, for any length.
        foreach ([0.6, 12.4, 12.5, 101.6, 102.49, 3599.5] as $seconds) {
            $this->assertSame(Minutes::format(max(1, (int) round($seconds))), Minutes::format(TranscriptionAccess::chargeFor($seconds)), "{$seconds} s");
        }
        $shown = TranscriptionPresenter::summary($t, false, false);
        $this->assertSame($shown['length'], $shown['used'], 'the length and the minutes used read the same');
        $balance = TranscriptionAccess::balance($user);
        $this->assertSame(7200, $balance['allowance']);
        $this->assertSame(12, $balance['used']);
        $this->assertSame(7188, $balance['left']);
        $this->assertSame('هلا والله، شلونكم؟', $t->paragraphs[0]['text']);
        $this->assertSame('ar', $t->language);
        $this->assertNull($t->work);
        $this->assertGreaterThan(0, $t->cost_usd);
        $this->assertFileExists(Storage::disk('local')->path(TranscriptionFlow::folder($t).'/audio.mp3'), 'the cleaned audio stays to play back');
    }

    public function test_a_file_that_cannot_be_written_out_uses_nothing(): void
    {
        // The model will not answer, however asked.
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '']]], 'finishReason' => 'RECITATION']]])]);
        $user = $this->member(minutes: 120);

        $t = app(TranscriptionFlow::class)->start($user, $this->audio(5), ['title' => 'quran.mp3']);

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame('not_written', $t->error_code);
        $this->assertSame(0, $t->usedSeconds());
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
        $this->assertStringContainsString('5 s held, given back', implode(' ', $t->notes));
        // Given back once: failing it again changes nothing.
        $this->assertFalse(app(TranscriptionFlow::class)->fail($t, 'stuck', 'x'));
    }

    public function test_not_enough_minutes_is_refused_before_anything_is_used_with_the_plan_that_has_more(): void
    {
        $this->answers('Hello.');
        $user = $this->member(minutes: 1);
        $this->plan('pro', 4900, 500, 120);
        app(TranscriptionFlow::class)->start($user, $this->audio(40), ['title' => 'first']);

        try {
            app(TranscriptionFlow::class)->start($user, $this->audio(30), ['title' => 'second']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('not_enough', $e->reason);
            $this->assertSame(402, $e->status);
            $this->assertSame('pro', $e->upgrade['slug']);
            $this->assertStringContainsString('This file needs 30 s and you have 20 s left this month', $e->getMessage());
            $this->assertSame(['seconds_needed' => 30, 'seconds_left' => 20], array_intersect_key($e->toArray(), ['seconds_needed' => 1, 'seconds_left' => 1]));
        }
        $this->assertSame(1, Transcription::query()->count(), 'nothing made, nothing held');
    }

    public function test_a_file_longer_than_the_plan_takes_names_the_plan_that_takes_it(): void
    {
        $user = $this->member(minutes: 120, fileMinutes: 1);
        $this->plan('creator', 2200, 250, 60);

        try {
            app(TranscriptionFlow::class)->start($user, $this->audio(75), ['title' => 'long']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('too_long', $e->reason);
            $this->assertSame('creator', $e->upgrade['slug']);
            $this->assertStringContainsString('This file is 1 min 15 s long and your plan takes files up to 1 min', $e->getMessage());
        }
    }

    public function test_the_minutes_start_again_with_the_billing_month_and_a_deleted_transcript_still_counts(): void
    {
        $this->answers('Hello there.');
        $user = $this->member(minutes: 120);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(20), ['title' => 'a']);
        $t->delete();
        $this->assertSame(20, TranscriptionAccess::balance($user)['used'], 'deleting a transcript does not win its minutes back');

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDays(2));
        Subscription::query()->where('user_id', $user->id)->update(['current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
        $this->assertSame(7200, TranscriptionAccess::balance($user)['left']);
    }

    public function test_the_free_try_is_one_short_recording_with_a_daily_cap_for_the_site(): void
    {
        $this->answers('Hi.');
        $this->plan('starter', 900, 120, 30);
        $user = $this->member(minutes: 0);

        try {
            app(TranscriptionFlow::class)->start($user, $this->audio(70), ['title' => 'long try']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('free_too_long', $e->reason);
            $this->assertSame('starter', $e->upgrade['slug']);
        }
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(8), ['title' => 'try']);
        $this->assertTrue($t->fresh()->is_free);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);

        try {
            app(TranscriptionFlow::class)->start($user, $this->audio(8), ['title' => 'again']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('free_used', $e->reason);
        }

        SiteSetting::set('transcription_free_per_day', 1, TranscriptionSettings::GROUP);
        try {
            app(TranscriptionFlow::class)->start($this->member(minutes: 0), $this->audio(8), ['title' => 'other member']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('free_day_full', $e->reason);
        }

        // The API and MCP need a plan with Transcribe: no free try there.
        try {
            app(TranscriptionFlow::class)->start($this->member(minutes: 0), $this->audio(8), ['title' => 'api', 'source' => 'api']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('not_in_plan', $e->reason);
        }
    }

    public function test_files_at_once_the_site_ceiling_and_who_sees_it(): void
    {
        $user = $this->member(minutes: 120);
        Transcription::query()->create(['user_id' => $user->id, 'title' => 'working', 'status' => Transcription::PROCESSING, 'charged_seconds' => 10, 'period_ym' => 'x']);
        try {
            app(TranscriptionFlow::class)->start($user, $this->audio(5), ['title' => 'b']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('busy', $e->reason);
            $this->assertSame(429, $e->status);
        }

        SiteSetting::set('transcription_daily_minutes', 1, TranscriptionSettings::GROUP);
        try {
            app(TranscriptionFlow::class)->start($this->member(minutes: 120), $this->audio(55), ['title' => 'c']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('site_busy', $e->reason);
        }

        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_OFF, TranscriptionSettings::GROUP);
        try {
            app(TranscriptionFlow::class)->start($this->member(minutes: 120), $this->audio(5), ['title' => 'd']);
            $this->fail('refused');
        } catch (TranscriptionRefused $e) {
            $this->assertSame('not_open', $e->reason);
        }
    }

    public function test_a_stuck_transcript_is_failed_and_its_minutes_given_back(): void
    {
        $user = $this->member(minutes: 120);
        $t = Transcription::query()->create(['user_id' => $user->id, 'title' => 'stuck', 'status' => Transcription::PROCESSING, 'charged_seconds' => 90,
            'period_ym' => TranscriptionAccess::balance($user)['period']]);
        $this->assertSame(90, TranscriptionAccess::balance($user)['used']);

        $this->travel(TranscriptionSettings::stuckMinutes() + 1)->minutes();
        Artisan::call('transcriptions:prune');

        $this->assertSame(Transcription::FAILED, $t->fresh()->status);
        $this->assertSame('stuck', $t->fresh()->error_code);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
    }

    /** Silence, or a microphone that did not pick up: never sent to the model (it can make up a sentence nobody said), and nothing used. */
    public function test_a_recording_with_no_voice_is_not_sent_to_the_model_and_uses_nothing(): void
    {
        Http::fake();
        $user = $this->member(minutes: 120);
        $silence = sys_get_temp_dir().'/tr-flow-silence-'.bin2hex(random_bytes(4)).'.mp3';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anoisesrc=color=pink:amplitude=0.002:duration=15', '-c:a', 'libmp3lame', $silence]))->mustRun();

        $t = app(TranscriptionFlow::class)->start($user, $silence, ['title' => 'Recording', 'source' => 'record']);

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame('no_speech', $t->error_code);
        $this->assertStringContainsString('microphone', (string) $t->error);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
        Http::assertNothingSent();
    }

    /** An answer with no words in it is not a transcript: it fails, and nothing is used. */
    public function test_an_answer_with_no_words_uses_nothing(): void
    {
        $this->answers('[inaudible]');
        $user = $this->member(minutes: 120);

        $t = app(TranscriptionFlow::class)->start($user, $this->audio(6), ['title' => 'Recording']);

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame('no_speech', $t->error_code);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
    }

    /** A short file does not wait for a queue worker: it is worked through once the answer has gone; the queued copy then finds it done. */
    public function test_a_short_file_is_worked_through_at_once(): void
    {
        config(['queue.default' => 'database']);
        $this->answers('هلا والله، شلونكم؟');
        $user = $this->member(minutes: 120);

        $t = app(TranscriptionFlow::class)->start($user, $this->audio(8), ['title' => 'voice note.m4a', 'language' => 'ar']);
        $this->assertSame(Transcription::QUEUED, $t->fresh()->status, 'the answer goes back before the work');
        $this->assertSame(1, DB::table('jobs')->count(), 'the queued copy, as the safety net');

        $this->app->terminate();

        $t->refresh();
        $this->assertSame(Transcription::READY, $t->status);
        $this->assertSame('هلا والله، شلونكم؟', $t->paragraphs[0]['text']);
        $used = TranscriptionAccess::balance($user)['used'];
        // The queued copy, when a worker reaches it, changes nothing.
        (new TranscribeJob($t->id))->handle(app(Transcriber::class), app(TranscriptionFlow::class));
        $this->assertSame(Transcription::READY, $t->fresh()->status);
        $this->assertSame($used, TranscriptionAccess::balance($user)['used']);
    }

    public function test_a_file_without_sound_is_refused(): void
    {
        $user = $this->member(minutes: 120);
        $file = tempnam(sys_get_temp_dir(), 'nosound');
        file_put_contents($file, 'not audio at all');

        $this->expectException(TranscriptionRefused::class);
        app(TranscriptionFlow::class)->start($user, $file, ['title' => 'notes.txt']);
    }

    /**
     * What a transcript uses is the length the audio really has once decoded: a file whose header
     * said less (or more) is charged what it holds when that fits, and stopped with nothing used
     * when it does not; a file's ordinary rounding (under 2 s) changes nothing.
     */
    public function test_a_file_longer_than_its_header_said_uses_its_real_length_or_is_stopped(): void
    {
        $flow = app(TranscriptionFlow::class);
        $user = $this->member(minutes: 3, fileMinutes: 2);
        $held = fn (float $seconds) => Transcription::query()->create(['user_id' => $user->id, 'title' => 'Call', 'source' => 'upload',
            'status' => Transcription::QUEUED, 'seconds' => $seconds, 'charged_seconds' => TranscriptionAccess::chargeFor($seconds),
            'period_ym' => TranscriptionAccess::balance($user)['period'], 'language_requested' => 'auto']);

        $rounding = $held(20.0);
        $this->assertTrue($flow->settleLength($rounding, 21.4));
        $this->assertSame(20, $rounding->fresh()->charged_seconds, 'a second or so of rounding changes nothing');

        $longer = $held(20.0);
        $this->assertTrue($flow->settleLength($longer, 95.6));
        $this->assertSame(96, $longer->fresh()->charged_seconds, 'it uses the length it really has');
        $this->assertSame(95.6, $longer->fresh()->seconds, 'and shows it');

        $tooLong = $held(20.0);
        $this->assertFalse($flow->settleLength($tooLong, 3 * 3600.0), 'three hours behind a header that said 20 s');
        $tooLong->refresh();
        $this->assertSame(Transcription::FAILED, $tooLong->status);
        $this->assertSame('longer', $tooLong->error_code);
        $this->assertSame(0, $tooLong->charged_seconds, 'nothing used');

        // 20 s and 96 s (above) are held of the 180 s: 110 s is within the longest file (120 s), not within the 64 s left.
        $over = $held(20.0);
        $this->assertFalse($flow->settleLength($over, 110.0), 'within the longest file, but more than the minutes left');
        $this->assertSame(Transcription::FAILED, $over->fresh()->status);
        $this->assertSame(116, TranscriptionAccess::balance($user)['used']);
    }

    /** A transcript stopped while a step worked on it (the stuck check, the admin) is never brought back by that step's save. */
    public function test_a_step_does_not_bring_back_a_transcript_stopped_meanwhile(): void
    {
        $user = $this->member(minutes: 120);
        $t = Transcription::query()->create(['user_id' => $user->id, 'title' => 'Call', 'source' => 'upload', 'status' => Transcription::QUEUED,
            'seconds' => 30, 'charged_seconds' => 30, 'period_ym' => TranscriptionAccess::balance($user)['period'], 'language_requested' => 'auto']);
        $stale = Transcription::query()->find($t->id);
        app(TranscriptionFlow::class)->fail($t, 'stuck', __('transcribe.failed.stuck'));

        $save = new \ReflectionMethod(TranscribeJob::class, 'saveIfWorking');
        $this->assertFalse($save->invoke(null, $stale, ['status' => Transcription::PROCESSING, 'parts' => 3]));

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame(0, $t->charged_seconds);
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
    }

    /** A step started again and again without ever finishing (a worker stopped on it each time) fails the transcript, its minutes given back. */
    public function test_a_step_that_never_finishes_fails_after_a_few_starts(): void
    {
        Queue::fake();
        $user = $this->member(minutes: 120);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(5.0), ['title' => 'Call', 'file_name' => 'call.mp3']);
        Cache::put('transcribe-step:'.$t->id.':clean', TranscribeJob::STEP_TRIES, now()->addHour());

        (new TranscribeJob($t->id))->handle(app(Transcriber::class), app(TranscriptionFlow::class));

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame(0, $t->charged_seconds);
    }

    /** The file's name comes from the member or a link: only its ending's letters and digits are used for the stored file. */
    public function test_a_strange_file_name_is_kept_safely(): void
    {
        $this->answers('هلا والله');
        $user = $this->member(minutes: 120);

        $t = app(TranscriptionFlow::class)->start($user, $this->audio(3.0), ['title' => 'x', 'file_name' => "a.mp3\0.php"]);

        $this->assertSame(Transcription::READY, $t->fresh()->status);
        $this->assertStringEndsWith('/audio.mp3', (string) $t->fresh()->audio_path);
    }

    private function member(int $minutes, int $fileMinutes = 30): User
    {
        $plan = $this->plan('member-'.bin2hex(random_bytes(3)), $minutes > 0 ? 900 : 0, $minutes, $fileMinutes);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);

        return $user;
    }

    private function plan(string $slug, int $price, int $minutes, int $fileMinutes): Plan
    {
        return Plan::factory()->create(['slug' => $slug, 'price_cents' => $price, 'monthly_chars' => 100000, 'monthly_requests' => 100,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes, 'transcription_max_file_minutes' => $fileMinutes,
            'transcription_concurrent_jobs' => 1]);
    }

    /** A recording of tone, $seconds long, in a file of its own. */
    private function audio(float $seconds): string
    {
        $file = sys_get_temp_dir().'/tr-flow-'.bin2hex(random_bytes(4)).'.mp3';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-c:a', 'libmp3lame', $file]))->mustRun();

        return $file;
    }

    private function answers(string $text): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'calm']],
            'paragraphs' => [['speaker' => 'A', 'text' => $text]]], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30,
                'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 300], ['modality' => 'TEXT', 'tokenCount' => 600]]]])]);
    }
}
