<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Plan;
use App\Models\User;
use App\Support\Transcription\TranscriptionAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each plan's speech to text ("Transcribe") is set on the plan, in the admin's
 * Plans page: whether it is included, the minutes a month, the longest file,
 * timings, speaker names, translation, the priority queue and files at once.
 */
class TranscriptionPlanSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plans_start_as_the_owner_set_them(): void
    {
        $plans = [];
        foreach (['free', 'basic', 'starter', 'creator', 'pro', 'studio_max'] as $slug) {
            // Some plans already come with the database (an earlier migration makes Basic).
            $plans[$slug] = Plan::query()->where('slug', $slug)->first() ?? Plan::factory()->create(['slug' => $slug, 'name' => $slug]);
        }
        $migration = require database_path('migrations/2026_12_01_120000_add_transcription_to_plans.php');
        $migration->up();

        $minutes = [];
        foreach ($plans as $slug => $plan) {
            $minutes[$slug] = TranscriptionAccess::monthlyMinutes($plan->fresh());
        }
        $this->assertSame(['free' => 0, 'basic' => 0, 'starter' => 120, 'creator' => 250, 'pro' => 500, 'studio_max' => 1000], $minutes);

        $starter = $plans['starter']->fresh();
        $this->assertSame(30, TranscriptionAccess::maxFileMinutes($starter));
        $this->assertTrue($starter->transcription_timestamps);
        $this->assertFalse($starter->transcription_speakers);
        $this->assertFalse($starter->transcription_translate);
        $this->assertSame(1, TranscriptionAccess::concurrentJobs($starter));

        $creator = $plans['creator']->fresh();
        $this->assertTrue($creator->transcription_speakers);
        $this->assertTrue($creator->transcription_translate);
        $this->assertFalse($creator->transcription_priority);

        $max = $plans['studio_max']->fresh();
        $this->assertSame(180, TranscriptionAccess::maxFileMinutes($max));
        $this->assertTrue($max->transcription_priority);
        $this->assertSame(2, TranscriptionAccess::concurrentJobs($max));
        $this->assertFalse(TranscriptionAccess::planIncludes($plans['basic']->fresh()));
    }

    public function test_the_admin_changes_every_limit_on_the_plan(): void
    {
        $plan = Plan::factory()->create(['slug' => 'creator', 'price_cents' => 2200, 'monthly_chars' => 320000, 'transcription_enabled' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldExists('transcription_enabled')
            ->assertFormFieldIsHidden('transcription_minutes_month')
            ->fillForm(['transcription_enabled' => true])
            ->assertFormFieldIsVisible('transcription_minutes_month')
            ->fillForm([
                'transcription_minutes_month' => 400,
                'transcription_max_file_minutes' => 90,
                'transcription_concurrent_jobs' => 3,
                'transcription_priority' => true,
                'transcription_timestamps' => true,
                'transcription_speakers' => true,
                'transcription_translate' => false,
            ])
            ->assertSee('If every minute is used')
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertTrue(TranscriptionAccess::planIncludes($plan));
        $this->assertSame(400, $plan->transcription_minutes_month);
        $this->assertSame(90, $plan->transcription_max_file_minutes);
        $this->assertSame(3, $plan->transcription_concurrent_jobs);
        $this->assertTrue($plan->transcription_priority);
        $this->assertTrue($plan->transcription_speakers);
        $this->assertFalse($plan->transcription_translate);
        $this->assertEqualsWithDelta(0.0042, TranscriptionAccess::costPerMinute($plan), 1e-9, 'timings cost the timed rate');
    }
}
