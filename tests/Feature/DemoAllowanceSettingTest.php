<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Models\User;
use App\Support\DemoAllowance;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin sets the demo character allowance in Site Settings.
 */
class DemoAllowanceSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_admin_changes_the_demo_allowance(): void
    {
        config(['listen.demo.guest_char_allowance' => 300]);

        Livewire::test(SiteSettings::class)
            ->assertSet('data.'.DemoAllowance::SETTING_KEY, 300)
            ->set('data.'.DemoAllowance::SETTING_KEY, 500)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(500, DemoAllowance::chars());
    }

    public function test_the_allowance_must_be_a_positive_whole_number(): void
    {
        foreach ([0, -5, 'abc', DemoAllowance::MAX + 1] as $bad) {
            Livewire::test(SiteSettings::class)
                ->set('data.'.DemoAllowance::SETTING_KEY, $bad)
                ->call('save')
                ->assertHasErrors('data.'.DemoAllowance::SETTING_KEY);
        }
    }
}
