<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/** Addresses people guess lead somewhere, and the Terms say what the voice cloning flow asks for. */
class SiteHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guessed_api_addresses_lead_to_the_developer_docs(): void
    {
        // Routes are registered under the language of the test run, as each language is on the site.
        $docs = $this->localizedRoute('developers');
        $prefix = substr($docs, 0, -strlen('/developers'));
        foreach (['api', 'api-docs', 'docs'] as $guess) {
            $response = $this->withoutMiddleware([
                LocaleSessionRedirect::class,
                LaravelLocalizationRedirectFilter::class,
                LaravelLocalizationViewPath::class,
            ])->get("{$prefix}/{$guess}");
            $response->assertStatus(301);
            $this->assertSame($docs, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), "{$prefix}/{$guess}");
        }
        $this->getLocalizedRoute('developers')->assertOk();
    }

    public function test_the_terms_cover_voice_cloning_consent_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = $this->getLocalizedRoute('legal.terms')->assertOk();
            $page->assertSee('id="voice-cloning"', false)
                ->assertSee(__('ui.legal.terms.clone_title'))
                ->assertSee(__('ui.legal.terms.clone_1'))
                ->assertSee(__('ui.legal.terms.clone_3'));
        }
    }
}
