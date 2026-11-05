<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentSource;
use App\Models\User;
use App\Services\Agents\AgentReview;
use App\Support\Agents\AgentInstructions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * An agent knows today's date, never offers what is over as coming (a test call offered a
 * seminar of 2025 as the next one, in 2026), and puts the newest facts first: the owner's latest
 * updates, then their notes and corrections, the newest files, and the website last.
 */
class AgentFreshFactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'UTC'));
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Nawaf', 'business_name' => 'Saudi Ophthalmological Society', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'status' => Agent::READY,
            'knowledge' => "Seminars: Myopia Control Seminar 2025.\n\n# Corrected by the owner\nMembership is 300 SAR a year.",
        ], $attributes));
    }

    private function patchAgent(Agent $agent, array $data): TestResponse
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), $data);
    }

    public function test_every_call_knows_todays_date_where_the_business_is(): void
    {
        $owner = User::factory()->create();
        $saudi = AgentInstructions::build($this->agent($owner));
        $this->assertStringContainsString("# Today\nToday is Thursday, 5 November 2026", $saudi);
        $this->assertStringContainsString('1:00 pm in Riyadh.', $saudi);
        if (class_exists(\IntlDateFormatter::class)) {
            $this->assertMatchesRegularExpression('/\(\d{1,2} [A-Za-z\' .]+ 1448 AH\)/', $saudi, 'the Islamic date beside it');
        }

        // Late on 4 November in New York it is still the 4th there.
        Carbon::setTestNow(Carbon::parse('2026-11-05 03:30:00', 'UTC'));
        $us = AgentInstructions::build($this->agent($owner, ['language' => 'en', 'dialect' => 'en-US-NY']));
        $this->assertStringContainsString('Today is Wednesday, 4 November 2026, and the time is 10:30 pm in New York.', $us);
        // An accent of a second language says nothing of where the business is.
        $this->assertSame('Asia/Riyadh', AgentInstructions::timezone($this->agent($owner, ['language' => 'en', 'dialect' => 'en-FR'])));
        $this->assertSame('Africa/Cairo', AgentInstructions::timezone($this->agent($owner, ['dialect' => 'ar-EG'])));
        $this->assertSame('Asia/Riyadh', AgentInstructions::timezone($this->agent($owner, ['dialect' => 'ar-XA-WH'])));
    }

    public function test_what_is_over_is_never_offered_as_coming_and_the_newer_fact_wins(): void
    {
        $text = AgentInstructions::build($this->agent(User::factory()->create()));

        $this->assertStringContainsString('dated before today is over', $text);
        $this->assertStringContainsString('an event named for 2025 is over in 2026', $text);
        $this->assertStringContainsString('When nothing in the facts is after today, say the next date has not been announced yet', $text);
        $this->assertStringContainsString('When two facts disagree, the newer one wins', $text);
        $this->assertStringContainsString('never argue or insist', $text);
        $this->assertStringContainsString('lines under "Corrected by the owner" are newer than the rest', $text);
    }

    public function test_the_owners_latest_updates_come_first_with_their_date_and_win(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['website_url' => 'https://example.org']);
        AgentSource::create(['agent_id' => $agent->id, 'kind' => AgentSource::WEBSITE, 'url' => 'https://example.org', 'content' => 'old', 'status' => AgentSource::READY, 'read_at' => now()->subMonths(3)]);
        $this->actingAs($owner);

        $this->patchAgent($agent, ['updates' => 'الندوة القادمة: ندوة جراحة الشبكية يوم 20 ديسمبر 2026 في الرياض.'])
            ->assertOk()->assertJsonPath('agent.updates', 'الندوة القادمة: ندوة جراحة الشبكية يوم 20 ديسمبر 2026 في الرياض.')
            ->assertJsonPath('agent.updates_at', __('agents.updates_at', ['date' => now()->locale(app()->getLocale())->translatedFormat('j F Y')]));

        $agent->refresh();
        $this->assertSame(now()->toIso8601String(), $agent->setting('updates_at'));
        $text = AgentInstructions::build($agent);
        $updates = strpos($text, '# Latest updates from Saudi Ophthalmological Society (written on 5 November 2026)');
        $this->assertNotFalse($updates);
        $this->assertStringContainsString('where it differs from anything below, this wins', $text);
        $this->assertStringContainsString('ندوة جراحة الشبكية يوم 20 ديسمبر 2026', $text);
        $this->assertLessThan(strpos($text, '# Facts about'), $updates, 'the updates come before the other facts');
        $this->assertStringContainsString('(written from its website as read on 5 August 2026', $text);
        // The check before going live reads them too.
        $this->assertStringContainsString('Latest updates from the owner: الندوة القادمة', AgentReview::material($agent));

        // Saving the same words again keeps their date; emptying them takes the section away.
        $this->travel(2)->days();
        $this->patchAgent($agent, ['updates' => 'الندوة القادمة: ندوة جراحة الشبكية يوم 20 ديسمبر 2026 في الرياض.'])->assertOk();
        $this->assertSame(Carbon::parse('2026-11-05 10:00:00', 'UTC')->toIso8601String(), $agent->fresh()->setting('updates_at'));
        $this->patchAgent($agent, ['updates' => ''])->assertOk()->assertJsonPath('agent.updates_at', '');
        $this->assertStringNotContainsString('# Latest updates', AgentInstructions::build($agent->fresh()));
    }

    public function test_each_file_says_when_it_was_uploaded(): void
    {
        $agent = $this->agent(User::factory()->create());
        $file = AgentSource::create(['agent_id' => $agent->id, 'kind' => AgentSource::FILE, 'title' => 'Program 2026.pdf', 'content' => 'Day one: cornea.', 'status' => AgentSource::READY]);
        $file->forceFill(['created_at' => Carbon::parse('2026-10-01 09:00:00', 'UTC')])->save();

        $this->assertStringContainsString("## Program 2026.pdf (uploaded on 1 October 2026)\nDay one: cornea.", AgentInstructions::build($agent));
    }

    public function test_the_knowledge_tab_has_the_latest_updates_box_first(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['updates' => 'التسجيل مفتوح حتى 15 ديسمبر.', 'updates_at' => now()->toIso8601String()]]);
        app()->setLocale('ar');

        $html = (string) $this->actingAs($owner)
            ->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()->getContent();
        $box = strpos($html, 'data-ag-updates-form');
        $this->assertNotFalse($box);
        $this->assertLessThan(strpos($html, 'data-ag-knowledge-form'), $box);
        $this->assertStringContainsString(e(__('agents.updates_title')), $html);
        $this->assertStringContainsString('التسجيل مفتوح حتى 15 ديسمبر.', $html);
    }
}
