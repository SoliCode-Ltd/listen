<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A search landing page plays recordings about its own subject: a dialect page its dialect,
 * the dialogue page two-voice dialogues, the emotions page one voice per emotion. They are
 * the samples the site already has, on the homepage's card player.
 */
class SeoPageSamplesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('tts:showcase', ['--import' => true]);
        // The dialogue page exists while a plan on sale includes dialogue.
        Plan::factory()->create(['slug' => 'studio', 'name_en' => 'Studio', 'price_cents' => 4999, 'dialogue_enabled' => true]);
    }

    public function test_a_dialect_page_plays_only_its_own_dialect(): void
    {
        $html = (string) $this->page('en', 'seo.dialect', ['dialect' => 'egyptian'])->assertOk()->getContent();
        $this->assertStringContainsString('class="sp-stage"', $html);
        preg_match_all('#data-src="[^"]*/showcase/([a-z0-9-]+)\.mp3#', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $slug) {
            $this->assertMatchesRegularExpression('/^(dlg-)?ar-eg/', $slug, $slug);
        }
        // The player and its styles are on the page, and no bare browser player is left.
        $this->assertStringContainsString('css/voice-showcase.css', $html);
        $this->assertStringNotContainsString('<audio controls', $html);

        $british = (string) $this->page('ar', 'seo.accent', ['accent' => 'british'])->assertOk()->getContent();
        $this->assertStringContainsString('/showcase/en-', $british);
        $this->assertStringNotContainsString('/showcase/ar-', $british);
    }

    public function test_the_dialogue_page_plays_dialogues_and_the_emotions_page_names_each_mood(): void
    {
        $dialogue = (string) $this->page('ar', 'seo.feature', ['feature' => 'two-voice-dialogue'])->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($dialogue, 'data-vp-dialogue data-src='));
        $this->assertStringNotContainsString('<article class="vs-card"', $dialogue);

        $emotions = (string) $this->page('en', 'seo.feature', ['feature' => 'text-to-speech-with-emotions'])->assertOk()->getContent();
        preg_match_all('#<span class="sp-mood">([^<]+)</span>#', $emotions, $m);
        $this->assertGreaterThanOrEqual(4, count($m[1]));
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'each emotion once');
    }

    public function test_a_page_with_no_recording_still_opens(): void
    {
        VoiceSample::query()->delete();
        $html = (string) $this->page('en', 'seo.feature', ['feature' => 'two-voice-dialogue'])->assertOk()->getContent();
        $this->assertStringNotContainsString('class="sp-stage"', $html);
        $this->assertStringContainsString('<h1>', $html);
    }

    private function page(string $locale, string $route, array $parameters = []): TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route, $parameters);
    }
}
