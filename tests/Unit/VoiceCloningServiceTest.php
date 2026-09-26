<?php

namespace Tests\Unit;

use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceCloningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_enabled_returns_true_by_default(): void
    {
        $this->assertTrue(VoiceCloningService::isEnabled());
    }

    public function test_is_enabled_returns_false_when_disabled(): void
    {
        SiteSetting::set('voice_cloning_enabled', '0');

        $this->assertFalse(VoiceCloningService::isEnabled());
    }

    public function test_max_voices_per_user_default(): void
    {
        $this->assertEquals(5, VoiceCloningService::maxVoicesPerUser());
    }

    public function test_max_voices_per_user_custom(): void
    {
        SiteSetting::set('voice_cloning_max_per_user', 10);

        $this->assertEquals(10, VoiceCloningService::maxVoicesPerUser());
    }

    public function test_max_voices_for_plan_uses_site_default_when_plan_max_is_zero(): void
    {
        SiteSetting::set('voice_cloning_max_per_user', 9);

        $plan = Plan::factory()->paid()->create([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 0,
        ]);

        $this->assertEquals(9, VoiceCloningService::maxVoicesForPlan($plan));

        $plan->voice_cloning_max_voices = 4;
        $this->assertEquals(4, VoiceCloningService::maxVoicesForPlan($plan));
    }

    public function test_max_voices_for_plan_falls_back_to_global_only_when_plan_is_missing(): void
    {
        SiteSetting::set('voice_cloning_max_per_user', 7);

        $this->assertEquals(7, VoiceCloningService::maxVoicesForPlan(null));
    }

    public function test_min_sample_duration_default(): void
    {
        $this->assertEquals(10, VoiceCloningService::minSampleDuration());
    }

    public function test_max_upload_size_default(): void
    {
        $this->assertEquals(25, VoiceCloningService::maxUploadSizeMb());
    }

    public function test_allowed_mime_types_are_audio(): void
    {
        $mimes = VoiceCloningService::allowedMimeTypes();

        $this->assertNotEmpty($mimes);
        $allowedPrefixes = ['audio/', 'video/', 'application/'];
        foreach ($mimes as $mime) {
            $matchesPrefix = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    $matchesPrefix = true;
                    break;
                }
            }
            $this->assertTrue($matchesPrefix, "MIME type '{$mime}' does not start with an allowed prefix.");
        }
        // Ensure audio types are the majority
        $audioTypes = array_filter($mimes, fn ($m) => str_starts_with($m, 'audio/'));
        $this->assertGreaterThan(count($mimes) / 2, count($audioTypes));
    }

    public function test_allowed_extensions(): void
    {
        $extensions = VoiceCloningService::allowedExtensions();

        $this->assertContains('wav', $extensions);
        $this->assertContains('mp3', $extensions);
        $this->assertContains('m4a', $extensions);
        $this->assertContains('webm', $extensions);
        $this->assertNotContains('pdf', $extensions);
        $this->assertNotContains('exe', $extensions);
    }

    public function test_consent_scripts_available_for_both_languages(): void
    {
        $scripts = VoiceCloningService::CONSENT_SCRIPTS;

        $this->assertArrayHasKey('en', $scripts);
        $this->assertArrayHasKey('ar', $scripts);
        $this->assertSame(
            'I am the owner of this voice and I consent to Google using this voice to create a synthetic voice model.',
            $scripts['en'],
        );
        $this->assertSame(
            'أنا مالك هذا الصوت وأوافق على أن تستخدم Google هذا الصوت لإنشاء نموذج صوتي اصطناعي.',
            $scripts['ar'],
        );
    }

    public function test_viewer_limit_follows_the_plan_toggle_and_exact_max(): void
    {
        $admin = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        $plan = Plan::factory()->paid()->create([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 0,
        ]);

        $this->assertSame(5, VoiceCloningService::maxVoicesForViewer($admin, $plan));

        $plan->voice_cloning_max_voices = 3;
        $this->assertSame(3, VoiceCloningService::maxVoicesForViewer($admin, $plan));

        $plan->voice_cloning_enabled = false;
        $this->assertFalse(VoiceCloningService::planAllows($plan));
        $this->assertSame(0, VoiceCloningService::maxVoicesForViewer($admin, $plan));
    }

    public function test_progress_advances_within_a_status_and_caps_before_completion(): void
    {
        $this->assertSame(12, VoiceCloningService::progressPercent('pending', 0));
        $this->assertSame(20, VoiceCloningService::progressPercent('pending', 24));
        $this->assertSame(36, VoiceCloningService::progressPercent('pending', 500));
        $this->assertGreaterThan(
            VoiceCloningService::progressPercent('pending', 10),
            VoiceCloningService::progressPercent('validating', 10),
        );
        $this->assertSame(100, VoiceCloningService::progressPercent('ready', 0));
        $this->assertSame(0, VoiceCloningService::elapsedSeconds(null));
        $this->assertGreaterThanOrEqual(40, VoiceCloningService::elapsedSeconds(now()->subSeconds(40)));
    }

    public function test_only_preview_admin_can_access_cloning(): void
    {
        $admin = User::factory()->create(['email' => 'Solicode.co.uk@gmail.com']);
        $other = User::factory()->create(['email' => 'listener@example.com']);

        $this->assertTrue(VoiceCloningAccess::allows($admin));
        $this->assertFalse(VoiceCloningAccess::allows($other));
        $this->assertFalse(VoiceCloningAccess::allows(null));

        $freePlan = Plan::factory()->free()->create();
        $paidPlan = Plan::factory()->paid()->create(['price_cents' => 1999]);
        $this->assertTrue(VoiceCloningAccess::showsUpgrade($other, $freePlan));
        $this->assertFalse(VoiceCloningAccess::showsUpgrade($other, $paidPlan));
        $this->assertFalse(VoiceCloningAccess::showsUpgrade($admin, $freePlan));
    }

    public function test_replication_request_uses_gemini_38_and_separate_consent_audio(): void
    {
        Storage::fake('local');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        Storage::disk('local')->put('voice-cloning/sample.wav', 'sample-bytes');
        Storage::disk('local')->put('voice-cloning/consent.wav', 'consent-bytes');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['id' => 'voice_test123'], 200),
        ]);

        $voice = ClonedVoice::factory()->create([
            'source_audio_path' => 'voice-cloning/sample.wav',
            'consent_audio_path' => 'voice-cloning/consent.wav',
            'language_code' => 'ar-XA',
            'name' => 'Studio',
        ]);

        $result = app(VoiceCloningService::class)->generateVoiceCloningKey($voice);

        $this->assertTrue($result['success']);
        $this->assertSame('voice_test123', $result['voice_cloning_key']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/voices')
                && ($data['store'] ?? false) === true
                && ($data['voice']['model'] ?? null) === VoiceCloningService::MODEL
                && ($data['voice']['type'] ?? null) === 'replicated'
                && filled($data['voice']['replicated']['source_audio']['data'] ?? null)
                && filled($data['voice']['replicated']['consent_audio']['data'] ?? null)
                && $request->hasHeader('x-goog-api-key', 'test-key');
        });
    }

    public function test_browser_wav_is_sent_to_gemini_as_wav(): void
    {
        Storage::fake('local');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        $wav = $this->silentWav(12);
        Storage::disk('local')->put('voice-cloning/sample.wav', $wav);
        Storage::disk('local')->put('voice-cloning/consent.wav', $this->silentWav(4));

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['id' => 'voice_wav123'], 200),
        ]);

        $voice = ClonedVoice::factory()->create([
            'source_audio_path' => 'voice-cloning/sample.wav',
            'consent_audio_path' => 'voice-cloning/consent.wav',
        ]);

        $result = app(VoiceCloningService::class)->generateVoiceCloningKey($voice);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        Http::assertSent(fn ($request): bool => ($request->data()['voice']['replicated']['source_audio']['mime_type'] ?? null) === 'audio/wav'
            && ($request->data()['voice']['replicated']['consent_audio']['mime_type'] ?? null) === 'audio/wav');
    }

    private function silentWav(int $seconds): string
    {
        $rate = 24000;
        $data = str_repeat("\x00\x00", $rate * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    public function test_replication_accepts_a_nested_voice_id(): void
    {
        Storage::fake('local');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        Storage::disk('local')->put('voice-cloning/sample.wav', 'sample-bytes');
        Storage::disk('local')->put('voice-cloning/consent.wav', 'consent-bytes');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'voice' => ['name' => 'voices/voice_nested123'],
            ], 200),
        ]);

        $voice = ClonedVoice::factory()->create([
            'source_audio_path' => 'voice-cloning/sample.wav',
            'consent_audio_path' => 'voice-cloning/consent.wav',
        ]);

        $result = app(VoiceCloningService::class)->generateVoiceCloningKey($voice);

        $this->assertTrue($result['success']);
        $this->assertSame('voice_nested123', $result['voice_cloning_key']);
    }
}
