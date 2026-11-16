<?php

namespace Tests\Feature;

use App\Http\Controllers\TranscribeController;
use App\Jobs\TranscribeJob;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Agents\SiteReader;
use App\Services\Dubbing\VideoDubber;
use App\Services\Transcription\AudioLink;
use App\Services\Transcription\StepOutOfTime;
use App\Services\Transcription\Transcriber;
use App\Services\Transcription\TranscriptEditor;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\UserDeletionService;
use App\Support\AudioFormats;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Speech to text kept safe and quick: files read as plain audio only, links fetched within limits,
 * uploads checked before they are sent, deleting keeps no words, a step never outlasts its time,
 * its own queues and limits, and the lighter list, saves and waveform.
 */
class TranscriptionSafetyTest extends TestCase
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
        $keys->shouldIgnoreMissing();
        $this->app->instance(GeminiKeyManager::class, $keys);
        AudioLink::$resolver = null;
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class]);
    }

    protected function tearDown(): void
    {
        AudioLink::$resolver = null;
        parent::tearDown();
    }

    // ------------------------------------------------------------------ files

    public function test_a_playlist_is_never_opened_and_a_strange_ending_is_never_kept(): void
    {
        $other = $this->audio(4);
        $playlist = sys_get_temp_dir().'/tr-safe-'.bin2hex(random_bytes(4)).'.m3u8';
        file_put_contents($playlist, "#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXT-X-VERSION:3\n#EXTINF:4.0,\n{$other}\n#EXT-X-ENDLIST\n");

        $this->assertFalse(Transcriber::probe($playlist)['audio'], 'the other file is not opened to find a sound');
        try {
            app(Transcriber::class)->clean($playlist, sys_get_temp_dir().'/tr-safe-'.bin2hex(random_bytes(4)).'.mp3');
            $this->fail('a playlist should not be made into a clean copy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be read', $e->getMessage());
        }

        // A real file keeps an ending the page takes; any other is kept under a neutral one.
        Queue::fake();
        $kept = app(TranscriptionFlow::class)->start($this->member(60), $this->audio(3), ['title' => 'Talk', 'file_name' => 'talk.MP3'])->fresh();
        $this->assertStringEndsWith('/source.mp3', (string) $kept->audio_path);
        $odd = app(TranscriptionFlow::class)->start($this->member(60), $this->audio(3), ['title' => 'Talk', 'file_name' => 'talk.m3u8'])->fresh();
        $this->assertStringEndsWith('/source.media', (string) $odd->audio_path);
        $this->assertTrue(Transcriber::probe(Storage::disk('local')->path((string) $odd->audio_path))['audio'], 'its content still opens');
    }

    public function test_only_the_clean_copy_is_served_as_audio(): void
    {
        $user = $this->member(60);
        $this->actingAs($user);
        $t = Transcription::query()->create(['user_id' => $user->id, 'title' => 'Early', 'status' => Transcription::FAILED, 'seconds' => 4,
            'charged_seconds' => 0, 'error_code' => 'unreadable', 'language_requested' => 'auto']);
        $original = TranscriptionFlow::folder($t).'/source.mp3';
        Storage::disk('local')->put($original, 'member bytes');
        $t->forceFill(['audio_path' => $original])->save();

        $this->get(route('transcribe.audio', $t->code))->assertNotFound();
        $this->assertFalse($this->getJson(route('transcribe.show', $t->code))->json('transcription.audio'));

        $this->answers([['speaker' => 'A', 'text' => 'Hello there.']]);
        $ready = app(TranscriptionFlow::class)->start($user, $this->audio(3), ['title' => 'Talk'])->fresh();
        $this->get(route('transcribe.audio', $ready->code))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------------------------------ links

    public function test_one_link_at_a_time_for_each_member_and_a_few_for_the_site(): void
    {
        AudioLink::$resolver = fn (string $host) => $host === 'cdn.example.org' ? '93.184.216.34' : null;
        Http::fake(['cdn.example.org/*' => Http::response('nothing here', 404)]);
        $user = $this->member(60);
        $this->actingAs($user);
        $link = ['url' => 'https://cdn.example.org/a.mp3'];

        $held = Cache::lock('transcribe-link:'.$user->id, 60);
        $held->get();
        $this->postJson(route('transcribe.store'), $link)->assertStatus(429)->assertJsonPath('code', 'busy')
            ->assertJsonPath('message', __('transcribe.refused.link_busy'));
        $held->release();

        $slots = array_map(fn (int $i) => tap(Cache::lock('transcribe-link-slot:'.$i, 60))->get(), range(1, AudioLink::AT_ONCE));
        $this->postJson(route('transcribe.store'), $link)->assertStatus(503)->assertJsonPath('code', 'site_busy')
            ->assertJsonPath('message', __('transcribe.refused.links_busy'));
        array_walk($slots, fn ($lock) => $lock->release());

        // Each refusal lets go of what it held: the next link is fetched (and refused for what it is).
        $this->postJson(route('transcribe.store'), $link)->assertStatus(422)->assertJsonPath('code', 'bad_link');
        $this->postJson(route('transcribe.store'), $link)->assertStatus(422)->assertJsonPath('code', 'bad_link');
    }

    public function test_a_link_too_slow_to_download_is_refused_saying_so(): void
    {
        AudioLink::$resolver = fn (string $host) => '93.184.216.34';
        Http::fake(['cdn.example.org/*' => Http::failedConnection('cURL error 28: Operation too slow. Less than 32768 bytes/sec transferred the last 15 seconds')]);
        $this->actingAs($this->member(60));

        $this->postJson(route('transcribe.store'), ['url' => 'https://cdn.example.org/slow.mp3'])->assertStatus(422)
            ->assertJsonPath('code', 'bad_link')->assertJsonPath('message', __('transcribe.refused.slow_link'));
        $this->assertSame(0, Transcription::query()->count());
    }

    // ------------------------------------------------------------------ uploads

    public function test_an_upload_that_could_not_start_is_refused_before_a_byte_is_sent(): void
    {
        $user = $this->member(1);
        $this->actingAs($user);
        Transcription::query()->create(['user_id' => $user->id, 'title' => 'Earlier', 'status' => Transcription::READY, 'seconds' => 55,
            'charged_seconds' => 55, 'period_ym' => TranscriptionAccess::balance($user)['period'], 'language_requested' => 'auto']);

        $this->postJson(route('transcribe.upload.start'), ['name' => 'talk.mp3', 'size' => 4000, 'seconds' => 30])->assertStatus(402)
            ->assertJsonPath('code', 'not_enough');
        // The browser could not tell its length: the plan and what is left are still checked.
        $this->postJson(route('transcribe.upload.start'), ['name' => 'talk.mp3', 'size' => 4000])->assertCreated();
        $this->assertCount(1, Storage::disk('local')->files(TranscribeController::UPLOADS));

        // A member whose free try is used is not sent the file either.
        $free = User::factory()->create(['email_verified_at' => now()]);
        Transcription::query()->create(['user_id' => $free->id, 'title' => 'Try', 'status' => Transcription::READY, 'seconds' => 20,
            'charged_seconds' => 20, 'is_free' => true, 'language_requested' => 'auto']);
        $this->actingAs($free)->postJson(route('transcribe.upload.start'), ['name' => 'talk.mp3', 'size' => 4000, 'seconds' => 20, 'source' => 'record'])
            ->assertStatus(403)->assertJsonPath('code', 'free_used');
    }

    public function test_unfinished_uploads_bytes_a_day_and_the_disk_have_limits(): void
    {
        $user = $this->member(60);
        $this->actingAs($user);
        $start = fn (int $size = 4000) => $this->postJson(route('transcribe.upload.start'), ['name' => 'talk.mp3', 'size' => $size, 'seconds' => 3]);

        foreach (range(1, TranscribeController::UPLOADS_AT_ONCE) as $n) {
            $start()->assertCreated();
        }
        $start()->assertStatus(429)->assertJsonPath('code', 'uploads_busy');
        // One left behind stops counting after a while.
        $this->travel(TranscribeController::UPLOAD_IDLE_MINUTES + 1)->minutes();
        $start()->assertCreated();

        Cache::put('transcribe-upload-bytes:'.$user->id.':'.now()->format('Ymd'), TranscribeController::UPLOAD_BYTES_A_DAY - 100, now()->addDay());
        $this->travel(TranscribeController::UPLOAD_IDLE_MINUTES + 1)->minutes();
        $start(4000)->assertStatus(429)->assertJsonPath('code', 'upload_day_full');

        config(['listen.transcription.keep_free_mb' => 1024 * 1024 * 1024]);
        $start(10)->assertStatus(503)->assertJsonPath('code', 'uploads_paused');
    }

    // ------------------------------------------------------------------ deleting

    public function test_deleting_a_transcript_removes_its_words_and_recording_and_keeps_its_minutes(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'Something private.']]);
        $user = $this->member(60);
        $this->actingAs($user);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(5), ['title' => 'Talk'])->fresh();
        $t->forceFill(['translations' => ['fr' => ['paragraphs' => [], 'segments' => []]], 'speaker_names' => ['A' => 'Mona']])->save();
        $folder = TranscriptionFlow::folder($t);
        $this->assertNotSame([], Storage::disk('local')->files($folder));
        $used = TranscriptionAccess::balance($user)['used'];

        $this->deleteJson(route('transcribe.destroy', $t->code))->assertOk();

        $row = Transcription::withTrashed()->find($t->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertNull($row->paragraphs);
        $this->assertNull($row->segments);
        $this->assertNull($row->translations);
        $this->assertNull($row->speaker_names);
        $this->assertSame('', (string) DB::table('transcriptions')->where('id', $t->id)->value('search_text'));
        $this->assertFalse(Storage::disk('local')->exists($folder.'/audio.mp3'));
        $this->assertNotNull($row->audio_deleted_at);
        $this->assertSame($used, TranscriptionAccess::balance($user)['used'], 'what it used stays counted in its month');
    }

    public function test_deleting_an_account_removes_its_recordings(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $t = Transcription::query()->create(['user_id' => $user->id, 'title' => 'Talk', 'status' => Transcription::READY, 'seconds' => 4,
            'charged_seconds' => 4, 'language_requested' => 'auto']);
        $folder = TranscriptionFlow::folder($t);
        Storage::disk('local')->put($folder.'/audio.mp3', 'sound');

        $this->assertNull(app(UserDeletionService::class)->delete($user, null));

        $this->assertFalse(Storage::disk('local')->exists($folder.'/audio.mp3'));
        $this->assertSame(0, Transcription::withTrashed()->count());
    }

    public function test_the_housekeeping_waits_longer_for_queued_clears_deleted_and_removes_folders_left_behind(): void
    {
        $user = User::factory()->create();
        $make = fn (string $status, int $minutesAgo) => tap(Transcription::query()->create(['user_id' => $user->id, 'title' => $status, 'status' => $status,
            'seconds' => 4, 'charged_seconds' => 4, 'language_requested' => 'auto']), fn ($t) => DB::table('transcriptions')->where('id', $t->id)
            ->update(['updated_at' => now()->subMinutes($minutesAgo)]));
        $waiting = $make(Transcription::QUEUED, 45);
        $stalled = $make(Transcription::PROCESSING, 45);
        $long = $make(Transcription::QUEUED, 150);
        $deleted = $make(Transcription::READY, 1);
        $deleted->forceFill(['paragraphs' => [['speaker' => 'A', 'text' => 'secret', 'start' => 0, 'end' => 1]]])->save();
        $deleted->delete();
        $disk = Storage::disk('local');
        $disk->put('transcriptions/20200101/tr_leftbehind000000000000000/audio.mp3', 'x');
        $known = $make(Transcription::READY, 1);
        $knownFolder = 'transcriptions/20200101/'.$known->code;
        $disk->put($knownFolder.'/audio.mp3', 'x');
        $disk->put('transcriptions/'.now()->format('Ymd').'/tr_madetoday00000000000000/audio.mp3', 'x');

        Artisan::call('transcriptions:prune');

        $this->assertSame(Transcription::QUEUED, $waiting->fresh()->status, 'a file waiting its turn is not stuck');
        $this->assertSame(Transcription::FAILED, $stalled->fresh()->status);
        $this->assertSame(Transcription::FAILED, $long->fresh()->status);
        $this->assertNull(Transcription::withTrashed()->find($deleted->id)->paragraphs);
        $this->assertFalse($disk->exists('transcriptions/20200101/tr_leftbehind000000000000000/audio.mp3'));
        $this->assertTrue($disk->exists($knownFolder.'/audio.mp3'), 'a folder a transcript points to stays');
        $this->assertTrue($disk->exists('transcriptions/'.now()->format('Ymd').'/tr_madetoday00000000000000/audio.mp3'), 'a recent one is left alone');
    }

    // ------------------------------------------------------------------ the work

    public function test_a_step_with_too_little_time_left_asks_nothing_and_is_tried_again_later(): void
    {
        Http::fake();
        $transcriber = app(Transcriber::class);
        $transcriber->until(microtime(true) + 10);

        try {
            $transcriber->transcribe($this->audio(4));
            $this->fail('the step should have stopped for want of time');
        } catch (StepOutOfTime) {
            Http::assertNothingSent();
        } finally {
            $transcriber->until(null);
        }
    }

    public function test_a_key_out_of_its_daily_quota_is_used_last_and_the_next_one_answers(): void
    {
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'KEY_ONE', 'index' => 1], ['key' => 'k2', 'name' => 'KEY_TWO', 'index' => 2]]);
        $keys->shouldReceive('recordUsage');
        $keys->shouldReceive('markOutForToday')->with('KEY_ONE', VideoDubber::MODELS[0])->atLeast()->once();
        $keys->shouldIgnoreMissing();
        $this->app->instance(GeminiKeyManager::class, $keys);
        $answer = $this->answer([['speaker' => 'A', 'text' => 'Hello there.']]);
        Http::fake(fn ($request) => $request->hasHeader('x-goog-api-key', 'k1')
            ? Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded for metric: generate_content_free_tier_requests, limit: GenerateRequestsPerDayPerProjectPerModel']], 429)
            : $answer);

        $result = app(Transcriber::class)->transcribe($this->audio(4));

        $this->assertSame('Hello there.', $result['paragraphs'][0]['text']);
    }

    public function test_steps_go_to_speech_to_texts_own_queues_worked_by_its_own_workers(): void
    {
        Queue::fake();
        app(TranscriptionFlow::class)->start($this->member(60), $this->audio(3), ['title' => 'Talk']);
        Queue::assertPushedOn(TranscribeJob::QUEUE, TranscribeJob::class);

        $priority = $this->member(60);
        Plan::query()->whereKey(TranscriptionAccess::planOf($priority)->id)->update(['transcription_priority' => true]);
        app(TranscriptionFlow::class)->start($priority, $this->audio(3), ['title' => 'Talk']);
        Queue::assertPushedOn(TranscribeJob::QUEUE_PRIORITY, TranscribeJob::class);

        Artisan::call('schedule:list');
        $this->assertSame(3, substr_count(Artisan::output(), "queue:work --queue='transcribe-high,transcribe'"));
    }

    public function test_short_files_worked_through_right_away_are_a_few_at_a_time(): void
    {
        Queue::fake();
        $this->answers([['speaker' => 'A', 'text' => 'Hello there.']]);
        $t = app(TranscriptionFlow::class)->start($this->member(60), $this->audio(3), ['title' => 'Talk']);
        $slots = array_map(fn (int $i) => tap(Cache::lock('transcribe-right-away:'.$i, 300))->get(), range(1, TranscribeJob::RIGHT_AWAY_AT_ONCE));

        TranscribeJob::runNow($t->id);
        $this->assertSame(Transcription::QUEUED, $t->fresh()->status, 'all taken: the queue works it');

        $slots[0]->release();
        TranscribeJob::runNow($t->id);
        $this->assertSame(Transcription::READY, $t->fresh()->status);
    }

    // ------------------------------------------------------------------ translations

    public function test_translations_have_limits_for_a_transcript_and_a_day(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'Hello there.']]);
        $user = $this->member(60);
        $this->actingAs($user);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(4), ['title' => 'Talk'])->fresh();
        $translator = \Mockery::mock(TranslationService::class);
        $translator->shouldReceive('prefetch');
        $translator->shouldReceive('maybeTranslate')->andReturnUsing(fn (string $text) => 'T: '.$text);
        $this->app->instance(TranslationService::class, $translator);
        $translate = fn (string $language) => $this->postJson(route('transcribe.translate', $t->code), ['language' => $language]);

        foreach (['fr', 'de', 'es', 'it', 'tr'] as $language) {
            $translate($language)->assertOk();
        }
        $translate('ar')->assertStatus(429)->assertJsonPath('code', 'translation_limit')
            ->assertJsonPath('message', __('transcribe.refused.translations_each', ['n' => TranscriptEditor::LANGUAGES_EACH]));
        $translate('fr')->assertOk()->assertJsonPath('paragraphs.0.text', 'T: Hello there.');

        $other = app(TranscriptionFlow::class)->start($user, $this->audio(4), ['title' => 'Other'])->fresh();
        Cache::put('transcribe-translations:'.$user->id.':'.now()->format('Ymd'), TranscriptEditor::NEW_A_DAY, now()->addDay());
        $this->postJson(route('transcribe.translate', $other->code), ['language' => 'fr'])->assertStatus(429)
            ->assertJsonPath('message', __('transcribe.refused.translations_day', ['n' => TranscriptEditor::NEW_A_DAY]));
        Cache::put('transcribe-translations:'.$user->id.':'.now()->format('Ymd'), 0, now()->addDay());
        Cache::put('transcribe-translated-letters:'.$user->id.':'.now()->format('Ymd'), TranscriptEditor::LETTERS_A_DAY, now()->addDay());
        $this->postJson(route('transcribe.translate', $other->code), ['language' => 'fr'])->assertStatus(429)
            ->assertJsonPath('message', __('transcribe.refused.translations_letters'));
    }

    // ------------------------------------------------------------------ the page's speed

    public function test_the_list_reads_no_sentences_or_translations_and_saves_send_back_only_what_changed(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'One.'], ['speaker' => 'B', 'text' => 'Two.']]);
        $user = $this->member(60);
        $this->actingAs($user);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(4), ['title' => 'Talk'])->fresh();

        DB::enableQueryLog();
        $list = $this->getJson(route('transcribe.index'))->assertOk();
        $read = collect(DB::getQueryLog())->pluck('query')->first(fn (string $q) => str_contains($q, 'from "transcriptions"') && str_contains($q, 'limit'));
        DB::disableQueryLog();
        $this->assertNotNull($read);
        $this->assertStringNotContainsString('"segments"', $read);
        $this->assertStringNotContainsString('"translations"', $read);
        $this->assertStringNotContainsString('*', $read);
        $list->assertJsonPath('items.0.id', $t->code)->assertJsonPath('items.0.speakers', 2)->assertJsonPath('items.0.title', 'Talk');

        $renamed = $this->patchJson(route('transcribe.update', $t->code), ['title' => 'Interview'])->assertOk();
        $renamed->assertJsonPath('transcription.title', 'Interview')->assertJsonPath('transcription.speaker_list.0.id', 'A');
        $this->assertNull($renamed->json('transcription.paragraphs'), 'a new title sends no words back');
        $this->patchJson(route('transcribe.update', $t->code), ['speakers' => ['B' => 'Ahmed']])->assertOk()
            ->assertJsonPath('transcription.speaker_list.1.name', 'Ahmed');
        $this->patchJson(route('transcribe.update', $t->code), ['paragraph' => 1, 'text' => 'Two, corrected.'])->assertOk()
            ->assertJsonPath('transcription.paragraphs.1.text', 'Two, corrected.');
    }

    public function test_the_waveform_comes_with_the_transcript_a_peak_every_ten_milliseconds(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'Hello there.']]);
        $user = $this->member(60);
        $this->actingAs($user);
        $t = app(TranscriptionFlow::class)->start($user, $this->audio(6), ['title' => 'Talk'])->fresh();

        $show = $this->getJson(route('transcribe.show', $t->code))->assertOk();
        $this->assertSame(route('transcribe.peaks', $t->code), $show->json('transcription.urls.peaks'));
        $peaks = $this->get(route('transcribe.peaks', $t->code))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $bytes = array_values(unpack('C*', (string) $peaks->streamedContent()));
        $this->assertEqualsWithDelta(600, count($bytes), 20, 'a hundred a second');
        $this->assertGreaterThan(20, max($bytes), 'the tone is heard');
        $this->assertLessThan(60, max($bytes), 'at its own level (an eighth of the loudest)');

        Storage::disk('local')->delete(TranscriptionFlow::folder($t).'/'.Transcriber::PEAKS_FILE);
        $this->get(route('transcribe.peaks', $t->code))->assertNotFound();
    }

    // ------------------------------------------------------------------ names and addresses

    public function test_refusals_name_the_plan_as_the_pricing_page_does(): void
    {
        Plan::factory()->create(['slug' => 'creator', 'name' => 'creator internal', 'name_en' => 'Creator', 'name_ar' => 'المبدع', 'price_cents' => 2200,
            'monthly_chars' => 100000, 'transcription_enabled' => true, 'transcription_minutes_month' => 250, 'transcription_max_file_minutes' => 60,
            'transcription_concurrent_jobs' => 1]);
        $this->actingAs($this->member(120, 30));

        $this->postJson(route('transcribe.check'), ['seconds' => 45 * 60])->assertStatus(422)
            ->assertJsonPath('upgrade.plan', 'Creator')
            ->assertJsonPath('message', 'This file is 45 min long and your plan takes files up to 30 min. The Creator plan takes it.');
    }

    public function test_the_link_guard_refuses_translated_ipv6_addresses(): void
    {
        $this->assertFalse(SiteReader::isPublicIp('64:ff9b::7f00:1'), 'NAT64 for 127.0.0.1');
        $this->assertFalse(SiteReader::isPublicIp('64:ff9b:1::a00:1'), 'local-use NAT64 for 10.0.0.1');
        $this->assertTrue(SiteReader::isPublicIp('2606:4700:4700::1111'));
        $this->assertTrue(SiteReader::isPublicIp('93.184.216.34'));
    }

    // ------------------------------------------------------------------ helpers

    private function member(int $minutes, int $fileMinutes = 30): User
    {
        $plan = Plan::factory()->create(['slug' => 'member-'.bin2hex(random_bytes(3)), 'price_cents' => 900, 'monthly_chars' => 100000, 'monthly_requests' => 100,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes, 'transcription_max_file_minutes' => $fileMinutes,
            'transcription_concurrent_jobs' => 1]);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);

        return $user;
    }

    /** A recording of tone, $seconds long, in a file of its own. */
    private function audio(float $seconds): string
    {
        $file = sys_get_temp_dir().'/tr-safe-'.bin2hex(random_bytes(4)).'.mp3';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-c:a', 'libmp3lame', $file]))->mustRun();

        return $file;
    }

    /** @param  array<int, array{speaker: string, text: string}>  $paragraphs */
    private function answers(array $paragraphs): void
    {
        Http::fake(['*' => $this->answer($paragraphs)]);
    }

    /** @param  array<int, array{speaker: string, text: string}>  $paragraphs */
    private function answer(array $paragraphs): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['language' => 'en', 'speakers' => [],
            'paragraphs' => $paragraphs], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30]]);
    }
}
