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
            ->assertSee(__('ui.dashboard.dialogue_example_btn'));
    }

    private function member(bool $dialogue): User
    {
        $plan = Plan::factory()->create([
            'slug' => $dialogue ? 'studio-max-test' : 'studio-test',
            'price_cents' => 2999,
            'voice_tier' => 'hd',
            'tts_provider_policy' => 'auto',
            'dialogue_enabled' => $dialogue,
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
