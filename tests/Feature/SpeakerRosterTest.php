<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\HeroCast;
use App\Support\SpeakerAvatar;
use App\Support\SpeakerVoices;
use App\Support\VoiceCatalog;
use App\Support\VoicePreviews;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One premium speaker for every Arabic dialect, English accent and native
 * language, each with a voice of their own, the same in the library, their
 * preview and the homepage clips.
 */
class SpeakerRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SpeakerVoices::forget();
        VoicePreviews::forget();
        parent::tearDown();
    }

    public function test_every_dialect_accent_and_native_language_has_one_speaker(): void
    {
        // Children come on top, in dialects grown-ups already speak.
        $adults = array_filter(VoicePreviews::lines(), fn (string $slug): bool => (SpeakerVoices::speaker(substr($slug, 3))['age'] ?? null) !== 'child', ARRAY_FILTER_USE_KEY);
        $dialects = array_column($adults, 'dialect');

        $this->assertSame(count($dialects), count(array_unique($dialects)), 'two speakers share a dialect');
        $expected = [...array_keys(ArabicDialects::ALL), ...array_keys(EnglishAccents::ALL), ...array_keys(VoicePreviews::NATIVE)];
        sort($expected);
        sort($dialects);
        $this->assertSame($expected, $dialects);
    }

    public function test_every_premium_speaker_has_a_voice_of_their_own_a_preview_an_avatar_and_an_arabic_name(): void
    {
        (new VoiceSeeder)->run();
        $speakers = Voice::query()->where('tier', 'hd')->get();
        $lines = VoicePreviews::lines();

        $this->assertCount(count(SpeakerVoices::all()), $speakers);
        $this->assertTrue($speakers->every(fn (Voice $v): bool => $v->provider === 'gemini'), 'every premium speaker is shown in the library');
        foreach ($speakers->groupBy('language') as $language => $group) {
            $voices = $group->map(fn (Voice $v): string => strtolower((string) $v->provider_voice_id));
            $this->assertSame([], $voices->duplicates()->values()->all(), "two {$language} speakers share a voice");
        }
        // Across the languages only homepage speakers share one: the studio voice of their homepage clips.
        $shared = $speakers->groupBy(fn (Voice $v): string => strtolower((string) $v->provider_voice_id))->filter(fn ($group): bool => $group->count() > 1);
        foreach ($shared->flatten() as $speaker) {
            $this->assertStringContainsString('-Gemini-HD-', (string) $speaker->internal_voice_id, $speaker->display_name);
            $this->assertNotNull(GeminiTtsProvider::voiceGender((string) $speaker->provider_voice_id));
        }
        $names = $speakers->map(fn (Voice $v): string => strtolower((string) $v->display_name));
        $this->assertSame([], $names->duplicates()->values()->all(), 'two speakers share a name');

        $catalog = collect(VoiceCatalog::publicCatalog('hd', 'gemini'))->flatten(1)->keyBy('key');
        foreach ($speakers as $speaker) {
            $id = (string) $speaker->internal_voice_id;
            $slug = SpeakerAvatar::slug((string) $speaker->display_name, (string) $speaker->language);
            $this->assertSame(SpeakerVoices::find($id), $speaker->provider_voice_id, $id);
            $this->assertSame($speaker->gender, SpeakerVoices::all()[strtolower($id)]['gender'], $id);
            $this->assertSame($speaker->gender, GeminiTtsProvider::voiceGender((string) $speaker->provider_voice_id), $id);
            $this->assertArrayHasKey($slug, $lines, "{$id} has no preview line");
            $this->assertSame($speaker->provider_voice_id, $lines[$slug]['voice'], "{$slug}: the preview is in the speaker's own voice");
            $this->assertFileExists(public_path("images/speakers/{$slug}.webp"), "{$slug} has no avatar");
            $this->assertNotSame($speaker->display_name, $catalog[VoiceCatalog::keyFromId((int) $speaker->id)]['label_ar'], "{$id} has no Arabic name");
        }
    }

    public function test_every_speaker_has_an_age_and_the_library_filters_by_it(): void
    {
        foreach (SpeakerVoices::all() as $id => $speaker) {
            $this->assertContains($speaker['age'] ?? null, SpeakerVoices::AGES, $id);
        }

        (new VoiceSeeder)->run();
        $entries = VoiceCatalog::libraryEntries(false);
        $ages = array_count_values(array_filter(array_column($entries, 'y')));
        foreach (['young', 'middle', 'elder'] as $age) {
            $this->assertGreaterThan(5, $ages[$age] ?? 0, "{$age} speakers in the library");
        }

        $user = \App\Models\User::factory()->create(['email_verified_at' => now()]);
        $page = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        foreach (['', 'young', 'middle', 'elder'] as $age) {
            $this->assertStringContainsString('data-vs-lib-age="'.$age.'"', $page);
        }
        $this->assertStringContainsString('aria-label="Middle-aged"', $page);
    }

    public function test_a_child_speaker_always_speaks_as_a_child_alone_or_in_a_dialogue(): void
    {
        $children = array_filter(SpeakerVoices::all(), fn (array $s): bool => ($s['age'] ?? null) === 'child');
        $this->assertGreaterThanOrEqual(12, count($children));
        foreach ($children as $id => $child) {
            $this->assertArrayHasKey(SpeakerAvatar::slug($child['name'], substr($id, 0, 2)), VoicePreviews::lines(), $id);
        }

        $this->assertStringStartsWith('The speaker is a real girl of about eight or nine', GeminiTtsProvider::asSpeaker('ar-Gemini-HD-Malak', 'Warm and happy.'));
        $this->assertStringEndsWith('Warm and happy.', GeminiTtsProvider::asSpeaker('Malak', 'Warm and happy.'));
        $this->assertStringContainsString('real boy', GeminiTtsProvider::asSpeaker('Alfie', ''));
        $this->assertSame('Warm and happy.', GeminiTtsProvider::asSpeaker('ar-Gemini-HD-Orus', 'Warm and happy.'), 'grown-ups unchanged');

        \Illuminate\Support\Facades\Storage::fake('public');
        $key = ['key' => 'k', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $keys = \Mockery::mock(\App\Services\Tts\GeminiKeyManager::class);
        $keys->shouldReceive('getAllKeys')->andReturn([$key]);
        $keys->shouldReceive('getAvailableKey')->andReturn($key);
        $keys->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(\App\Services\Tts\GeminiKeyManager::class, $keys);
        config(['services.gemini_tts.dialect_rewrite' => false]);
        $bodies = [];
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->data();

            return \Illuminate\Support\Facades\Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/mpeg', 'data' => base64_encode("\xFF\xFB\x90\xC4".str_repeat("\0", 64))]]]]]]]);
        });

        app(GeminiTtsProvider::class)->synthesize('Hello there, Grandma!', 'en', ['voice_name' => 'en-Gemini-HD-Emily']);
        $this->assertStringContainsString('real girl', $bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style']);
        $this->assertSame(SpeakerVoices::find('Emily'), $bodies[0]['generationConfig']['speechConfig']['voiceConfig']['voice']);

        app(GeminiTtsProvider::class)->synthesize("Alfie: Look at my rocket!\nTheodore: Splendid.", 'en', ['speakers' => [
            ['name' => 'Alfie', 'voice_name' => 'en-Gemini-HD-Alfie'],
            ['name' => 'Theodore', 'voice_name' => 'en-Gemini-HD-Charon'],
        ]]);
        $parts = $bodies[1]['contents'][0]['parts'];
        $this->assertStringContainsString('real boy', $parts[0]['speech_metadata']['style']);
        $this->assertStringNotContainsString('real boy', $parts[1]['speech_metadata']['style'] ?? '');
    }

    public function test_homepage_clips_use_each_speakers_own_voice(): void
    {
        $own = [];
        foreach (SpeakerVoices::all() as $id => $speaker) {
            $own[substr($id, 0, 2).':'.$speaker['name']] = $speaker['voice'];
        }

        $checked = 0;
        foreach ((require database_path('seeders/data/showcase_samples.php'))['samples'] as $sample) {
            $cast = $sample['kind'] === 'dialogue' ? $sample['speakers'] : [['name' => $sample['speaker'], 'voice' => $sample['voice']]];
            foreach ($cast as $speaker) {
                $key = $sample['language'].':'.$speaker['name'];
                if (isset($own[$key])) {
                    $this->assertSame($own[$key], $speaker['voice'], "{$sample['slug']}: {$speaker['name']}");
                    $checked++;
                }
            }
        }
        // The hero opens with Sultan in a more striking voice than his other clips (kept as it is).
        $heroOnly = ['ar:Sultan' => 'Fenrir'];
        foreach (HeroCast::CAST as $language => $people) {
            foreach ($people as $person) {
                $key = $language.':'.$person['name'];
                $this->assertSame($heroOnly[$key] ?? $own[$key], $person['voice'], "hero {$person['name']}");
                $checked++;
            }
        }
        $this->assertGreaterThan(60, $checked);
    }

    public function test_a_speaker_is_found_by_id_or_name_and_their_voice_is_used_as_is(): void
    {
        $resolve = fn (string $name, ?string $gender = null): string => (new \ReflectionMethod(GeminiTtsProvider::class, 'resolveGeminiVoice'))
            ->invoke(app(GeminiTtsProvider::class), $name, $gender);

        $this->assertSame(SpeakerVoices::find('ar-Gemini-HD-Pulcherrima'), $resolve('ar-Gemini-HD-Pulcherrima', 'female'));
        $this->assertSame(SpeakerVoices::find('ar-Gemini-HD-Pulcherrima'), $resolve('Abeer'));
        $this->assertNotSame('Zephyr', $resolve('Abeer'), 'Abeer no longer shares Sawsan\'s voice');
        $this->assertSame('Zephyr', $resolve('Sawsan'));
        // Dubbing passes the engine voice itself.
        $this->assertSame(SpeakerVoices::find('Abeer'), $resolve((string) SpeakerVoices::find('Abeer'), 'female'));
    }

    public function test_the_migration_shows_every_speaker_with_their_own_voice_and_adds_the_new_ones(): void
    {
        DB::table('voices')->insert([
            ['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Farah', 'internal_voice_id' => 'ar-XA-Chirp3-HD-Achernar',
                'provider' => 'google_cloud', 'provider_voice_id' => 'ar-XA-Chirp3-HD-Achernar', 'is_active' => true],
            ['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Abeer', 'internal_voice_id' => 'ar-Gemini-HD-Pulcherrima',
                'provider' => 'gemini', 'provider_voice_id' => 'Pulcherrima', 'is_active' => true],
            ['language' => 'ar', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Sara', 'internal_voice_id' => 'ar-XA-Standard-A',
                'provider' => 'google_cloud', 'provider_voice_id' => 'ar-XA-Standard-A', 'is_active' => true],
        ]);
        $migration = require database_path('migrations/2026_10_30_120000_one_premium_speaker_for_every_dialect.php');

        $migration->up();
        $migration->up();

        $farah = Voice::query()->where('internal_voice_id', 'ar-XA-Chirp3-HD-Achernar')->first();
        $this->assertSame('gemini', $farah->provider);
        $this->assertSame(SpeakerVoices::find('Farah'), $farah->provider_voice_id);
        $this->assertSame(SpeakerVoices::find('Abeer'), Voice::query()->where('display_name', 'Abeer')->value('provider_voice_id'));
        $this->assertSame('ar-XA-Standard-A', Voice::query()->where('display_name', 'Sara')->value('provider_voice_id'));
        $added = (require database_path('seeders/data/more_speakers.php'))['speakers'];
        $this->assertSame(count($added) + 3, Voice::query()->count(), 'running it twice adds nobody twice');
        $new = Voice::query()->where('internal_voice_id', $added[0]['id'])->first();
        $this->assertSame([$added[0]['name'], 'gemini', 'hd', SpeakerVoices::find($added[0]['id'])], [$new->display_name, $new->provider, $new->tier, $new->provider_voice_id]);

        $migration->down();

        $this->assertSame(3, Voice::query()->count());
        $this->assertSame(['google_cloud', 'ar-XA-Chirp3-HD-Achernar'], [$farah->fresh()->provider, $farah->fresh()->provider_voice_id]);
        $this->assertSame('Pulcherrima', Voice::query()->where('display_name', 'Abeer')->value('provider_voice_id'));
    }
}
