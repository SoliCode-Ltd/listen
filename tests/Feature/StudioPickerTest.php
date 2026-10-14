<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\GeminiVocalTags;
use App\Support\StudioChoices;
use App\Support\TtsStudioConfig;
use App\Support\VoiceFlag;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class StudioPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_emotion_style_and_tag_has_an_icon_a_category_and_a_line_in_both_languages(): void
    {
        foreach ([[TtsStudioConfig::emotionValues(), StudioChoices::EMOTIONS, StudioChoices::EMOTION_GROUPS], [TtsStudioConfig::performanceStyleValues(), StudioChoices::STYLES, StudioChoices::STYLE_GROUPS]] as [$values, $rows, $groups]) {
            $this->assertSame([], array_values(array_diff($values, array_keys($rows))), 'every choice has a card');
            foreach ($rows as $value => [$icon, $group, $en, $ar]) {
                $this->assertContains($value, $values, $value.' is offered in the studio');
                $this->assertArrayHasKey($group, $groups, $value);
                $this->assertNotSame('', $en, $value);
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar, $value);
                $this->assertMatchesRegularExpression('/^[fg]-[a-z0-9-]+$/', $icon, $value);
            }
        }
        foreach (array_merge(GeminiVocalTags::all(), GeminiVocalTags::deliveries()) as $tag) {
            $this->assertArrayHasKey($tag, StudioChoices::TAG_ICONS, $tag);
        }
    }

    public function test_every_icon_the_studio_names_is_drawn(): void
    {
        $script = (string) file_get_contents(public_path('js/voice-studio-icons.js'));
        $faces = substr($script, strpos($script, 'var FACES = {'));
        $faces = substr($faces, 0, strpos($faces, '};'));
        $glyphs = substr($script, strpos($script, 'var GLYPHS = {'));
        $glyphs = substr($glyphs, 0, strpos($glyphs, "\n  };"));

        $icons = array_merge(array_column(StudioChoices::EMOTIONS, 0), array_column(StudioChoices::STYLES, 0), array_values(StudioChoices::TAG_ICONS));
        foreach (array_unique($icons) as $icon) {
            $name = preg_quote(substr($icon, 2), '/');
            $this->assertMatchesRegularExpression(
                '/(?:^|[\s{,])\'?'.$name.'\'?:/m',
                str_starts_with($icon, 'f-') ? $faces : $glyphs,
                $icon.' has a drawing'
            );
        }
    }

    public function test_every_dialect_and_accent_has_a_card_line_and_a_flag_or_letter(): void
    {
        foreach (ArabicDialects::ALL as $code => $row) {
            $this->assertNotSame('', ArabicDialects::about($code, false), $code);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', ArabicDialects::about($code, true), $code);
            $this->assertNotNull(ArabicDialects::markers($code), $code.' has pronunciation markers');
            $this->assertLessThanOrEqual(10, strlen($code), 'fits the dialect columns');
            if (ArabicDialects::countryOf($code) !== 'XA') {
                $this->assertNotNull(VoiceFlag::url($code), $code.' has a flag');
            }
        }
        foreach (EnglishAccents::ALL as $code => $row) {
            $this->assertArrayHasKey($row['country'], EnglishAccents::COUNTRIES, $code);
            $this->assertNotSame('', EnglishAccents::about($code, false), $code);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', EnglishAccents::about($code, true), $code);
            $this->assertLessThanOrEqual(10, strlen($code), 'fits the dialect columns');
            if ($row['country'] !== 'AR') {
                $this->assertNotNull(VoiceFlag::url($code, $row['country']), $code.' has a flag');
            }
        }
        $this->assertStringEndsWith('gb-nir.svg', (string) VoiceFlag::url('en-GB-NI', 'GB'));
        $this->assertSame('asia_pacific', VoiceFlag::region('en-PH'));
        $this->assertSame('africa', VoiceFlag::region('en-KE'));
    }

    public function test_every_emotion_and_style_has_its_own_speech_direction(): void
    {
        $provider = new ReflectionClass(GeminiTtsProvider::class);
        $instance = app(GeminiTtsProvider::class);
        $emotion = $provider->getMethod('getEmotionDescription');
        $style = $provider->getMethod('getPerformanceStyleDescription');

        foreach (TtsStudioConfig::emotionValues() as $value) {
            if ($value !== 'neutral') {
                $this->assertNotSame('', $emotion->invoke($instance, $value, false), $value.' has a direction');
            }
        }
        foreach (TtsStudioConfig::performanceStyleValues() as $value) {
            $this->assertStringNotContainsString('Performance style: ', $style->invoke($instance, $value, false), $value.' has its own direction');
        }
    }

    public function test_fusha_registers_stay_formal_and_new_delivery_tags_direct_the_reading(): void
    {
        $prompt = (new ReflectionClass(GeminiTtsProvider::class))->getMethod('buildStylePrompt')
            ->invoke(app(GeminiTtsProvider::class), 'news', 'serious', 'ar', 1.0, 'ar-XA-NW', 0.0, true);
        $this->assertStringContainsString('TV news bulletin', $prompt);
        $this->assertStringNotContainsString('Do not switch to Modern Standard Arabic', $prompt);

        $parts = GeminiVocalTags::parts('[بتأكيد] هذا مهم. [ضاحكًا] لا تصدق!');
        $this->assertSame(['with strong emphasis, stressing every key word'], $parts[0]['cues']);
        $this->assertSame(['while laughing, the words bubbling with laughter'], $parts[1]['cues']);
    }

    public function test_dashboard_carries_the_picker_data_in_the_page_language(): void
    {
        $plan = Plan::factory()->create(['voice_tier' => VoiceTier::HD, 'is_active' => true]);
        $user = User::factory()->create();
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);
        app()->setLocale('ar');

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<script type="application\/json" data-vs-meta>(.*?)<\/script>/s', $html, $m));
        $meta = json_decode($m[1], true);
        $this->assertSame('جدة ومكة والمدينة · إيقاع سريع وناعم', $meta['dialects']['ar-SA-HJ']['d']);
        $this->assertStringEndsWith('/images/flags/sa.svg', $meta['dialects']['ar-SA-HJ']['f']);
        $this->assertSame('gulf', $meta['dialects']['ar-SA-HJ']['r']);
        $this->assertNull($meta['dialects']['ar-XA-CL']['f'], 'Fusha has the letter badge');
        $this->assertSame('الخليج', $meta['regions']['gulf']);
        $this->assertSame('f-panic', $meta['emotion']['panicked']['i']);
        $this->assertSame('مشاعر قوية', $meta['emotionGroups'][$meta['emotion']['panicked']['g']]);
        $this->assertSame('g-ghost', $meta['performance_style']['horror']['i']);
        $this->assertSame('f-laugh', $meta['tags']['laugh']);
        $this->assertSame('اختر اللهجة', $meta['i18n']['dialect_title']);
        // The choices themselves stay ordinary form fields.
        $this->assertStringContainsString('<select name="emotion" id="emotion" class="vs-hidden-field"', $html);
        $this->assertStringContainsString('<option value="panicked">مذعور</option>', $html);
        $this->assertStringContainsString('<option value="horror">قصة رعب</option>', $html);
    }

    public function test_members_keep_their_favourite_voices_on_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('dashboard.favorite-voices', [], false), ['voices' => ['voice_3', 'cloned_7', 'voice_3']])
            ->assertOk()
            ->assertJson(['saved' => true, 'voices' => ['voice_3', 'cloned_7']]);
        $this->assertSame(['voice_3', 'cloned_7'], $user->fresh()->favorite_voices);

        $this->actingAs($user)->putJson(route('dashboard.favorite-voices', [], false), ['voices' => ['<script>']])->assertStatus(422);
        $this->actingAs($user)->putJson(route('dashboard.favorite-voices', [], false), ['voices' => array_map(fn ($i) => 'voice_'.$i, range(1, 101))])->assertStatus(422);
        $this->assertSame(['voice_3', 'cloned_7'], $user->fresh()->favorite_voices, 'a refused list changes nothing');

        $this->actingAs($user)->putJson(route('dashboard.favorite-voices', [], false), ['voices' => []])->assertOk();
        $this->assertSame([], $user->fresh()->favorite_voices);
    }

    public function test_guests_cannot_save_favourites(): void
    {
        $this->putJson(route('dashboard.favorite-voices', [], false), ['voices' => ['voice_1']])->assertUnauthorized();
    }

    public function test_the_library_knows_each_speaker_and_the_members_favourites(): void
    {
        $plan = Plan::factory()->create(['voice_tier' => VoiceTier::HD, 'is_active' => true]);
        $user = User::factory()->create(['favorite_voices' => ['voice_999']]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $voice = Voice::query()->create([
            'language' => 'ar', 'gender' => 'female', 'tier' => VoiceTier::HD, 'display_name' => 'Rawaa',
            'internal_voice_id' => 'gemini-ar-rawaa-test', 'is_active' => true, 'provider' => 'gemini',
            'provider_voice_id' => 'Kore', 'dialect' => 'ar-SA-HJ', 'preview_audio_path' => 'missing/preview.mp3',
        ]);
        app()->setLocale('ar');

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script type="application\/json" data-vs-meta>(.*?)<\/script>/s', $html, $m));
        $meta = json_decode($m[1], true);

        $entry = $meta['voices']['voice_'.$voice->id];
        $this->assertSame('female', $entry['g']);
        $this->assertSame('ar', $entry['l']);
        $this->assertSame('SA', $entry['c']);
        $this->assertStringEndsWith('/images/flags/sa.svg', $entry['f']);
        $this->assertSame('سعودي (حجازي)', $entry['d']);
        $this->assertNull($entry['p'], 'no preview when the file is missing');
        $this->assertSame(['voice_999'], $meta['favorites']);
        $this->assertStringEndsWith('/dashboard/favorite-voices', $meta['favoritesUrl']);
        $this->assertSame('أنثى', $meta['i18n']['female']);
    }
}
