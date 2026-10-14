<?php

namespace Tests\Feature;

use App\Exceptions\TranslationFailedException;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use App\Support\SpeechLanguages;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

class SpeechLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_language_has_names_a_speech_code_the_provider_accepts_and_a_flag(): void
    {
        $supported = (new ReflectionClass(GeminiTtsProvider::class))->getConstant('SUPPORTED_LANGUAGE_CODES');
        $this->assertGreaterThanOrEqual(24, count(SpeechLanguages::codes()));
        foreach (SpeechLanguages::ALL as $code => $row) {
            $this->assertContains(SpeechLanguages::speechCode($code), $supported, $code);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', SpeechLanguages::name($code, true), $code);
            $this->assertNotSame('', SpeechLanguages::nativeName($code), $code);
            $this->assertFileExists(public_path('images/flags/'.SpeechLanguages::flag($code).'.svg'), $code);
        }
        $this->assertFalse(SpeechLanguages::premiumOnly('ar'));
        $this->assertFalse(SpeechLanguages::premiumOnly('en'));
        $this->assertTrue(SpeechLanguages::premiumOnly('es'));
        $this->assertSame('en', SpeechLanguages::voiceLanguage('ja'));
        $this->assertSame('ar', SpeechLanguages::voiceLanguage('ar'));
    }

    public function test_the_script_tells_which_language_text_was_left_in(): void
    {
        $this->assertSame('ar', SpeechLanguages::detect('مرحبا'));
        $this->assertSame('ru', SpeechLanguages::detect('Привет'));
        $this->assertSame('ja', SpeechLanguages::detect('こんにちは'));
        $this->assertSame('en', SpeechLanguages::detect('Hola amigo'));
        $this->assertTrue(SpeechLanguages::hasForeignScript('I am under the bridge ومش عارف', 'en'));
        $this->assertFalse(SpeechLanguages::hasForeignScript('Estoy bajo el puente', 'es'));
        $this->assertFalse(SpeechLanguages::hasForeignScript('أنا تحت الجسر', 'ar'));
    }

    public function test_a_premium_member_can_make_a_spanish_recording_with_a_premium_speaker(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/voice.mp3', 'audio');
        $tts = $this->fakeSpeech('audio/voice.mp3');
        $tts->expects($this->once())->method('synthesizeAdvanced')
            ->with($this->anything(), 'es', $this->callback(fn (array $options) => ($options['dialect'] ?? null) === null));
        $voice = $this->voice();

        $this->actingAs($this->memberOn(VoiceTier::HD))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hola, bienvenidos al programa.',
            'target_language' => 'es',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'dialect' => 'ar-EG',
        ])->assertOk();

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('es', $generation->target_language);
        $this->assertSame($voice->internal_voice_id, $generation->voice_name);
    }

    public function test_standard_plans_keep_arabic_and_english_only(): void
    {
        Storage::fake('public');
        $this->voice();

        $this->actingAs($this->memberOn(VoiceTier::STANDARD))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hola.',
            'target_language' => 'es',
        ])->assertStatus(422)->assertJsonValidationErrors('target_language');

        $this->actingAs($this->memberOn(VoiceTier::HD))->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hola.',
            'target_language' => 'xx',
        ])->assertStatus(422)->assertJsonValidationErrors('target_language');
    }

    public function test_the_voice_is_told_which_language_to_speak(): void
    {
        $provider = new ReflectionClass(GeminiTtsProvider::class);
        $instance = app(GeminiTtsProvider::class);

        $prompt = $provider->getMethod('buildStylePrompt')->invoke($instance, 'neutral', 'neutral', 'fr', 1.0, null, 0.0, true);
        $this->assertStringContainsString('Speak in French (Français)', $prompt);
        $this->assertStringNotContainsString('English accent', $prompt);
        $this->assertSame('ja-JP', $provider->getMethod('resolveLanguageCode')->invoke($instance, 'ja', null));
        $this->assertSame('ar-XA', $provider->getMethod('resolveLanguageCode')->invoke($instance, 'ar', null));
    }

    public function test_the_dashboard_lists_the_languages_and_locks_them_on_standard_plans(): void
    {
        $this->voice();
        app()->setLocale('ar');

        $hd = $this->actingAs($this->memberOn(VoiceTier::HD))->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="es">الإسبانية · Español</option>', $hd);
        $this->assertStringContainsString('<option value="ja">اليابانية · 日本語</option>', $hd);

        $standard = $this->actingAs($this->memberOn(VoiceTier::STANDARD))->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="es" disabled>الإسبانية · Español</option>', $standard);
    }

    public function test_a_translation_that_leaves_text_untranslated_is_finished_by_the_language_model(): void
    {
        // The quick translator returns the part after the tag untouched.
        Http::fake(['translate.googleapis.com/*' => Http::response([[["I'm under the bridge [[0]] ومش عارف اعمل ايه", 'x']], null, 'ar'])]);
        $this->mock(VoiceCloningService::class, fn ($mock) => $mock->shouldReceive('generateText')->once()
            ->andReturn("I'm under the bridge [[0]] and I don't know what to do"));

        $out = app(TranslationService::class)->maybeTranslate('انا تحت الكوبري [بقلق] ومش عارف اعمل ايه', 'ar', 'en', true);

        $this->assertSame("I'm under the bridge [بقلق] and I don't know what to do", $out);
    }

    public function test_no_translation_means_no_recording_instead_of_reading_the_original(): void
    {
        Http::fake(['translate.googleapis.com/*' => Http::response('', 500)]);
        $this->mock(VoiceCloningService::class, fn ($mock) => $mock->shouldReceive('generateText')->andReturn(null));

        $this->expectException(TranslationFailedException::class);
        app(TranslationService::class)->maybeTranslate('انا تحت الكوبري', 'ar', 'fr', true);
    }

    public function test_text_already_in_the_voice_language_is_read_as_written(): void
    {
        Http::fake(['translate.googleapis.com/*' => Http::response([[['Hola amigo', 'Hola amigo']], null, 'es'])]);

        $this->assertSame('Hola amigo', app(TranslationService::class)->maybeTranslate('Hola amigo', 'en', 'es', true));
    }

    private function fakeSpeech(string $path): TtsService
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willReturn([
            'audio_path' => $path, 'duration_seconds' => 2, 'provider' => 'gemini', 'model' => 'premium',
            'latency_ms' => 10, 'fallback_used' => false, 'cost_estimate_usd' => 0.0, 'routing_mode' => 'hybrid', 'plan_slug' => 'test',
        ]);
        $this->app->instance(TtsService::class, $tts);

        return $tts;
    }

    private function memberOn(string $tier): User
    {
        $plan = Plan::factory()->create(['voice_tier' => $tier, 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function voice(): Voice
    {
        Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-test-standard'], [
            'language' => 'en', 'gender' => 'male', 'tier' => 'standard', 'display_name' => 'Puck standard',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-test-standard', 'is_active' => true,
        ]);

        return Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-test-hd'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Kore hd',
            'provider' => VoiceCatalog::preferredProviderForTier('hd'), 'provider_voice_id' => 'Kore', 'is_active' => true,
        ]);
    }
}
