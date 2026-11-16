<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\AudioLink;
use App\Services\Transcription\TranscriptExport;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioFormats;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Transcribe on the dashboard: a file checked before it is sent (what it uses
 * and what is left), sent in pieces, transcribed, read, corrected, named,
 * translated and downloaded (no minutes for those), deleted (still counted),
 * and a link fetched the safe way. Refusals name the plan that fits.
 */
class TranscribePageTest extends TestCase
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
        AudioLink::$resolver = null;
        // Routes have no locale prefix in tests (TestCase::localizedRoute).
        $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ]);
    }

    public function test_a_file_is_checked_sent_in_pieces_transcribed_and_read(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'مرحبا خديجة، شو صار؟'], ['speaker' => 'B', 'text' => 'كل شي جاهز تقريباً.']]);
        $user = $this->member(120);
        $this->actingAs($user);

        $this->postJson(route('transcribe.check'), ['seconds' => 12.43])->assertOk()
            ->assertJson(['seconds' => 12, 'left' => 7200, 'remain' => 7188, 'free' => false]);

        $bytes = (string) file_get_contents($this->audio(12.4));
        $start = $this->postJson(route('transcribe.upload.start'), ['name' => 'voice note.mp3', 'size' => strlen($bytes)])->assertCreated();
        $this->call('POST', $start->json('chunk_url'), [], [], [], ['HTTP_X_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)
            ->assertOk()->assertJson(['received' => strlen($bytes)]);
        $store = $this->postJson(route('transcribe.store'), ['upload_id' => $start->json('id'), 'language' => 'ar'])->assertStatus(202);
        $this->assertSame(12, $store->json('account.used_seconds'), 'the header shows the minutes used at once');

        $code = $store->json('transcription.id');
        $show = $this->getJson(route('transcribe.show', $code))->assertOk();
        $show->assertJsonPath('transcription.status', 'ready')
            ->assertJsonPath('transcription.title', 'voice note.mp3')
            ->assertJsonPath('transcription.used', '12 s')
            ->assertJsonPath('transcription.rtl', true)
            ->assertJsonPath('transcription.speakers', 2)
            ->assertJsonPath('transcription.speaker_list.0.name', 'Speaker A')
            ->assertJsonPath('account.left_seconds', 7188);
        $this->assertStringContainsString("Speaker A: مرحبا خديجة، شو صار؟\n\nSpeaker B: كل شي جاهز تقريباً.", $show->json('transcription.text'));
        $this->get(route('transcribe.audio', $code))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        $this->getJson(route('transcribe.index', ['q' => 'خديجة']))->assertOk()->assertJsonPath('items.0.id', $code);
        $this->getJson(route('transcribe.index', ['q' => 'nothing like it']))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_a_file_that_does_not_fit_is_refused_before_it_is_sent_naming_the_plan(): void
    {
        $this->plan('creator', 2200, 250, 60);
        $this->actingAs($this->member(120, 30));

        $this->postJson(route('transcribe.check'), ['seconds' => 45 * 60])->assertStatus(422)
            ->assertJsonPath('code', 'too_long')
            ->assertJsonPath('upgrade.slug', 'creator')
            ->assertJsonPath('message', 'This file is 45 min long and your plan takes files up to 30 min. The Creator plan takes it.');
    }

    public function test_correcting_naming_translating_and_downloading_use_no_minutes(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'بص بقى، أنا عايزة أقولك حاجة مهمة قوي. البرنامج ده هيوفر وقت.'], ['speaker' => 'B', 'text' => 'تمام.']]);
        $user = $this->member(120);
        $this->actingAs($user);
        $t = $this->transcribe($user, 9);
        $used = TranscriptionAccess::balance($user)['used'];

        $this->patchJson(route('transcribe.update', $t->code), ['title' => 'Interview', 'speakers' => ['A' => 'Mona', 'B' => 'Ahmed'], 'paragraph' => 0,
            'text' => 'بص بقى، أنا عايزة أقولك حاجة مهمة أوي. البرنامج ده هيوفر وقت.'])->assertOk()
            ->assertJsonPath('transcription.title', 'Interview')
            ->assertJsonPath('transcription.speaker_list.1.name', 'Ahmed')
            ->assertJsonPath('transcription.paragraphs.0.text', 'بص بقى، أنا عايزة أقولك حاجة مهمة أوي. البرنامج ده هيوفر وقت.');
        $segments = $t->fresh()->segments;
        $this->assertSame([0, 0, 1], array_column($segments, 'paragraph'), 'the corrected paragraph cut into its sentences again');

        $translator = \Mockery::mock(TranslationService::class);
        $translator->shouldReceive('prefetch')->once();
        $translator->shouldReceive('maybeTranslate')->andReturnUsing(fn (string $text) => 'EN: '.mb_substr($text, 0, 10));
        $this->app->instance(TranslationService::class, $translator);
        $this->postJson(route('transcribe.translate', $t->code), ['language' => 'en'])->assertOk()->assertJsonPath('paragraphs.1.text', 'EN: تمام.');

        $r = $this->get(route('transcribe.export', [$t->code, 'srt']));
        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 600));
        $srt = (string) $r->getContent();
        $this->assertStringStartsWith("1\n00:00:00,000 --> ", $srt);
        $this->assertStringContainsString("\u{200F}Mona: بص بقى،", $srt, 'right to left, and the speaker named where the speaker changes');
        $vtt = $this->get(route('transcribe.export', [$t->code, 'vtt']))->getContent();
        $this->assertStringStartsWith("WEBVTT\n\n00:00:00.000 --> ", $vtt);
        $english = $this->get(route('transcribe.export', [$t->code, 'txt']).'?lang=en')->assertHeader('Content-Disposition', 'attachment; filename="Interview-en.txt"; filename*=UTF-8\'\'Interview-en.txt')->getContent();
        $this->assertStringContainsString('Mona: EN:', $english);
        $docx = $this->get(route('transcribe.export', [$t->code, 'docx']))->assertOk()->getContent();
        $file = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $docx);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('<w:bidi/>', $xml);
        $this->assertStringContainsString('Ahmed: ', $xml);
        @unlink($file);

        $this->assertSame($used, TranscriptionAccess::balance($user)['used']);
    }

    public function test_a_deleted_transcript_stays_counted_and_others_cannot_see_one(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'Hello there.']]);
        $user = $this->member(120);
        $t = $this->transcribe($user, 6);

        $this->actingAs($this->member(120));
        $this->getJson(route('transcribe.show', $t->code))->assertNotFound();
        $this->get(route('transcribe.export', [$t->code, 'txt']))->assertNotFound();

        $this->actingAs($user);
        $this->deleteJson(route('transcribe.destroy', $t->code))->assertOk();
        $this->getJson(route('transcribe.show', $t->code))->assertNotFound();
        $this->assertSame(6, TranscriptionAccess::balance($user)['used']);
    }

    public function test_a_link_is_fetched_the_safe_way(): void
    {
        $user = $this->member(120);
        $this->actingAs($user);
        $bytes = (string) file_get_contents($this->audio(4));
        AudioLink::$resolver = fn (string $host) => $host === 'cdn.example.org' ? '93.184.216.34' : null;
        Http::fake([
            'cdn.example.org/*' => Http::response($bytes, 200, ['Content-Type' => 'audio/mpeg']),
            'generativelanguage.googleapis.com/*' => $this->answer([['speaker' => 'A', 'text' => 'From a link.']]),
        ]);

        $r = $this->postJson(route('transcribe.store'), ['url' => 'https://cdn.example.org/shows/episode%201.mp3']);
        $this->assertSame(202, $r->status(), (string) $r->getContent());
        $r
            ->assertJsonPath('transcription.title', 'episode 1.mp3')
            ->assertJsonPath('transcription.source', 'url');

        // Never this server's own network, nor a page that is not audio.
        $this->postJson(route('transcribe.store'), ['url' => 'http://127.0.0.1/secret.mp3'])->assertStatus(422)->assertJsonPath('code', 'bad_link');
        Http::fake(['cdn.example.org/*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
        $this->postJson(route('transcribe.store'), ['url' => 'https://cdn.example.org/page'])->assertStatus(422)->assertJsonPath('code', 'bad_link');
    }

    public function test_closed_to_people_it_is_not_open_to(): void
    {
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_PREVIEW, TranscriptionSettings::GROUP);
        $this->actingAs($this->member(120));
        $this->getJson(route('transcribe.index'))->assertNotFound();
        $this->postJson(route('transcribe.check'), ['seconds' => 10])->assertNotFound();
    }

    public function test_subtitle_cues_keep_to_two_short_lines(): void
    {
        $cues = TranscriptExport::cues(str_repeat('word ', 40), 0.0, 20.0);
        foreach ($cues as [$start, $end, $lines]) {
            $this->assertLessThanOrEqual(2, count($lines));
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(42, mb_strlen($line));
            }
        }
        $this->assertSame(20.0, round(end($cues)[1], 2));
        $this->assertSame('01:02:03,450', TranscriptExport::stamp(3723.45, false));
    }

    public function test_the_dashboard_shows_the_section_with_the_months_minutes_in_english_and_arabic(): void
    {
        $user = $this->member(120);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('id="transcribeSection"', false)
            ->assertSee('href="#transcribeSection"', false)
            ->assertSee(__('transcribe.page.drop_title'))
            ->assertSee(__('transcribe.page.stat_left'))
            ->assertSee('120 min')
            ->assertSee('css/transcribe.css', false)
            ->assertSee('js/transcribe.js', false);

        app()->setLocale('ar');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('transcribe.page.drop_title', [], 'ar'))
            ->assertSee('120 د');
        app()->setLocale('en');

        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_OFF, TranscriptionSettings::GROUP);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="transcribeSection"', false)->assertDontSee('js/transcribe.js', false);
    }

    public function test_a_member_without_it_sees_the_free_try_and_the_plan_that_has_it(): void
    {
        $this->plan('starter', 900, 120, 30);
        $this->actingAs($this->member(0));

        $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('transcribe.page.free_text', ['length' => '1 min']))
            ->assertSee(__('transcribe.page.get_plan', ['plan' => 'Starter']));
    }

    private function transcribe(User $user, float $seconds): Transcription
    {
        return app(TranscriptionFlow::class)->start($user, $this->audio($seconds), ['title' => 'clip.mp3', 'language' => 'ar'])->fresh();
    }

    private function member(int $minutes, int $fileMinutes = 30): User
    {
        $plan = $this->plan('member-'.bin2hex(random_bytes(3)), 900, $minutes, $fileMinutes);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);

        return $user;
    }

    private function plan(string $slug, int $price, int $minutes, int $fileMinutes): Plan
    {
        return Plan::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'price_cents' => $price, 'monthly_chars' => 100000, 'monthly_requests' => 100,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes, 'transcription_max_file_minutes' => $fileMinutes,
            'transcription_concurrent_jobs' => 1]);
    }

    private function audio(float $seconds): string
    {
        $file = sys_get_temp_dir().'/tr-page-'.bin2hex(random_bytes(4)).'.mp3';
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
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['language' => 'ar', 'speakers' => [],
            'paragraphs' => $paragraphs], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30]]);
    }
}
