<?php

namespace Tests\Unit;

use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\VoiceCloningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_max_voices_for_plan_uses_exact_plan_value_including_zero(): void
    {
        SiteSetting::set('voice_cloning_max_per_user', 9);

        $plan = Plan::factory()->paid()->create([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 0,
        ]);

        $this->assertEquals(0, VoiceCloningService::maxVoicesForPlan($plan));
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
        $this->assertNotEmpty($scripts['en']);
        $this->assertNotEmpty($scripts['ar']);
    }
}
