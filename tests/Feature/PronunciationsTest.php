<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Pronunciation;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use App\Support\GeminiVocalTags;
use App\Support\Pronunciations;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * A member's pronunciations: saved in the studio's panel (and the demo's), and
 * every new voice says those words their way. Speech is always faked here: no
 * real audio is generated.
 */
class PronunciationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{text: string, options: array<string, mixed>}> */
    private array $spoken = [];

    // ──────────────────────────────────────────────────────────────────
    //  Finding the words
    // ──────────────────────────────────────────────────────────────────

    public function test_a_word_is_found_however_it_is_written(): void
    {
        $this->assertSame(Pronunciations::key('عِلْم'), Pronunciations::key('علم'));
        $this->assertSame(Pronunciations::key('أحمد'), Pronunciations::key('احمد'));
        $this->assertSame(Pronunciations::key('مستشفى'), Pronunciations::key('مستشفي'));
        $this->assertSame(Pronunciations::key('مدرسة'), Pronunciations::key('مدرسه'));
        $this->assertSame(Pronunciations::key('GIF'), Pronunciations::key(' gif '));

        $said = $this->apply('قال إحمد: العلم نور، وعِلم أحمد كبير.', [['احمد', 'أَحْمَد'], ['علم', 'عِلْم']]);
        $this->assertSame('قال أَحْمَد: العلم نور، وعِلم أَحْمَد كبير.', $said['text'], 'only whole words: «العلم» and «وعلم» are other words');
        $this->assertSame(['أَحْمَد'], $said['spoken']);
    }

    public function test_words_inside_tags_and_dialogue_names_stay_as_written(): void
    {
        $said = $this->apply("Sara: <laugh> Sara is here [calm] Sara\nOmar: hi Sara", [['Sara', 'Sahra'], ['calm', 'kalm']], ['Sara', 'Omar']);

        $this->assertSame("Sara: <laugh> Sahra is here [calm] Sahra\nOmar: hi Sahra", $said['text']);
    }

    public function test_the_longer_word_wins_and_nothing_is_replaced_twice(): void
    {
        $said = $this->apply('Listen AI and Listen.', [['Listen', 'Lissen'], ['Listen AI', 'Lissen A I'], ['Lissen', 'wrong']]);

        $this->assertSame('Lissen A I and Lissen.', $said['text']);
    }

    public function test_the_voice_is_told_to_keep_only_the_words_in_its_text(): void
    {
        $this->assertSame('', Pronunciations::direction('Nothing here', ['Lissen']));
        $this->assertSame(
            'Pronounce these words exactly as they are written, following every vowel mark and letter: «عِلْم».',
            Pronunciations::direction('هذا عِلْم نافع', ['عِلْم', 'لِسِن']),
        );
    }

    public function test_the_dialect_rewrite_cannot_respell_the_members_words(): void
    {
        $protected = GeminiVocalTags::protect('قال عِلْم <laugh> ثم عِلْم', ['عِلْم']);

        $this->assertSame(['عِلْم', '<laugh>', 'عِلْم'], $protected['tags'], 'locked in the order they appear');
        $this->assertTrue(GeminiVocalTags::placeholdersIntact($protected['text'], count($protected['tags'])));

        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        Http::fake(['https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'وش [[0]] الحين']]]]],
        ])]);

        $this->assertSame('وش عِلْم الحين', app(VoiceCloningService::class)->speakableText('إيه عِلْم دلوقتي', 'ar-SA', ['عِلْم']));
        Http::assertSent(fn ($request): bool => str_contains((string) json_encode($request->data(), JSON_UNESCAPED_UNICODE), '[[0]]')
            && ! str_contains((string) json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'عِلْم'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  The panel
    // ──────────────────────────────────────────────────────────────────

    public function test_a_member_saves_changes_and_deletes_their_words(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);

        $this->save(['word' => ' Listen ', 'spoken' => 'لِسِن'])->assertOk()
            ->assertJsonPath('items.0.word', 'Listen')->assertJsonPath('items.0.spoken', 'لِسِن');
        $id = (int) Pronunciation::query()->value('id');

        // The same word again changes it rather than adding a second one.
        $this->save(['word' => 'listen', 'spoken' => 'لِسْن'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.spoken', 'لِسْن');

        // Changed into a word already on the list: one entry for it, with the new pronunciation.
        $this->save(['word' => 'علم', 'spoken' => 'عِلْم'])->assertOk()->assertJsonCount(2, 'items');
        $this->save(['id' => $id, 'word' => 'عِلم', 'spoken' => 'عَلَم'])->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.word', 'عِلم')->assertJsonPath('items.0.spoken', 'عَلَم');

        $left = (int) Pronunciation::query()->value('id');
        $this->remove($left)->assertOk()->assertJsonCount(0, 'items');
        $this->assertSame(0, Pronunciation::query()->count());
    }

    public function test_only_words_are_saved(): void
    {
        $this->actingAs(User::factory()->create());

        $this->save(['word' => '', 'spoken' => 'x'])->assertStatus(422);
        $this->save(['word' => '123', 'spoken' => 'one two three'])->assertStatus(422)->assertJsonValidationErrors('word');
        $this->save(['word' => '<laugh>', 'spoken' => 'laugh'])->assertStatus(422);
        $this->save(['word' => 'one two three four', 'spoken' => 'four'])->assertStatus(422);
        $this->save(['word' => 'same', 'spoken' => 'same'])->assertStatus(422)->assertJsonValidationErrors('spoken');
        $this->save(['word' => str_repeat('a', Pronunciations::WORD_MAX + 1), 'spoken' => 'a'])->assertStatus(422);
        $this->assertSame(0, Pronunciation::query()->count());
    }

    public function test_the_list_has_a_limit(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);
        foreach (range(1, Pronunciations::LIMIT) as $n) {
            Pronunciation::query()->create(['user_id' => $member->id, 'word' => 'w'.$n, 'word_key' => 'w'.$n, 'spoken' => 's'.$n]);
        }

        $this->save(['word' => 'another', 'spoken' => 'anuther'])->assertStatus(422)->assertJsonValidationErrors('word');
        // A word already on the list can still be changed.
        $this->save(['word' => 'w1', 'spoken' => 'double you one'])->assertOk();
    }

    public function test_a_member_never_sees_or_changes_someone_elses_words(): void
    {
        $owner = User::factory()->create();
        $theirs = Pronunciation::query()->create(['user_id' => $owner->id, 'word' => 'Nguyen', 'word_key' => 'nguyen', 'spoken' => 'Win']);

        $this->actingAs(User::factory()->create());
        $this->remove($theirs->id)->assertOk()->assertJsonCount(0, 'items');
        $this->save(['id' => $theirs->id, 'word' => 'Nguyen', 'spoken' => 'New-yen'])->assertOk()->assertJsonCount(1, 'items');

        $this->assertSame('Win', $theirs->fresh()->spoken);
        $this->assertSame(2, Pronunciation::query()->count());
    }

    public function test_the_studio_and_the_demo_show_the_members_words(): void
    {
        Plan::factory()->free()->create(['slug' => 'free']);
        $member = User::factory()->create();
        Pronunciation::query()->create(['user_id' => $member->id, 'word' => 'Nguyen', 'word_key' => 'nguyen', 'spoken' => 'Win']);

        foreach (['dashboard', 'demo.page'] as $page) {
            $html = $this->actingAs($member)->getLocalizedRoute($page)->assertOk()->getContent();
            $this->assertStringContainsString('"pron":{"items":[{"id":', $html, $page);
            $this->assertStringContainsString('"spoken":"Win"', $html, $page);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    //  Every new voice
    // ──────────────────────────────────────────────────────────────────

    public function test_the_studio_voice_reads_the_members_words_but_charges_and_keeps_what_they_wrote(): void
    {
        $this->seedFreePlan();
        $this->fakeSpeech();
        $member = User::factory()->create();
        Pronunciation::query()->create(['user_id' => $member->id, 'word' => 'Nguyen', 'word_key' => 'nguyen', 'spoken' => 'Win']);

        $generation = AudioGeneration::create([
            'user_id' => $member->id, 'input_text' => 'Meet Dr. Nguyen today!!', 'source_language' => 'en',
            'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C',
            'translate' => false, 'status' => 'queued',
        ]);
        GenerateAudioJob::dispatchSync($generation->id);

        $this->assertSame('Meet Dr Win today!', $this->spoken[0]['text']);
        $this->assertSame(['Win'], $this->spoken[0]['options']['pronounce']);
        $generation->refresh();
        $this->assertSame('done', $generation->status);
        $this->assertSame('Meet Dr. Nguyen today!!', $generation->input_text);
        $this->assertSame(UsageEstimator::estimate('Meet Dr. Nguyen today!!', 'standard')['chars'], (int) $generation->billable_chars);
        $this->assertSame('Meet Dr Win today!', $generation->settings_snapshot['spoken_text'] ?? null);
    }

    public function test_a_saved_word_is_used_by_the_next_voice_without_reloading_the_page(): void
    {
        Storage::fake('public');
        $this->fakeSpeech();
        $this->seedFreePlan();
        $member = User::factory()->create();
        $voice = Voice::query()->create([
            'internal_voice_id' => 'en-US-Wavenet-C', 'language' => 'en', 'gender' => 'female', 'tier' => 'standard',
            'display_name' => 'Emma', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
        $this->actingAs($member);
        // Generate, as the studio's button sends it: the words are not in the request, the server reads them.
        $generate = fn () => $this->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Meet Nguyen today.',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ])->assertOk()->assertJsonPath('ok', true);

        $generate();
        $this->save(['word' => 'Nguyen', 'spoken' => 'Win'])->assertOk();
        $generate();
        $id = (int) Pronunciation::query()->value('id');
        $this->save(['id' => $id, 'word' => 'Nguyen', 'spoken' => 'New-yen'])->assertOk();
        $generate();
        $this->remove($id)->assertOk();
        $generate();

        $this->assertSame(
            ['Meet Nguyen today.', 'Meet Win today.', 'Meet New-yen today.', 'Meet Nguyen today.'],
            array_column($this->spoken, 'text'),
        );
    }

    public function test_the_demo_voice_reads_the_members_words_too(): void
    {
        Storage::fake('public');
        $this->seedFreePlan();
        $this->fakeSpeech();
        $voice = Voice::query()->create([
            'internal_voice_id' => 'en-US-Wavenet-C', 'language' => 'en', 'gender' => 'female', 'tier' => 'standard',
            'display_name' => 'Emma', 'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
        $member = User::factory()->create(['registration_ip' => '203.0.113.90']);
        Pronunciation::query()->create(['user_id' => $member->id, 'word' => 'Nguyen', 'word_key' => 'nguyen', 'spoken' => 'Win']);

        $this->actingAs($member)->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello Nguyen, e.g. now.',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('Hello Win, for example now.', $this->spoken[0]['text']);
        $this->assertSame(['Win'], $this->spoken[0]['options']['pronounce']);
    }

    public function test_the_premium_voice_is_told_to_say_the_words_as_written(): void
    {
        Storage::fake('public');
        config(['services.gemini_tts.dialect_rewrite' => false]);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
        $pcm = pack('v*', 0, 1200, -1200, 0);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        $bodies = [];
        Http::fake(function ($request) use ($wav, &$bodies) {
            $bodies[] = (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('هذا عِلْم نافع', 'ar', ['voice_name' => 'Kore', 'pronounce' => ['عِلْم']]);
        app(GeminiTtsProvider::class)->synthesize("Sara: Hi Win\nOmar: Hello", 'en', [
            'speakers' => [['name' => 'Sara', 'voice_name' => 'Kore'], ['name' => 'Omar', 'voice_name' => 'Puck']],
            'pronounce' => ['Win'],
        ]);

        $this->assertStringContainsString('Pronounce these words exactly as they are written, following every vowel mark and letter: «عِلْم».', $bodies[0]);
        $this->assertStringContainsString('following every vowel mark and letter: «Win».', $bodies[1]);
    }

    public function test_the_shared_audio_cache_never_mixes_two_members_pronunciations(): void
    {
        $key = new \ReflectionMethod(TtsService::class, 'buildAdvancedCacheKey');
        $service = app(TtsService::class);
        $config = ['voice_name' => 'Kore', 'tier' => 'hd'];

        $plain = $key->invoke($service, 'Hello Win', 'en', $config, 'gemini');
        $mine = $key->invoke($service, 'Hello Win', 'en', $config + ['pronounce' => ['Win']], 'gemini');

        $this->assertNotSame($plain, $mine);
        $this->assertSame($mine, $key->invoke($service, 'Hello Win', 'en', $config + ['pronounce' => ['Win']], 'gemini'));
    }

    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  list<array{0: string, 1: string}>  $entries
     * @param  list<string>  $labels
     * @return array{text: string, spoken: list<string>}
     */
    private function apply(string $text, array $entries, array $labels = []): array
    {
        return Pronunciations::applyEntries($text, array_map(fn (array $e): array => ['word' => $e[0], 'spoken' => $e[1]], $entries), $labels);
    }

    /** @param  array<string, mixed>  $data */
    private function save(array $data): TestResponse
    {
        return $this->postJsonLocalizedRoute('dashboard.pronunciations.save', $data);
    }

    private function remove(int $id): TestResponse
    {
        return $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->deleteJson($this->localizedRoute('dashboard.pronunciations.save').'/'.$id);
    }

    private function fakeSpeech(): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturnCallback(function (string $text, string $language, array $options): array {
            $this->spoken[] = ['text' => $text, 'options' => $options];
            Storage::disk('public')->put('audio/said.mp3', 'audio');

            return ['audio_path' => 'audio/said.mp3', 'duration_seconds' => 2, 'provider' => 'google_cloud', 'model' => 'google-cloud-tts'];
        });
        $this->app->instance(TtsService::class, $tts);
    }

    private function seedFreePlan(): void
    {
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false,
            'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
    }
}
