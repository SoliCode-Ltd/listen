<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsRoutingService;
use App\Support\GeminiTtsModel;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dialogue mode, end to end from the studio: a plan with dialogue switched on
 * sends two speakers, and the speech request uses both voices.
 */
class DialogueModeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{url:string, body:array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(\Database\Seeders\VoiceSeeder::class);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        Http::fake(function ($request) {
            $this->requests[] = ['url' => $request->url(), 'body' => $request->data()];

            return Http::response(['candidates' => [['content' => ['parts' => [[
                'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, 2400, 1200, 0, -1200))],
            ]]]]]]);
        });
    }

    public function test_a_dialogue_from_the_studio_is_spoken_by_both_voices(): void
    {
        $this->actingAs($this->member(dialogue: true));
        [$a, $b] = $this->twoPremiumVoices('ar');

        $this->postJsonLocalizedRoute('dashboard.generate', $this->dialogueRequest($a['key'], $b['key']))
            ->assertSuccessful();

        $speech = collect($this->requests)->first(fn ($r) => str_contains($r['url'], ':generateContent') && isset($r['body']['generationConfig']['speechConfig']));
        $this->assertNotNull($speech, 'a speech request was sent');
        $cast = $speech['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'] ?? [];
        $this->assertSame(['سلطان', 'نورة'], array_column($cast, 'speaker'));
        $this->assertCount(2, array_unique(array_map(fn ($s) => $s['voiceConfig']['prebuiltVoiceConfig']['voiceName'], $cast)));

        // Each line goes to its own speaker, without the names read aloud.
        $parts = $speech['body']['contents'][0]['parts'];
        $this->assertSame(['سلطان', 'نورة'], array_map(fn ($p) => $p['speech_metadata']['speaker'], $parts));
        $this->assertStringNotContainsString('سلطان:', $parts[0]['text']);

        $generation = \App\Models\AudioGeneration::latest('id')->first();
        $this->assertTrue((bool) $generation->is_dialogue);
        $this->assertCount(2, $generation->dialogue_metadata['speakers']);
    }

    public function test_each_speaker_can_speak_their_own_dialect(): void
    {
        $this->actingAs($this->member(dialogue: true));
        [$a, $b] = $this->twoPremiumVoices('ar');
        $request = $this->dialogueRequest($a['key'], $b['key']);
        $request['dialogue_speakers'][0]['dialect'] = 'ar-SA';
        $request['dialogue_speakers'][1]['dialect'] = 'ar-EG';

        $this->postJsonLocalizedRoute('dashboard.generate', $request)->assertSuccessful();

        $parts = $this->speechParts();
        $this->assertSame(['سلطان', 'نورة'], array_map(fn ($p) => $p['speech_metadata']['speaker'], $parts));
        $saudi = $parts[0]['speech_metadata']['style'];
        $egyptian = $parts[1]['speech_metadata']['style'];
        $this->assertNotSame($saudi, $egyptian);
        $this->assertStringContainsString('Cairene Egyptian', $egyptian);
        $this->assertStringNotContainsString('Cairene', $saudi);
        $this->assertStringContainsString('Voice dialect:', $saudi);
        $this->assertSame('ar-EG', \App\Models\AudioGeneration::latest('id')->first()->dialogue_metadata['speakers'][1]['dialect']);

        // The same dialogue with a different dialect is a new recording, not the saved one.
        $this->requests = [];
        $request['dialogue_speakers'][1]['dialect'] = 'ar-LB';
        $this->postJsonLocalizedRoute('dashboard.generate', $request)->assertSuccessful();
        $this->assertNotEmpty($this->speechParts(), 'a new speech request was sent');
        $this->assertStringNotContainsString('Cairene', $this->speechParts()[1]['speech_metadata']['style']);
    }

    public function test_english_speakers_can_have_different_accents(): void
    {
        $this->actingAs($this->member(dialogue: true));
        [$a, $b] = $this->twoPremiumVoices('en');
        $request = [
            'content' => "Daisy: Welcome back to the show!\nTheodore: Delighted to be here.",
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'voice_key' => $a['key'],
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
            'is_dialogue' => true,
            'dialogue_speakers' => [
                ['name' => 'Daisy', 'voice' => $a['key'], 'dialect' => 'en-US'],
                ['name' => 'Theodore', 'voice' => $b['key'], 'dialect' => 'en-GB'],
            ],
        ];

        $this->postJsonLocalizedRoute('dashboard.generate', $request)->assertSuccessful();

        $parts = $this->speechParts();
        $this->assertStringContainsString('American English', $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString('British English', $parts[1]['speech_metadata']['style']);
    }

    public function test_a_made_up_dialect_value_is_rejected(): void
    {
        $this->actingAs($this->member(dialogue: true));
        [$a, $b] = $this->twoPremiumVoices('ar');
        $request = $this->dialogueRequest($a['key'], $b['key']);
        $request['dialogue_speakers'][0]['dialect'] = 'saudi; drop';

        $this->postJsonLocalizedRoute('dashboard.generate', $request)
            ->assertStatus(422)->assertJsonValidationErrors(['dialogue_speakers.0.dialect']);
    }

    public function test_dialogue_needs_two_speakers_and_named_lines(): void
    {
        $this->actingAs($this->member(dialogue: true));
        [$a, $b] = $this->twoPremiumVoices('ar');

        $oneSpeaker = $this->dialogueRequest($a['key'], $b['key']);
        $oneSpeaker['dialogue_speakers'] = [$oneSpeaker['dialogue_speakers'][0]];
        $this->postJsonLocalizedRoute('dashboard.generate', $oneSpeaker)
            ->assertStatus(422)->assertJsonValidationErrors(['dialogue_speakers']);

        $sameName = $this->dialogueRequest($a['key'], $b['key']);
        $sameName['dialogue_speakers'][1]['name'] = 'سلطان';
        $this->postJsonLocalizedRoute('dashboard.generate', $sameName)
            ->assertStatus(422)->assertJsonValidationErrors(['dialogue_speakers']);

        $unnamed = $this->dialogueRequest($a['key'], $b['key']);
        $unnamed['content'] = "مرحبا يا جماعة\nأهلاً وسهلاً";
        $this->postJsonLocalizedRoute('dashboard.generate', $unnamed)
            ->assertStatus(422)->assertJsonValidationErrors(['content']);

        $this->assertSame([], $this->requests, 'nothing was sent for speech');
    }

    /**
     * A dialogue used to be made in one request, so the studio stopped at 2,400
     * characters (very long ones came back empty). A long dialogue is now made in
     * pieces split at speaker turns, so the studio takes one up to the plan's
     * per-request limit, which still applies before anything is reserved.
     */
    public function test_the_studio_makes_a_dialogue_longer_than_2400_characters_in_pieces(): void
    {
        $member = $this->member(dialogue: true);
        $this->actingAs($member);
        [$a, $b] = $this->twoPremiumVoices('ar');

        $long = $this->dialogueRequest($a['key'], $b['key']);
        $long['content'] = $this->scriptOf(3000);
        $this->postJsonLocalizedRoute('dashboard.generate', $long)->assertSuccessful();

        $this->assertSame('done', \App\Models\AudioGeneration::latest('id')->first()->status);
        $this->assertMadeInPiecesWithBothVoices($long['content']);

        // Over the plan's own limit (10,000 characters a request) it is still refused, unspent.
        $this->requests = [];
        $tooLong = $this->dialogueRequest($a['key'], $b['key']);
        $tooLong['content'] = $this->scriptOf(10001);
        $this->postJsonLocalizedRoute('dashboard.generate', $tooLong)
            ->assertStatus(422)
            ->assertJsonPath('errors.content.0', __('ui.backend.dashboard_request_limit', ['max' => 10000]));
        $this->assertSame([], $this->requests, 'nothing was sent for speech');
        $this->assertSame(1, \App\Models\AudioGeneration::query()->count());
    }

    public function test_the_extension_makes_a_dialogue_longer_than_2400_characters_too(): void
    {
        $member = $this->member(dialogue: true);
        $key = $member->issueApiKey();
        [$a, $b] = $this->twoPremiumVoices('ar');
        $request = $this->dialogueRequest($a['key'], $b['key']);
        $request['content'] = $this->scriptOf(3000);

        $this->postJson('/api/reader/queue', $request, ['X-Listen-Key' => $key])->assertSuccessful();

        $this->assertSame('done', \App\Models\AudioGeneration::latest('id')->first()->status);
        $this->assertMadeInPiecesWithBothVoices($request['content']);
    }

    public function test_the_demo_makes_a_dialogue_longer_than_2400_characters_within_its_allowance(): void
    {
        $this->freePlan();
        SiteSetting::set(\App\Support\DemoAllowance::SETTING_KEY, '5000', 'demo');
        $this->actingAs(User::factory()->create());
        [$a, $b] = $this->twoPremiumVoices('ar');

        $long = $this->demoRequest($a['key'], $b['key']);
        $long['text'] = $this->scriptOf(2600);
        $this->postJsonLocalizedRoute('demo.preview', $long)->assertOk()->assertJson(['ok' => true]);

        $this->assertMadeInPiecesWithBothVoices($long['text']);
        $this->assertSame(1, \App\Models\DemoVoiceGeneration::count());
    }

    /**
     * The developer API keeps its own documented dialogue limit.
     */
    public function test_the_developer_apis_dialogue_limit_is_unchanged(): void
    {
        $plan = Plan::factory()->create(['max_chars_per_request' => 10000]);

        $this->assertSame(2500, \App\Services\Api\ApiSpeechService::DIALOGUE_MAX_TEXT);
        $this->assertSame(2500, \App\Services\Api\ApiSpeechService::maxCharsFor($plan, false, true));
        $this->assertSame(2500, \App\Services\Api\ApiSpeechService::maxCharsFor($plan, true, true));
    }

    public function test_plans_without_dialogue_ignore_the_speakers_and_hide_the_panel(): void
    {
        $member = $this->member(dialogue: false);
        $this->actingAs($member);
        [$a, $b] = $this->twoPremiumVoices('ar');

        $this->postJsonLocalizedRoute('dashboard.generate', $this->dialogueRequest($a['key'], $b['key']))
            ->assertSuccessful();
        $this->assertFalse((bool) \App\Models\AudioGeneration::latest('id')->first()->is_dialogue);

        app()->setLocale('ar');
        $this->getLocalizedRoute('dashboard')->assertOk()->assertDontSee('id="dialogueSpeakers"', false);
    }

    public function test_the_studio_shows_the_speaker_panel_when_the_plan_has_dialogue(): void
    {
        $this->actingAs($this->member(dialogue: true));
        app()->setLocale('ar');

        $this->getLocalizedRoute('dashboard')->assertOk()
            ->assertSee('id="dialogueSpeakers"', false)
            ->assertSee('name="dialogue_speakers[0][name]"', false)
            ->assertSee('name="dialogue_speakers[1][voice]"', false)
            ->assertSee('name="dialogue_speakers[1][dialect]"', false)
            ->assertSee(__('ui.dashboard.dialogue_example_btn'));
    }

    public function test_the_demo_speaks_a_dialogue_with_each_speakers_own_dialect(): void
    {
        $this->freePlan();
        $this->actingAs(User::factory()->create());
        [$a, $b] = $this->twoPremiumVoices('ar');
        $request = $this->demoRequest($a['key'], $b['key']);
        $request['dialogue_speakers'][0]['dialect'] = 'ar-SA';
        $request['dialogue_speakers'][1]['dialect'] = 'ar-EG';

        $this->postJsonLocalizedRoute('demo.preview', $request)->assertOk()->assertJson(['ok' => true]);

        $parts = $this->speechParts();
        $this->assertSame(['سلطان', 'نورة'], array_map(fn ($p) => $p['speech_metadata']['speaker'], $parts));
        $this->assertStringContainsString('Cairene Egyptian', $parts[1]['speech_metadata']['style']);
        $this->assertStringNotContainsString('Cairene', $parts[0]['speech_metadata']['style']);

        $stored = \App\Models\DemoVoiceGeneration::latest('id')->first();
        $this->assertSame('ar-EG', $stored->metadata['dialogue_speakers'][1]['dialect']);
    }

    public function test_the_demo_checks_a_dialogue_before_using_the_allowance(): void
    {
        $this->freePlan();
        $this->actingAs(User::factory()->create());
        [$a, $b] = $this->twoPremiumVoices('ar');

        $standard = $this->demoRequest($a['key'], $b['key']);
        $standard['voice_tier'] = 'standard';
        unset($standard['performance_style'], $standard['emotion']);
        $this->postJsonLocalizedRoute('demo.run', $standard)
            ->assertStatus(422)->assertJsonPath('errors.is_dialogue.0', __('ui.backend.dialogue_needs_hd'));

        $unnamed = $this->demoRequest($a['key'], $b['key']);
        $unnamed['text'] = "مرحبا يا جماعة\nأهلاً وسهلاً";
        $this->postJsonLocalizedRoute('demo.preview', $unnamed)
            ->assertStatus(422)->assertJsonValidationErrors(['text']);

        $this->assertSame([], $this->requests, 'nothing was sent for speech');
        $this->assertSame(0, \App\Models\DemoVoiceGeneration::count());
    }

    public function test_the_demo_page_has_the_dialogue_panel(): void
    {
        $this->freePlan();
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        $this->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('id="dialogueSpeakers"', false)
            ->assertSee('name="dialogue_speakers[1][dialect]"', false)
            ->assertSee('js/dialogue-mode.js', false);
    }

    /**
     * The script went in more than one request, split at its turns: every line once
     * and in order, each piece with both speakers and the same voices.
     */
    private function assertMadeInPiecesWithBothVoices(string $script): void
    {
        $speech = collect($this->requests)->filter(fn ($r) => isset($r['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']))->values();
        $this->assertGreaterThan(1, $speech->count(), 'made in pieces');
        $casts = $speech->map(fn ($r) => array_map(
            fn ($c) => $c['speaker'].'='.$c['voiceConfig']['prebuiltVoiceConfig']['voiceName'],
            $r['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'],
        ))->unique(fn ($cast) => implode('|', $cast));
        $this->assertCount(1, $casts, 'the same speakers and voices in every piece');
        $this->assertCount(2, $casts->first());

        $expected = [];
        $speaker = 'سلطان';
        foreach (preg_split("/\r\n|\n|\r/", trim($script)) ?: [] as $line) {
            foreach (['سلطان', 'نورة'] as $name) {
                if (str_starts_with($line, $name.':')) {
                    [$speaker, $line] = [$name, mb_substr($line, mb_strlen($name) + 1)];
                    break;
                }
            }
            $expected[] = $speaker.': '.trim($line);
        }
        $spoken = [];
        foreach ($speech as $r) {
            foreach ($r['body']['contents'][0]['parts'] as $part) {
                $spoken[] = $part['speech_metadata']['speaker'].': '.$part['text'];
            }
        }
        $this->assertSame($expected, $spoken);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function speechParts(): array
    {
        $speech = collect($this->requests)->first(fn ($r) => isset($r['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']));

        return $speech['body']['contents'][0]['parts'] ?? [];
    }

    /**
     * A two-speaker script of exactly $chars characters, every line named.
     */
    private function scriptOf(int $chars): string
    {
        $lines = [];
        for ($i = 1; mb_strlen(implode("\n", $lines)) < $chars; $i++) {
            $lines[] = ($i % 2 === 1 ? 'سلطان' : 'نورة').': هذا هو السطر رقم '.$i.' من حوارنا الطويل اليوم.';
        }
        // Cut to length, never on a space (the text is trimmed before it is counted).
        $script = mb_substr(implode("\n", $lines), 0, $chars - 1).'.';
        $this->assertSame($chars, mb_strlen(trim($script)));

        return $script;
    }

    private function member(bool $dialogue): User
    {
        $plan = Plan::factory()->create([
            'slug' => $dialogue ? 'studio-max-test' : 'studio-test',
            'price_cents' => 2999,
            'voice_tier' => 'hd',
            'tts_provider_policy' => 'auto',
            'dialogue_enabled' => $dialogue,
            'monthly_chars' => 500000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 10000,
        ]);
        $user = User::factory()->create();
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function twoPremiumVoices(string $language): array
    {
        $voices = array_values(array_filter(VoiceCatalog::publicCatalog()[$language], fn ($v) => ($v['tier'] ?? '') === 'hd'));
        $male = collect($voices)->firstWhere('gender', 'male');
        $female = collect($voices)->firstWhere('gender', 'female');

        return [$male, $female];
    }

    private function freePlan(): Plan
    {
        return Plan::factory()->create([
            'slug' => 'free',
            'price_cents' => 0,
            'voice_tier' => 'standard',
            'tts_provider_policy' => 'auto',
            'dialogue_enabled' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoRequest(string $voiceA, string $voiceB): array
    {
        $request = $this->dialogueRequest($voiceA, $voiceB);
        $request['text'] = $request['content'];
        unset($request['content']);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function dialogueRequest(string $voiceA, string $voiceB): array
    {
        return [
            'content' => "سلطان: أهلاً فيكم في حلقة جديدة!\nنورة: أهلاً سلطان، جاهزة نبدأ.",
            'target_language' => 'ar',
            'translate' => false,
            'voice_tier' => 'hd',
            'voice_key' => $voiceA,
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
            'is_dialogue' => true,
            'dialogue_speakers' => [
                ['name' => 'سلطان', 'voice' => $voiceA],
                ['name' => 'نورة', 'voice' => $voiceB],
            ],
        ];
    }
}
