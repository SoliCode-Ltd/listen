<?php

namespace Tests\Feature;

use App\Filament\Pages\TtsSettings;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\TtsService;
use App\Support\Tashkeel\AutoReadings;
use App\Support\UsageEstimator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The automatic pronunciation step on the site: off until switched on, for the
 * test accounts first, only within its first scope, and never able to stop a
 * voice or change a member's text. Speech and the text model are always faked
 * here: no real request is made.
 */
class AutoReadingsTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'رفع الجنود علم بلادهم في جدة.';

    private const MARKED = 'رفع الجنود عَلَم بلادهم في جِدَّة.';

    /** @var list<array<string, mixed>> the requests sent to the text model */
    private array $asked = [];

    /** @var list<string> what the voice was sent, as JSON */
    private array $voiced = [];

    public function test_it_is_off_until_switched_on_and_test_accounts_come_first(): void
    {
        $this->assertSame(AutoReadings::OFF, AutoReadings::mode());
        $this->assertFalse(AutoReadings::allowsMember(8215));

        SiteSetting::set(AutoReadings::SETTING, AutoReadings::TEST);
        SiteSetting::set(AutoReadings::MEMBERS_SETTING, '8215, 12');
        $this->assertSame([8215, 12], AutoReadings::testMembers());
        $this->assertTrue(AutoReadings::allowsMember(8215));
        $this->assertFalse(AutoReadings::allowsMember(7));
        $this->assertFalse(AutoReadings::allowsMember(null));

        SiteSetting::set(AutoReadings::SETTING, AutoReadings::ON);
        $this->assertTrue(AutoReadings::allowsMember(7));

        // Anything else saved there is off.
        SiteSetting::set(AutoReadings::SETTING, 'yes');
        $this->assertFalse(AutoReadings::allowsMember(8215));
    }

    public function test_only_a_fusha_voice_and_a_text_read_in_one_piece_without_tags_are_in_scope(): void
    {
        $this->assertTrue(AutoReadings::fits(self::TEXT, null, 900));
        $this->assertTrue(AutoReadings::fits(self::TEXT, 'ar-XA', 900));
        // A voice set to a dialect, a text longer than one piece, one with a tag, one with no Arabic.
        $this->assertFalse(AutoReadings::fits(self::TEXT, 'ar-EG', 900));
        $this->assertFalse(AutoReadings::fits(self::TEXT, null, 10));
        $this->assertFalse(AutoReadings::fits('[calm] '.self::TEXT, null, 900));
        $this->assertFalse(AutoReadings::fits(self::TEXT.' <laugh>', null, 900));
        $this->assertFalse(AutoReadings::fits('Hello there', null, 900));
    }

    public function test_the_words_are_marked_from_three_seeded_answers_and_the_result_is_kept_for_the_next_take(): void
    {
        $this->fakeGemini();

        $made = AutoReadings::for(self::TEXT);

        $this->assertSame(self::MARKED, $made['text']);
        $this->assertStringContainsString('the word written «علم» means "a flag" and is said "ʿalam"', $made['direction']);
        $this->assertSame(['words' => 2, 'written_in' => 'fusha', 'reused' => false, 'answers' => 3], array_diff_key($made['info'], ['ms' => true]));
        $this->assertCount(3, $this->asked);
        $this->assertSame([101, 102, 103], array_column(array_column($this->asked, 'generationConfig'), 'seed'));
        $this->assertStringContainsString('«'.self::TEXT.'»', $this->asked[0]['contents'][0]['parts'][0]['text']);

        // The same text again: nothing is asked, and the same words come back.
        $again = AutoReadings::for(self::TEXT);
        $this->assertCount(3, $this->asked);
        $this->assertSame(self::MARKED, $again['text']);
        $this->assertTrue($again['info']['reused']);
        // A word the member spells their own way makes it another text, and that word is left alone.
        $kept = AutoReadings::for(self::TEXT, ['علم']);
        $this->assertCount(6, $this->asked);
        $this->assertSame('رفع الجنود علم بلادهم في جِدَّة.', $kept['text']);
    }

    public function test_a_dialect_text_keeps_names_only_though_nobody_said_it_was_one(): void
    {
        $this->fakeGemini('egyptian');

        $made = AutoReadings::for(self::TEXT);

        $this->assertSame('رفع الجنود علم بلادهم في جِدَّة.', $made['text']);
        $this->assertSame('dialect', $made['info']['written_in']);
    }

    public function test_with_fewer_than_two_answers_the_text_is_read_as_written_and_nothing_is_kept(): void
    {
        $this->fakeGemini('fusha', failing: 2);

        $made = AutoReadings::for(self::TEXT);

        $this->assertSame(['text' => self::TEXT, 'direction' => ''], array_diff_key($made, ['info' => true]));
        $this->assertSame(1, $made['info']['answers']);
        // Not kept: the next take asks again.
        AutoReadings::for(self::TEXT);
        $this->assertCount(6, $this->asked);
    }

    public function test_a_failure_of_the_text_model_never_stops_the_voice(): void
    {
        $this->app->instance(GeminiKeyManager::class, \Mockery::mock(GeminiKeyManager::class, function ($mock): void {
            $mock->shouldReceive('getAvailableKey')->andThrow(new \RuntimeException('All keys are used up.'));
        }));

        $made = AutoReadings::for(self::TEXT);

        $this->assertSame(self::TEXT, $made['text']);
        $this->assertSame('', $made['direction']);
    }

    public function test_the_premium_voice_reads_the_marked_text_and_is_told_what_the_words_mean(): void
    {
        $this->fakeGemini();
        config(['services.gemini_tts.dialect_rewrite' => false]);

        $result = app(GeminiTtsProvider::class)->synthesize(self::TEXT, 'ar', ['voice_name' => 'Kore', 'auto_readings' => true]);

        $this->assertCount(3, $this->asked);
        $this->assertCount(1, $this->voiced);
        $this->assertStringContainsString(self::MARKED, $this->voiced[0]);
        $this->assertStringContainsString('the word written «جدة» means', $this->voiced[0]);
        $this->assertSame(self::MARKED, $result['auto_readings']['text']);
        $this->assertSame(2, $result['auto_readings']['words']);
    }

    public function test_outside_its_scope_or_switched_off_the_voice_reads_the_text_as_written(): void
    {
        $this->fakeGemini();
        config(['services.gemini_tts.dialect_rewrite' => false]);
        $provider = app(GeminiTtsProvider::class);

        // Not switched on for this member; a voice set to a dialect; a text with a delivery tag; English.
        $provider->synthesize(self::TEXT, 'ar', ['voice_name' => 'Kore']);
        $provider->synthesize(self::TEXT, 'ar', ['voice_name' => 'Kore', 'dialect' => 'ar-EG', 'auto_readings' => true]);
        $provider->synthesize('[calm] '.self::TEXT, 'ar', ['voice_name' => 'Kore', 'auto_readings' => true]);
        $result = $provider->synthesize('The flag was raised.', 'en', ['voice_name' => 'Kore', 'auto_readings' => true]);

        $this->assertSame([], $this->asked);
        $this->assertCount(4, $this->voiced);
        $this->assertStringNotContainsString('عَلَم', implode(' ', $this->voiced));
        $this->assertArrayNotHasKey('auto_readings', $result);
    }

    public function test_the_studio_switches_it_on_for_test_accounts_only_and_keeps_what_the_member_wrote(): void
    {
        Storage::fake('public');
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false,
            'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);
        $options = [];
        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function (string $text, string $language, array $given) use (&$options): array {
            $options[] = $given;
            Storage::disk('public')->put('audio/said.mp3', 'audio');

            return ['audio_path' => 'audio/said.mp3', 'duration_seconds' => 2, 'provider' => 'gemini', 'model' => 'x']
                + (($given['auto_readings'] ?? false) ? ['auto_readings' => ['words' => 2, 'written_in' => 'fusha', 'reused' => false, 'ms' => 2900, 'answers' => 3, 'text' => self::MARKED]] : []);
        });
        $this->app->instance(TtsService::class, $tts);
        $tester = User::factory()->create();
        $other = User::factory()->create();
        SiteSetting::set(AutoReadings::SETTING, AutoReadings::TEST);
        SiteSetting::set(AutoReadings::MEMBERS_SETTING, (string) $tester->id);

        $made = [];
        foreach ([$tester, $other] as $member) {
            $generation = AudioGeneration::create([
                'user_id' => $member->id, 'input_text' => self::TEXT, 'source_language' => 'ar', 'target_language' => 'ar',
                'voice_tier' => 'standard', 'voice_name' => 'ar-XA-Wavenet-A', 'translate' => false, 'status' => 'queued',
            ]);
            GenerateAudioJob::dispatchSync($generation->id);
            $made[] = $generation->refresh();
        }

        $this->assertTrue($options[0]['auto_readings']);
        $this->assertFalse($options[1]['auto_readings']);
        // The tester's voice: what it read is kept for support; the text and the charge are the member's own.
        $this->assertSame('done', $made[0]->status);
        $this->assertSame(self::TEXT, $made[0]->input_text);
        $this->assertSame(self::MARKED, $made[0]->settings_snapshot['spoken_text']);
        $this->assertSame(['words' => 2, 'written_in' => 'fusha', 'reused' => false, 'ms' => 2900, 'answers' => 3], $made[0]->settings_snapshot['auto_readings']);
        $this->assertSame(UsageEstimator::estimate(self::TEXT, 'standard')['chars'], (int) $made[0]->billable_chars);
        $this->assertSame((int) $made[0]->billable_chars, (int) $made[1]->billable_chars);
        $this->assertArrayNotHasKey('auto_readings', (array) $made[1]->settings_snapshot);
    }

    public function test_the_admin_sets_the_switch_and_the_test_accounts_in_tts_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TtsSettings::class)
            ->assertFormSet([AutoReadings::SETTING => AutoReadings::OFF])
            ->fillForm([AutoReadings::SETTING => AutoReadings::TEST, AutoReadings::MEMBERS_SETTING => '8215'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AutoReadings::TEST, AutoReadings::mode());
        $this->assertTrue(AutoReadings::allowsMember(8215));
        $this->assertFalse(AutoReadings::allowsMember(1));
    }

    /**
     * The text model answers every ask with the two words of TEXT (the last $failing asks fail);
     * the voice answers with a short sound. Requests are kept to look at.
     */
    private function fakeGemini(string $writtenIn = 'fusha', int $failing = 0): void
    {
        Storage::fake('public');
        Cache::flush();
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
        config(['services.gemini_tts.voice_retry_pause' => false]);
        $answer = json_encode(['written_in' => $writtenIn, 'words' => [
            ['word' => 'علم', 'marked' => 'عَلَم', 'said' => 'ʿalam', 'means' => 'a flag', 'kind' => 'word'],
            ['word' => 'جدة', 'marked' => 'جِدَّة', 'said' => 'Jidda', 'means' => 'the city of Jeddah', 'kind' => 'name'],
        ]], JSON_UNESCAPED_UNICODE);
        $pcm = pack('v*', 0, 1200, -1200, 0);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake(function ($request) use ($answer, $wav, $failing) {
            if (str_contains($request->url(), '/models/gemini-3.8-flash:generateContent')) {
                $this->asked[] = $request->data();
                $seed = (int) ($request->data()['generationConfig']['seed'] ?? 0);

                return $seed > 103 - $failing
                    ? Http::response('', 500)
                    : Http::response(['candidates' => [['content' => ['parts' => [['text' => $answer]]]]]]);
            }
            $this->voiced[] = (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });
    }
}
