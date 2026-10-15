<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\AudioEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Tests\TestCase;

class HomeEnvironmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sample_names_a_real_environment_in_both_languages(): void
    {
        $samples = (require database_path('seeders/data/environment_samples.php'))['samples'];
        $this->assertCount(6, array_filter($samples, fn ($s) => $s['language'] === 'ar'));
        $this->assertCount(6, array_filter($samples, fn ($s) => $s['language'] === 'en'));
        foreach ($samples as $sample) {
            $this->assertTrue(AudioEffects::isValid($sample['effect']), $sample['slug']);
            $this->assertStringStartsWith('env-'.$sample['language'].'-', $sample['slug']);
        }
    }

    public function test_the_homepage_plays_each_place_clean_or_in_the_place_in_the_page_language(): void
    {
        $made = $this->ensureClips();
        try {
            app()->setLocale('ar');
            $ar = $this->getLocalizedRoute('home')->assertOk()->getContent();
            $this->assertStringContainsString('id="sound-environments"', $ar);
            $this->assertStringContainsString('ضع صوتك في أي مكان', $ar);
            $this->assertStringContainsString('audio/environments/env-ar-stadium.mp3', $ar);
            $this->assertStringContainsString('audio/environments/clean/env-ar-stadium.mp3', $ar);
            $this->assertStringNotContainsString('env-en-stadium', $ar);
            $this->assertGreaterThan(strpos($ar, 'id="dialogues"') ?: 0, strpos($ar, 'id="sound-environments"'), 'after the dialogues');

            app()->setLocale('en');
            $en = $this->getLocalizedRoute('home')->assertOk()->getContent();
            $this->assertStringContainsString('Put your voice anywhere', $en);
            $this->assertStringContainsString('data-vp-mode="clean"', $en);
            $this->assertStringContainsString('env-en-church', $en);
            $this->assertStringNotContainsString('env-ar-', $en);
            $this->assertStringContainsString('class="vs-env-cta" href="'.route('pricing').'"', $en, 'no plan has them yet: the pricing page');
            $this->assertStringContainsString('<bdi dir="ltr">+20</bdi> sound environments', $en);

            Plan::factory()->create(['slug' => 'pro', 'voice_tier' => 'hd', 'is_active' => true, 'price_cents' => 4900, 'sound_effects_enabled' => true]);
            Plan::factory()->create(['slug' => 'studio_max', 'voice_tier' => 'hd', 'is_active' => true, 'price_cents' => 9900, 'sound_effects_enabled' => true]);
            Once::flush();
            app()->setLocale('ar');
            $this->assertStringContainsString('billing?plan=pro', $this->getLocalizedRoute('home')->getContent(), 'the cheapest plan that has them');
        } finally {
            array_map('unlink', $made);
        }
    }

    /**
     * @return array<int, string> files this test created
     */
    private function ensureClips(): array
    {
        $made = [];
        foreach ((require database_path('seeders/data/environment_samples.php'))['samples'] as $sample) {
            foreach (['', 'clean/'] as $dir) {
                $path = public_path('audio/environments/'.$dir.$sample['slug'].'.mp3');
                if (! is_file($path)) {
                    @mkdir(dirname($path), 0775, true);
                    file_put_contents($path, 'mp3');
                    $made[] = $path;
                }
            }
        }

        return $made;
    }
}
