<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NVIDIA Inception, the one other company the site names (CLAUDE.md): the strip right under the homepage
 * hero and the badge in the footer, in English and Arabic, with NVIDIA's own badge file.
 */
class HomeInceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_strip_sits_right_under_the_hero_in_both_languages(): void
    {
        app()->setLocale('en');
        $en = $this->getLocalizedRoute('home')->assertOk();
        $en->assertSee('Listen is a member of <b>NVIDIA Inception</b>, a program for startups building AI solutions.', false)
            ->assertSee('images/partners/nvidia-inception.svg', false)
            ->assertSee('alt="NVIDIA Inception Program"', false);
        $html = $en->getContent();
        $hero = strpos($html, '<section class="hero">');
        $strip = strpos($html, '<section class="inception-strip"');
        $this->assertNotFalse($strip);
        $this->assertGreaterThan($hero, $strip);
        // Nothing between the hero's end and the strip.
        $this->assertSame(0, substr_count(substr($html, $hero + 1, $strip - $hero), '<section'));

        app()->setLocale('ar');
        $this->getLocalizedRoute('home')->assertOk()
            ->assertSee('إسمع عضو في برنامج <b>إنسبشن من إنفيديا</b> للشركات الناشئة في مجال الذكاء الاصطناعي.', false);
    }

    public function test_lines_flow_out_of_both_sides_of_the_badge_and_stand_still_for_reduced_motion(): void
    {
        app()->setLocale('en');
        $html = (string) $this->getLocalizedRoute('home')->assertOk()->getContent();

        // Lines on each side of the badge, longer waves for wide screens and shorter for phones, hidden from screen readers.
        $this->assertSame(2, substr_count($html, 'class="inception-wave inception-wave-start '));
        $this->assertSame(4, substr_count($html, '<span class="inception-wave '));
        $this->assertSame(4, substr_count($html, 'aria-hidden="true"><svg style="--w:150px;') + substr_count($html, 'aria-hidden="true"><svg style="--w:63px;'));
        $this->assertSame(4 * 3, substr_count($html, '<svg style="--w:'));
        // They touch the badge: the row has no gap.
        $this->assertDoesNotMatchRegularExpression('/\.inception-row \{[^}]*gap:/', $html);
        // They only move for visitors who have not asked for less motion.
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.inception-wave svg \{[^}]*animation: inceptionDrift/', $html);
    }

    public function test_the_footer_shows_the_badge_on_every_page(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->getLocalizedRoute('pricing')->assertOk()
                ->assertSee('class="footer-inception"', false)
                ->assertSee('images/partners/nvidia-inception.svg', false);
        }
    }

    public function test_the_badge_is_nvidias_own_file_framed_to_its_outline(): void
    {
        $svg = (string) file_get_contents(public_path('images/partners/nvidia-inception.svg'));
        $this->assertStringContainsString('viewBox="25.4 25.3 449.6 165.4"', $svg);
        // NVIDIA's green, unchanged.
        $this->assertStringContainsString('#76b900', $svg);
    }

    public function test_search_engines_read_the_membership_on_every_page_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['home', 'pricing', 'agents-landing'] as $route) {
                app()->setLocale($locale);
                $organization = collect($this->jsonLd((string) $this->getLocalizedRoute($route)->assertOk()->getContent()))
                    ->firstWhere('@type', 'Organization');
                $membership = $organization['memberOf'][0] ?? null;
                $this->assertNotNull($membership, "{$locale} {$route}");
                $this->assertSame('ProgramMembership', $membership['@type']);
                $this->assertSame('NVIDIA Inception', $membership['programName']);
                $this->assertSame('https://www.nvidia.com/en-us/startups/', $membership['url']);
                $this->assertSame('NVIDIA', $membership['hostingOrganization']['name']);
            }
        }
    }

    public function test_ai_assistants_read_it_in_llms_txt_and_the_homes_markdown(): void
    {
        $llms = (string) $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Listen is a member of NVIDIA Inception, NVIDIA\'s program for startups building AI solutions.', $llms);
        $this->assertDoesNotMatchRegularExpression('/gemini|google/i', $llms, 'the speech provider is never named');

        app()->setLocale('en');
        $markdown = (string) $this->withHeaders(['Accept' => 'text/markdown'])->getLocalizedRoute('home')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Listen is a member of \*\*NVIDIA Inception\*\*, a program for startups building AI solutions\./', $markdown);
    }

    /**
     * Every node of the page's JSON-LD.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $nodes = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            foreach ($data['@graph'] ?? [$data] as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
