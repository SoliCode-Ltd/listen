<?php

namespace Tests\Feature;

use App\Filament\Pages\EnvEditor;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin Environment Editor edits .env values and adds new variables.
 * Every test works on a throwaway .env, never the real one.
 */
class EnvEditorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/env-editor-'.uniqid();
        File::makeDirectory($this->dir);
        File::put($this->dir.'/.env', implode("\n", [
            'APP_NAME=Listen',
            '# comment stays',
            'GOOGLE_PRIVATE_KEY="-----BEGIN KEY-----\nabc\n-----END KEY-----\n"',
            'LISTEN_BILLING_GRACE_DAYS=7',
            '',
        ]));
        $this->app->useEnvironmentPath($this->dir);
        $this->app->loadEnvironmentFrom('.env');

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_admin_adds_a_new_variable(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'LISTEN_STRIPE_TRIAL_ACTIONS', 'value' => 'true', 'is_new' => true];

        $page->set('data.entries', $entries)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('.env file updated successfully');

        $env = $this->env();
        $this->assertStringContainsString("\nLISTEN_STRIPE_TRIAL_ACTIONS=true\n", $env);
        $this->assertStringContainsString('# comment stays', $env);
    }

    public function test_admin_edits_an_existing_value_and_other_lines_stay_exactly_as_they_were(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        foreach ($entries as $id => $entry) {
            if ($entry['key'] === 'LISTEN_BILLING_GRACE_DAYS') {
                $entries[$id]['value'] = '10';
            }
        }

        $page->set('data.entries', $entries)->call('save')->assertHasNoErrors();

        $env = $this->env();
        $this->assertStringContainsString("LISTEN_BILLING_GRACE_DAYS=10\n", $env);
        $this->assertStringContainsString('GOOGLE_PRIVATE_KEY="-----BEGIN KEY-----\nabc\n-----END KEY-----\n"', $env, 'escape sequences untouched');
        $this->assertSame(1, substr_count($env, 'LISTEN_BILLING_GRACE_DAYS='));
    }

    public function test_saving_with_no_changes_leaves_the_file_identical(): void
    {
        $before = $this->env();

        Livewire::test(EnvEditor::class)->call('save')->assertHasNoErrors();

        $this->assertSame($before, $this->env());
    }

    public function test_bad_key_names_are_refused(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'lower case', 'value' => 'x', 'is_new' => true];

        $page->set('data.entries', $entries)->call('save')->assertHasErrors();

        $this->assertStringNotContainsString('lower case', $this->env());
    }

    public function test_a_variable_listed_twice_is_not_saved(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['dup'] = ['key' => 'APP_NAME', 'value' => 'Other', 'is_new' => true];
        $before = $this->env();

        $page->set('data.entries', $entries)->call('save')->assertNotified('APP_NAME is listed twice');

        $this->assertSame($before, $this->env());
    }

    public function test_a_pasted_line_break_cannot_add_a_second_variable(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'MY_NOTE', 'value' => "hello\nAPP_DEBUG=true", 'is_new' => true];

        $page->set('data.entries', $entries)->call('save');

        $this->assertStringNotContainsString("\nAPP_DEBUG=true", $this->env());
        $this->assertStringContainsString('MY_NOTE="helloAPP_DEBUG=true"', $this->env());
    }

    public function test_values_with_spaces_are_quoted(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'MAIL_FROM_NAME', 'value' => 'Listen Studio', 'is_new' => true];

        $page->set('data.entries', $entries)->call('save');

        $this->assertStringContainsString('MAIL_FROM_NAME="Listen Studio"', $this->env());
    }

    public function test_non_admins_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get(EnvEditor::getUrl())->assertForbidden();
    }

    private function env(): string
    {
        return File::get($this->dir.'/.env');
    }
}
