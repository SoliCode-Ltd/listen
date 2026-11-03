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
            foreach (__('agents_landing.scene_order') as $key) {
                $page->assertSee(__('agents_landing.scenes.'.$key.'.label'));
            }
            // The first call is written into the page, for readers without scripts.
            $page->assertSee(__('agents_landing.scenes.'.__('agents_landing.scene_order')[0].'.lines')[0][1]);
            $page->assertSee('data-agl-call', false)
                // The steps over the real setup, the bento and the channels.
                ->assertSee('images/agents/landing/'.$locale.'/step-1.webp', false)
                ->assertSee('data-agl-bento', false)
                ->assertSee(__('agents_landing.flow_sources')[0]);
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
