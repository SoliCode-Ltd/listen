<?php

namespace Tests\Feature\Agents;

use App\Models\Voice;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\HouseAgent;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use Database\Seeders\PlanSeeder;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listen's own agent knows the whole product, so a caller who asks for something Listen has (a
 * child's voice, a dialect, a sound place) is told it is there: the real voice library by age with
 * the children's voices by name, every dialect and accent, the sound places, and every plan's row.
 */
class HouseKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, VoiceSeeder::class]);
    }

    public function test_it_knows_the_childrens_voices_by_name_in_both_languages(): void
    {
        $this->assertGreaterThan(0, Voice::query()->where('is_active', true)->count());
        $ar = HouseAgent::knowledge('ar');
        $en = HouseAgent::knowledge('en');

        $this->assertStringContainsString('أصوات أطفال', $ar);
        $this->assertStringContainsString("children's voices", $en);
        $this->assertStringContainsString('Hessa (girl)', $en);
        $this->assertStringContainsString('Max (boy)', $en);
        $this->assertStringContainsString("Elders' voices", $en);
    }

    public function test_it_knows_every_dialect_accent_sound_place_and_plan(): void
    {
        $en = HouseAgent::knowledge('en');
        $ar = HouseAgent::knowledge('ar');
        foreach (ArabicDialects::ALL as $row) {
            $this->assertStringContainsString($row['en'], $en);
            $this->assertStringContainsString($row['ar'], $ar);
        }
        foreach (EnglishAccents::ALL as $row) {
            $this->assertStringContainsString($row['en'], $en);
        }
        $this->assertStringContainsString('# Sound places', $en);
        $this->assertStringContainsString('# Every plan in detail', $en);
        $this->assertStringContainsString('# الأماكن الصوتية', $ar);
    }

    public function test_it_fits_in_what_goes_into_a_call_and_never_names_the_engine(): void
    {
        foreach (['ar', 'en'] as $language) {
            $sheet = HouseAgent::knowledge($language);
            $this->assertLessThanOrEqual(AgentInstructions::MAX_KNOWLEDGE_CHARS, mb_strlen($sheet));
            $this->assertStringNotContainsStringIgnoringCase('gemini', $sheet);
            $this->assertStringNotContainsString('✓', $sheet);
        }
    }

    public function test_it_is_told_never_to_say_listen_lacks_something_before_looking(): void
    {
        $this->assertStringContainsString("children's and elders' voices", implode(' ', HouseAgent::texts('en', null)['script']['never']));
        $this->assertStringContainsString('أصوات الأطفال', implode(' ', HouseAgent::texts('ar', null)['script']['never']));
    }
}
