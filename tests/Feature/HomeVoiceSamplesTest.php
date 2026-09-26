<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeVoiceSamplesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_voice_samples_respect_admin_flags_order_and_limit(): void
    {
        SiteSetting::set('landing_voice_samples_limit', '2', 'content');

        VoiceSample::create([
            'title' => 'Featured English Title',
            'title_ar' => 'AR Featured',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 5,
        ]);

        VoiceSample::create([
            'title' => 'Regular B',
            'title_ar' => 'AR Second',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        VoiceSample::create([
            'title' => 'Regular A',
            'title_ar' => 'AR First',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        VoiceSample::create([
            'title' => 'Inactive',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => true,
            'is_active' => false,
            'sort_order' => 0,
        ]);

        VoiceSample::create([
            'title' => 'Wrong Category',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'demo',
            'emotion' => 'custom_style',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app()->setLocale('ar');
        $response = $this->getLocalizedRoute('home');
        $response->assertOk();

        $voiceSamples = $response->viewData('voiceSamples');
        $this->assertIsArray($voiceSamples);
        $this->assertCount(2, $voiceSamples);
        $this->assertSame('AR Featured', $voiceSamples[0]['title']);
        $this->assertSame('AR First', $voiceSamples[1]['title']);
        $this->assertTrue($voiceSamples[0]['is_featured']);
        $this->assertFalse($voiceSamples[1]['is_featured']);
    }

    public function test_homepage_uses_english_samples_for_english_locale_and_arabic_for_default(): void
    {
        SiteSetting::set('landing_voice_samples_limit', '12', 'content');

        VoiceSample::create([
            'title' => 'Arabic sample',
            'title_ar' => 'AR Sample',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        VoiceSample::create([
            'title' => 'English sample',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'en',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app()->setLocale('en');
        $englishResponse = $this->getLocalizedRoute('home');
        $englishResponse->assertOk();
        $englishSamples = $englishResponse->viewData('voiceSamples');
        $this->assertCount(1, $englishSamples);
        $this->assertSame('en', $englishSamples[0]['language']);

        app()->setLocale('fr');
        $defaultResponse = $this->getLocalizedRoute('home');
        $defaultResponse->assertOk();
        $defaultSamples = $defaultResponse->viewData('voiceSamples');
        $this->assertCount(1, $defaultSamples);
        $this->assertSame('ar', $defaultSamples[0]['language']);
    }

    public function test_guest_and_authenticated_users_get_same_homepage_voice_samples(): void
    {
        SiteSetting::set('landing_voice_samples_limit', '12', 'content');

        VoiceSample::create([
            'title' => 'Sample One',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        VoiceSample::create([
            'title' => 'Sample Two',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        app()->setLocale('ar');
        $guestResponse = $this->getLocalizedRoute('home');
        $guestResponse->assertOk();
        $guestSamples = $guestResponse->viewData('voiceSamples');

        $user = User::factory()->create();
        $authResponse = $this->actingAs($user)->getLocalizedRoute('home');
        $authResponse->assertOk();
        $authSamples = $authResponse->viewData('voiceSamples');

        $this->assertSame($guestSamples, $authSamples);
    }

    public function test_homepage_can_show_hero_audio_without_showing_same_record_in_showcase(): void
    {
        SiteSetting::set('landing_voice_samples_limit', '12', 'content');

        VoiceSample::create([
            'title' => 'Hero Only',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => true,
            'is_active' => true,
            'show_in_hero' => true,
            'show_in_showcase' => false,
            'sort_order' => 0,
        ]);

        VoiceSample::create([
            'title' => 'Showcase Only',
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'category' => 'hero',
            'emotion' => 'custom_style',
            'is_featured' => false,
            'is_active' => true,
            'show_in_hero' => false,
            'show_in_showcase' => true,
            'sort_order' => 1,
        ]);

        app()->setLocale('ar');
        $response = $this->getLocalizedRoute('home');
        $response->assertOk();

        $heroVoiceSample = $response->viewData('heroVoiceSample');
        $voiceSamples = $response->viewData('voiceSamples');

        $this->assertSame('Hero Only', $heroVoiceSample['title']);
        $this->assertCount(1, $voiceSamples);
        $this->assertSame('Showcase Only', $voiceSamples[0]['title']);
    }

    public function test_homepage_announces_voice_cloning_under_the_hero_and_links_to_pricing(): void
    {
        app()->setLocale('ar');
        $arabic = $this->getLocalizedRoute('home');
        $arabic->assertOk();
        $arabic->assertSee('id="cloneAnnounceTitle"', false);
        $arabic->assertSee('استنساخ الصوت متاح الآن', false);
        $arabic->assertSee('سجّل صوتك، ثم استخدمه في كل توليد', false);
        $arabic->assertSee('عرض الأسعار', false);
        $arabic->assertSee('href="'.route('pricing').'"', false);
        $arabic->assertDontSee('صار جاهزاً', false);

        app()->setLocale('en');
        $english = $this->getLocalizedRoute('home');
        $english->assertOk();
        $english->assertSee('Voice cloning is now available.', false);
        $english->assertSee('Record your voice, then use it in every generation.', false);
        $english->assertSee('View pricing', false);
        $english->assertSee('href="'.route('pricing').'"', false);
    }
}
