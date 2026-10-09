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

    private function sample(array $attributes): VoiceSample
    {
        return VoiceSample::create($attributes + [
            'sample_text' => 'sample',
            'audio_path' => 'audio/missing.mp3',
            'language' => 'ar',
            'dialect' => 'ar-SA',
            'category' => 'hero',
            'emotion' => 'neutral',
            'is_active' => true,
            'show_in_hero' => true,
            'show_in_showcase' => true,
        ]);
    }

    public function test_homepage_gallery_respects_admin_flags_order_and_limit(): void
    {
        SiteSetting::set('landing_voice_samples_limit', '2', 'content');

        $this->sample(['title' => 'Featured', 'title_ar' => 'AR Featured', 'is_featured' => true, 'sort_order' => 5]);
        $this->sample(['title' => 'Regular B', 'title_ar' => 'AR Second', 'sort_order' => 1]);
        $this->sample(['title' => 'Regular A', 'title_ar' => 'AR First', 'sort_order' => 0]);
        $this->sample(['title' => 'Inactive', 'is_featured' => true, 'is_active' => false]);
        $this->sample(['title' => 'Wrong Category', 'is_featured' => true, 'category' => 'demo']);
        $this->sample(['title' => 'Hidden', 'is_featured' => true, 'show_in_showcase' => false]);

        app()->setLocale('ar');
        $voices = $this->getLocalizedRoute('home')->assertOk()->viewData('showcaseVoices');

        $this->assertSame(['AR Featured', 'AR First'], array_column($voices, 'title'));
    }

    public function test_each_page_plays_only_its_own_language(): void
    {
        $this->sample(['title' => 'Arabic sample', 'title_ar' => 'AR Sample', 'sample_text' => 'نص عربي']);
        $this->sample(['title' => 'English sample', 'language' => 'en', 'dialect' => 'en-GB', 'sample_text' => 'English words']);
        $this->sample(['title' => 'Arabic chat', 'category' => 'dialogue']);
        $this->sample(['title' => 'English chat', 'category' => 'dialogue', 'language' => 'en', 'dialect' => 'en-US']);

        app()->setLocale('en');
        $english = $this->getLocalizedRoute('home')->assertOk();
        $this->assertSame(['English sample'], array_column($english->viewData('showcaseVoices'), 'title'));
        $this->assertSame('British (Standard)', $english->viewData('showcaseVoices')[0]['dialect_label']);
        $this->assertSame(['English chat'], array_column($english->viewData('showcaseDialogues'), 'title'));
        $english->assertSee('English words')->assertDontSee('نص عربي');

        app()->setLocale('ar');
        $arabic = $this->getLocalizedRoute('home')->assertOk();
        $this->assertSame(['AR Sample'], array_column($arabic->viewData('showcaseVoices'), 'title'));
        $this->assertSame('سعودي (نجدي)', $arabic->viewData('showcaseVoices')[0]['dialect_label']);
        $this->assertSame(['Arabic chat'], array_column($arabic->viewData('showcaseDialogues'), 'title'));
        $arabic->assertSee('نص عربي')->assertDontSee('English words');
    }

    public function test_guest_and_authenticated_users_get_same_homepage_voice_samples(): void
    {
        $this->sample(['title' => 'Sample One', 'is_featured' => true, 'sort_order' => 1]);
        $this->sample(['title' => 'Sample Two', 'sort_order' => 2]);

        app()->setLocale('ar');
        $guestSamples = $this->getLocalizedRoute('home')->assertOk()->viewData('showcaseVoices');

        $user = User::factory()->create();
        $authSamples = $this->actingAs($user)->getLocalizedRoute('home')->assertOk()->viewData('showcaseVoices');

        $this->assertSame($guestSamples, $authSamples);
    }

    public function test_hero_is_artwork_and_players_show_no_timer(): void
    {
        $this->sample(['title' => 'Sample One', 'duration_seconds' => 17]);

        app()->setLocale('ar');
        $html = $this->getLocalizedRoute('home')->assertOk()->getContent();

        $hero = substr($html, strpos($html, 'class="hero-art'), 4000);
        $this->assertStringContainsString('class="vs-art"', $hero);
        $this->assertStringNotContainsString('data-vp-track', $hero);
        $this->assertStringNotContainsString('<audio', $hero);
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\sdata-vp-time[\s>]/', $html);
        $this->assertStringNotContainsString('0:17', $html);
    }

    public function test_homepage_announces_voice_cloning_under_the_hero_and_links_to_pricing(): void
    {
        app()->setLocale('ar');
        $arabic = $this->getLocalizedRoute('home');
        $arabic->assertOk();
        $arabic->assertSee('id="cloneAnnounceTitle"', false);
        $arabic->assertSee('استنساخ الصوت متاح الآن', false);
        $arabic->assertSee('سجّل صوتك، ثم استخدمه في كل توليد بأي لهجة تختارها.', false);
        $arabic->assertSee('اختر باقتك', false);
        $arabic->assertSee('href="'.route('pricing').'"', false);
        $arabic->assertDontSee('صار جاهزاً', false);
        $arabicSection = $this->announcementHtml($arabic->getContent());
        $this->assertStringNotContainsString('منصة إسمع', $arabicSection);
        $this->assertStringNotContainsString('عرض الأسعار', $arabicSection);
        $this->assertStringNotContainsString('clone-announce-dialects', $arabicSection);
        $this->assertStringNotContainsString('سعودي', $arabicSection);

        app()->setLocale('en');
        $english = $this->getLocalizedRoute('home');
        $english->assertOk();
        $english->assertSee('Voice cloning is now available.', false);
        $english->assertSee('Record your voice, then use it in every generation, in any dialect you choose.', false);
        $english->assertSee('Choose your plan', false);
        $english->assertSee('href="'.route('pricing').'"', false);
        $englishSection = $this->announcementHtml($english->getContent());
        $this->assertStringNotContainsString('Listen Studio', $englishSection);
        $this->assertStringNotContainsString('View pricing', $englishSection);
        $this->assertStringNotContainsString('clone-announce-dialects', $englishSection);
        $this->assertStringNotContainsString('Saudi', $englishSection);
    }

    public function test_audio_download_converts_wav_into_a_whatsapp_file(): void
    {
        $page = $this->getLocalizedRoute('home');
        $page->assertOk();
        $page->assertSee('function toWhatsAppAudio', false);
        $page->assertSee('lame.min.js', false);
        $page->assertSee('audio/mpeg', false);
    }

    private function announcementHtml(string $html): string
    {
        preg_match('/<section class="clone-announce"[\s\S]*?<\/section>/', $html, $matches);

        return $matches[0] ?? '';
    }
}
