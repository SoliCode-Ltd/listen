<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The Agents page for visitors: in both languages, every dialect's call is
 * on it, its button starts the right way, and it names no other company.
 */
class AgentLandingTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $locale): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->withSession(['locale' => $locale])
            ->get($this->localizedRoute('agents-landing'));
    }

    public function test_it_shows_the_call_in_every_dialect_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = $this->page($locale)->assertOk();
            $page->assertSee(__('agents_landing.hero_title'));
            // The real recorded calls, each under its own tab; the first is written into the page.
            $order = __('agents_landing.real_order');
            foreach ($order as $key) {
                $page->assertSee(__('agents_landing.real_calls.'.$key.'.label'));
            }
            $first = collect(__('agents_landing.real_calls.'.$order[0].'.turns'))->firstWhere(0, 'agent');
            $page->assertSee(\App\Support\Agents\SpokenNumbers::forDisplay($first[1], $locale === 'ar'))
                ->assertSee('data-agl-real', false)
                // How it works is drawn, not old screenshots; the call inbox and the channels follow.
                ->assertSee(__('agents_landing.steps_title'))
                ->assertDontSee('images/agents/landing/', false)
                ->assertSee(__('agents_landing.calls_title'))
                ->assertSee(__('agents_landing.speaks_title'))
                ->assertSee(__('agents_landing.flow_sources')[0])
                // The call's play button has no progress line under it.
                ->assertDontSee('agl-real-progress', false);
            // Every dialect (or accent) is in the full list, by name.
            $names = $locale === 'ar' ? array_column(\App\Support\ArabicDialects::ALL, 'ar') : array_column(\App\Support\EnglishAccents::ALL, 'en');
            foreach ($names as $name) {
                $page->assertSee($name);
            }
            // The questions are the page's last section, after the final call to action.
            $html = (string) $page->getContent();
            $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
            $faq = (int) strpos($main, 'id="agl-faq-title"');
            $this->assertGreaterThan((int) strpos($main, 'class="agl-final"'), $faq, $locale.': the questions come before the final call to action');
            $this->assertStringNotContainsString('<section', substr($main, $faq), $locale.': a section follows the questions');
        }
    }

    public function test_its_button_signs_up_visitors_into_creating_an_agent(): void
    {
        $this->page('en')->assertSee(e(route('register', ['next' => 'agents'])), false);

        $member = User::factory()->create();
        $this->actingAs($member);
        $this->page('en')->assertSee(route('voice-agents.create'), false);

        Agent::create(['user_id' => $member->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare', 'language' => 'ar', 'status' => Agent::DRAFT]);
        $this->page('en')->assertSee(route('voice-agents.index'), false)->assertSee(__('agents_landing.hero_cta_member'));
    }

    public function test_it_names_no_other_company_or_product(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = (string) $this->page($locale)->assertOk()->getContent();
            $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html) ?? '');
            foreach (['WordPress', 'Shopify', 'Wix', 'Laravel', 'Salla', 'Zid', 'Gemini', 'Google', 'Nabrah', 'ووردبريس', 'شوبيفاي', 'نبرة'] as $name) {
                $this->assertStringNotContainsStringIgnoringCase($name, $text, "{$locale} page names {$name}");
            }
        }
    }

    public function test_it_is_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('/voice-agents', false);
    }
}
