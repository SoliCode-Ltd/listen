<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MemberReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The homepage's reach section: live member and country counts, a claim that stays true, and the globe's lights. */
class HomeReachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MemberReach::forget();
    }

    /** @param  array<string, int>  $perCountry */
    private function members(array $perCountry, int $withoutCountry = 0): void
    {
        foreach ($perCountry as $code => $n) {
            $ids = User::factory()->count($n)->create()->pluck('id');
            DB::table('users')->whereIn('id', $ids)->update(['country_code' => $code]);
        }
        if ($withoutCountry > 0) {
            User::factory()->count($withoutCountry)->create();
        }
    }

    public function test_the_homepage_shows_members_and_countries_from_the_members_themselves(): void
    {
        $this->members(['EG' => 60, 'SA' => 30, 'US' => 10, 'DE' => 8, 'FR' => 7, 'MA' => 5], 3);
        // An admin is not a member.
        $admin = User::factory()->create(['is_admin' => true]);
        DB::table('users')->where('id', $admin->id)->update(['country_code' => 'GB']);

        $this->assertSame(123, MemberReach::counts()['members']);
        $this->assertSame(6, MemberReach::countryCount());
        $this->assertSame(100, MemberReach::membersRoundedDown()); // never more than there are

        $page = $this->getLocalizedRoute('home')->assertOk();
        $page->assertSee('id="reachTitle"', false);
        $page->assertSee('data-count="6" data-kind="countries">6</bdi>', false);
        $page->assertSee('js/home-globe.js', false);
        $page->assertSee('data/globe-land.json', false);
    }

    public function test_the_member_count_is_rounded_down_and_written_the_way_each_language_writes_it(): void
    {
        $this->members(['EG' => 12, 'SA' => 4, 'US' => 2, 'DE' => 1, 'FR' => 1]);
        // 9,483 members as the page would count them, without making 9,483 rows.
        cache()->put('member_reach:v1', ['members' => 9483, 'countries' => MemberReach::counts()['countries']], 60);
        $this->assertSame(9000, MemberReach::membersRoundedDown());

        app()->setLocale('en');
        $en = view('components.home.reach')->render();
        $this->assertStringContainsString('data-count="9000" data-plus="after">9,000+</bdi>', $en);
        $this->assertStringContainsString('data-count="5" data-kind="countries">5</bdi>', $en);

        app()->setLocale('ar');
        $ar = view('components.home.reach')->render();
        $this->assertStringContainsString('data-count="9000" data-plus="before">+9,000</bdi>', $ar);
        $this->assertStringContainsString('حول العالم', $ar);
    }

    public function test_only_member_countries_light_and_more_members_light_brighter(): void
    {
        $this->members(['EG' => 520, 'US' => 60, 'FR' => 3, 'DE' => 1, 'MA' => 1]);
        $lit = collect(MemberReach::markers())->keyBy('code');

        $this->assertSame(['DE', 'EG', 'FR', 'MA', 'US'], $lit->keys()->sort()->values()->all());
        $this->assertSame(3, $lit['EG']['tier']);
        $this->assertSame(2, $lit['US']['tier']);
        $this->assertSame(1, $lit['FR']['tier']);
        // The wave starts in the Arab world and reaches the Americas last.
        $this->assertLessThan($lit['US']['delay'], $lit['EG']['delay']);
        // Each light sits on its country's main land: France's, not French Guiana's.
        [$lat, $lon] = $lit['FR']['at'];
        $this->assertEqualsWithDelta(46.6, $lat, 1.0);
        $this->assertEqualsWithDelta(2.4, $lon, 1.0);
    }

    public function test_each_light_carries_its_country_name_in_the_page_language(): void
    {
        $this->members(['EG' => 60, 'SA' => 30, 'US' => 10, 'DE' => 8, 'FR' => 7]);

        app()->setLocale('en');
        $en = view('components.home.reach')->render();
        $this->assertStringContainsString('"name":"Egypt"', $en);
        $this->assertStringContainsString('"flag":"eg"', $en);

        app()->setLocale('ar');
        $ar = view('components.home.reach')->render();
        // @json writes Arabic as \u escapes inside the attribute; the browser reads them back as مصر.
        $this->assertStringContainsString(substr(json_encode(['name' => 'مصر']), 1, -1), $ar);
    }

    public function test_the_section_stays_off_until_there_is_something_to_show(): void
    {
        $this->members(['EG' => 20, 'SA' => 10]);

        $this->assertFalse(MemberReach::show());
        $this->getLocalizedRoute('home')->assertOk()->assertDontSee('id="reachTitle"', false);
    }
}
