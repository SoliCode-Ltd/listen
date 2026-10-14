<?php

namespace Tests\Feature;

use App\Exceptions\TranslationFailedException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TranslationService;
use App\Services\VoiceCloningService;
use App\Support\EnglishAccents;
use App\Support\SpeechLanguages;
use App\Support\VoiceFlag;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpeechLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recordings_are_made_in_arabic_and_english_only(): void
    {
        $this->assertSame(['ar', 'en'], SpeechLanguages::codes());
        $this->assertFalse(SpeechLanguages::premiumOnly('es'));

        $plan = Plan::factory()->create(['voice_tier' => VoiceTier::HD, 'is_active' => true]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Hola.',
            'target_language' => 'es',
        ])->assertStatus(422)->assertJsonValidationErrors('target_language');

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('<option value="es"', $html);
    }

    public function test_english_has_accents_from_other_languages_each_with_a_flag_and_a_line(): void
    {
        foreach (['en-ES', 'en-MX', 'en-DE', 'en-IT', 'en-BR', 'en-RU', 'en-TR', 'en-NL', 'en-PL', 'en-GR', 'en-IR', 'en-JP', 'en-KR', 'en-CN', 'en-FR'] as $code) {
            $row = EnglishAccents::ALL[$code] ?? null;
            $this->assertNotNull($row, $code);
            $this->assertStringContainsString('native', $row['prompt'], $code);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', EnglishAccents::about($code, true), $code);
            $this->assertNotNull(VoiceFlag::url($code, $row['country']), $code.' has a flag');
            $this->assertSame('other', VoiceFlag::region($code), $code);
        }
        $this->assertGreaterThanOrEqual(45, count(EnglishAccents::ALL));
    }

    public function test_the_script_tells_which_language_text_was_left_in(): void
    {
        $this->assertSame('ar', SpeechLanguages::detect('مرحبا'));
        $this->assertSame('ru', SpeechLanguages::detect('Привет'));
        $this->assertSame('en', SpeechLanguages::detect('Hola amigo'));
        $this->assertTrue(SpeechLanguages::hasForeignScript('I am under the bridge ومش عارف', 'en'));
        $this->assertFalse(SpeechLanguages::hasForeignScript('أنا تحت الجسر', 'ar'));
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
        app(TranslationService::class)->maybeTranslate('انا تحت الكوبري', 'ar', 'en', true);
    }

    public function test_text_already_in_the_voice_language_is_read_as_written(): void
    {
        Http::fake(['translate.googleapis.com/*' => Http::response([[['Hello friend', 'Hello friend']], null, 'en'])]);

        $this->assertSame('Hello friend', app(TranslationService::class)->maybeTranslate('Hello friend', 'en', 'en', true));
    }
}
