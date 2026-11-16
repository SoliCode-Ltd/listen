<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Agents\AgentAccess;
use App\Support\PlanPricingLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Voice agents on the paid plans as the live site sells them: every plan but Basic, with its own
 * agent settings, and the agents line on each plan's hand-written pricing card, written from the
 * settings so it follows them. The Agents page reads the same settings; and the two decisions an
 * agent is set up with are on its page, to change.
 */
class AgentLaunchPlansTest extends TestCase
{
    use RefreshDatabase;

    private function livePlans(): void
    {
        $own = fn (string $locale) => $locale === 'en'
            ? [['label' => 'Generations per day', 'value' => '100', 'icon' => 'activity'], ['label' => 'Voice quality', 'value' => 'Premium', 'icon' => 'mic'], ['label' => 'Commercial use', 'value' => 'Allowed', 'icon' => 'lock'], ['label' => 'Developer API', 'value' => 'Included', 'icon' => 'check']]
            : [['label' => 'التوليدات يوميًا', 'value' => '100', 'icon' => 'activity'], ['label' => 'جودة الصوت', 'value' => 'متميزة', 'icon' => 'mic'], ['label' => 'الاستخدام التجاري', 'value' => 'مسموح', 'icon' => 'lock']];
        Plan::factory()->free()->create(['monthly_chars' => 5000]);
        foreach (['basic' => [400, 18000], 'starter' => [900, 90000], 'creator' => [2200, 180000], 'pro' => [4900, 450000], 'studio_max' => [9900, 1800000]] as $slug => [$price, $credits]) {
            // Basic is already there (a migration turned the one-time pack into it).
            $plan = Plan::query()->where('slug', $slug)->first() ?? Plan::factory()->make(['slug' => $slug]);
            $plan->forceFill(['name' => ucfirst(str_replace('_', ' ', $slug)), 'price_cents' => $price, 'monthly_chars' => $credits, 'is_active' => true, 'kind' => Plan::KIND_SUBSCRIPTION,
                'pricing_use_custom_text' => true, 'features_en' => $own('en'), 'features_ar' => $own('ar')])->save();
        }
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_21_120000_put_voice_agents_on_every_paid_plan.php'))->up();
    }

    public function test_every_paid_plan_but_basic_gets_agents_and_a_card_line_that_follows_its_settings(): void
    {
        $this->livePlans();
        $this->migrate();
        $this->migrate(); // twice: nothing is added again

        $plans = Plan::query()->get()->keyBy('slug');
        $this->assertFalse((bool) $plans['basic']->agents_enabled);
        $this->assertFalse((bool) $plans['free']->agents_enabled);
        foreach (['starter' => [1, 0, 2, 5], 'creator' => [3, 0, 3, 8], 'pro' => [5, 0, 5, 10], 'studio_max' => [10, 600, 10, 10]] as $slug => [$agents, $minutes, $concurrent, $longest]) {
            $plan = $plans[$slug];
            $this->assertTrue((bool) $plan->agents_enabled, $slug);
            $this->assertSame([$agents, $minutes, $concurrent, $longest], [(int) $plan->agents_max, (int) $plan->agent_minutes_per_month, (int) $plan->agent_concurrent_calls, (int) $plan->agent_max_call_minutes], $slug);
            // On the plan's own lists, once, just before the commercial-use line.
            $en = $plan->features_en;
            $this->assertSame(1, collect($en)->where('value', '{agents}')->count(), $slug);
            $this->assertSame('Voice agents', $en[2]['label']);
            $this->assertSame('Commercial use', $en[3]['label']);
            $this->assertSame('الوكلاء الصوتيون', $plan->features_ar[2]['label']);
        }
        $this->assertSame(0, collect($plans['basic']->features_en)->where('value', '{agents}')->count());

        // The card writes the line from the settings; a change shows at once.
        $rate = AgentAccess::creditsPerMinute();
        $creator = $plans['creator'];
        $line = collect(PlanPricingLines::forPlan($creator, 'en'))->firstWhere('label', 'Voice agents');
        $this->assertSame('3 agents', $line['value'], 'the agents only: the call minutes are in the comparison table');
        $creator->forceFill(['agents_max' => 4, 'agent_minutes_per_month' => 50])->save();
        $this->assertSame('4 agents', collect(PlanPricingLines::forPlan($creator->fresh(), 'en'))->firstWhere('label', 'Voice agents')['value']);
        $this->assertStringContainsString('4 وكلاء', collect(PlanPricingLines::forPlan($creator->fresh(), 'ar'))->firstWhere('label', 'الوكلاء الصوتيون')['value']);

        // Not while Agents are closed, and never on a plan without them.
        SiteSetting::set('agents_audience', AgentAccess::AUDIENCE_OFF, 'agents');
        $this->assertNull(collect(PlanPricingLines::forPlan($creator->fresh(), 'en'))->firstWhere('label', 'Voice agents'));
        SiteSetting::set('agents_audience', AgentAccess::AUDIENCE_EVERYONE, 'agents');
        $creator->forceFill(['agents_enabled' => false])->save();
        $this->assertNull(collect(PlanPricingLines::forPlan($creator->fresh(), 'en'))->firstWhere('label', 'Voice agents'));

        // Rolled back: the lines go, the plans' other lines stay.
        (require database_path('migrations/2026_11_21_120000_put_voice_agents_on_every_paid_plan.php'))->down();
        $this->assertSame(['Generations per day', 'Voice quality', 'Commercial use', 'Developer API'], collect(Plan::where('slug', 'pro')->sole()->features_en)->pluck('label')->all());
    }

    public function test_the_agents_page_lists_each_plan_with_what_its_settings_give(): void
    {
        $this->livePlans();
        $this->migrate();
        SiteSetting::set('agents_credits_per_minute', '1900', 'agents');

        $page = (string) $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->withSession(['locale' => 'en'])->get($this->localizedRoute('agents-landing'))->assertOk()->getContent();
        $section = substr($page, strpos($page, 'agl-price-title'), 9000);
        foreach (['Starter' => 47, 'Creator' => 94, 'Pro' => 236, 'Studio Max' => 600] as $name => $minutes) {
            $this->assertStringContainsString('<h3>'.$name.'</h3>', $section);
            $this->assertStringContainsString('<b dir="ltr">'.number_format($minutes).'</b>', $section);
        }
        $this->assertStringNotContainsString('<h3>Basic</h3>', $section);
        $this->assertStringContainsString('10 calls at the same time', $section);
        $this->assertStringContainsString('Calls up to 8 minutes long', $section);
        $this->assertStringContainsString('1,900 credits', $section);

        // A setting changed in the admin shows here at once.
        Plan::where('slug', 'creator')->update(['agents_max' => 7]);
        $page = (string) $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->withSession(['locale' => 'en'])->get($this->localizedRoute('agents-landing'))->getContent();
        $this->assertStringContainsString('7 agents', substr($page, strpos($page, 'agl-price-title'), 9000));
    }

    public function test_the_two_decisions_are_on_the_agents_setup_tab_and_keep_their_options(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'en', 'dialect' => 'en-US', 'status' => Agent::DRAFT,
            'choices' => [['question' => 'If a caller asks for a discount?', 'options' => ['Explain prices are fixed', 'Take their number'], 'answer' => 'Explain prices are fixed']],
        ]);

        $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk()
            ->assertSee(__('agents.decisions_title'))
            ->assertSee('data-question="If a caller asks for a discount?"', false)
            ->assertSee('value="Take their number"', false);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->actingAs($owner)->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), [
                'choices' => [['question' => 'If a caller asks for a discount?', 'answer' => 'Take their number']],
            ])->assertOk();
        $choice = $agent->fresh()->choices[0];
        $this->assertSame('Take their number', $choice['answer']);
        $this->assertSame(['Explain prices are fixed', 'Take their number'], $choice['options']);
    }

    public function test_the_setup_never_says_it_reads_a_website_that_was_not_given_and_asks_no_extra_questions(): void
    {
        $owner = User::factory()->create();
        $html = (string) $this->actingAs($owner)->getLocalizedRoute('voice-agents.create')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ag-decisions', $html);
        $this->assertStringContainsString('data-site="'.e(__('agents.writing_slow_site')).'"', $html);
        $this->assertStringContainsString('data-plain="'.e(__('agents.writing_slow')).'"', $html);
        $this->assertStringContainsString(__('agents.write_agent'), $html);
        $this->assertStringNotContainsString('Write my agent', $html);
        // The language switch beside the dialect, the note that any voice speaks it under both.
        $this->assertStringContainsString('ag-seg ag-seg-fit', $html);
        $this->assertSame(2, substr_count($html, 'class="ag-speaks-note"'));
    }
}
