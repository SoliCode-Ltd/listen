<?php

namespace Tests\Feature;

use App\Filament\Pages\EnvEditor;
use App\Models\User;
use Dotenv\Parser\Parser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
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
        // Backups of the old file go under storage: keep them in the throwaway folder too.
        $this->app->useStoragePath($this->dir.'/storage');

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

    public function test_values_with_backslashes_and_quotes_are_saved_so_the_file_still_loads(): void
    {
        $values = [
            'DB_PASSWORD' => 'pa\ss word',
            'MAIL_PASSWORD' => 'ends with \\',
            'QUOTED_START' => "'not closed",
            'HAS_QUOTE' => 'say "hi" now',
            'MAIL_FROM_NAME' => '${APP_NAME} Team',
        ];
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        foreach ($values as $key => $value) {
            $entries['new-'.$key] = ['key' => $key, 'value' => $value, 'is_new' => true];
        }

        $page->set('data.entries', $entries)->call('save')->assertNotified('.env file updated successfully');

        $read = $this->readEnv();
        foreach ($values as $key => $value) {
            $this->assertSame($value, $read[$key], $key.' reads back exactly as typed');
        }
    }

    public function test_a_saved_value_shows_as_typed_and_saving_again_changes_nothing(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'DB_PASSWORD', 'value' => 'pa\ss "word"', 'is_new' => true];
        $page->set('data.entries', $entries)->call('save');
        $saved = $this->env();

        $page = Livewire::test(EnvEditor::class);
        $shown = collect($page->get('data.entries'))->firstWhere('key', 'DB_PASSWORD');
        $this->assertSame('pa\ss "word"', $shown['value']);

        $page->call('save');
        $this->assertSame($saved, $this->env());
    }

    public function test_a_change_that_would_not_load_is_refused_and_the_file_is_untouched(): void
    {
        $before = $this->env();
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        foreach ($entries as $id => $entry) {
            if ($entry['key'] === 'GOOGLE_PRIVATE_KEY') {
                // Shown as written in .env (it runs over several lines); \q is no escape phpdotenv knows.
                $this->assertSame('-----BEGIN KEY-----\nabc\n-----END KEY-----\n', $entry['value']);
                $entries[$id]['value'] = '-----BEGIN KEY-----\nab\qc\n-----END KEY-----\n';
            }
        }

        $page->set('data.entries', $entries)->call('save')->assertNotified('Nothing was saved');

        $this->assertSame($before, $this->env());
        $this->assertSame([], glob($this->dir.'/storage/app/listen-deploy/env.backup-*') ?: []);
    }

    public function test_a_value_over_several_lines_keeps_its_line_breaks_when_edited(): void
    {
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        foreach ($entries as $id => $entry) {
            if ($entry['key'] === 'GOOGLE_PRIVATE_KEY') {
                $entries[$id]['value'] = '-----BEGIN KEY-----\nx"yz\n-----END KEY-----\n';
            }
        }

        $page->set('data.entries', $entries)->call('save')->assertNotified('.env file updated successfully');

        $this->assertSame("-----BEGIN KEY-----\nx\"yz\n-----END KEY-----\n", $this->readEnv()['GOOGLE_PRIVATE_KEY']);
    }

    public function test_saving_keeps_a_private_copy_of_the_old_file_and_only_the_last_ten(): void
    {
        $backups = $this->dir.'/storage/app/listen-deploy';
        File::makeDirectory($backups, 0755, true);
        foreach (range(1, 12) as $n) {
            File::put($backups.'/env.backup-2026010112'.sprintf('%04d', $n), 'old '.$n);
        }
        $before = $this->env();

        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'LISTEN_LIVE_ACTIONS', 'value' => 'false', 'is_new' => true];
        $page->set('data.entries', $entries)->call('save');

        $copies = glob($backups.'/env.backup-*');
        sort($copies);
        $this->assertCount(10, $copies);
        $newest = end($copies);
        $this->assertSame($before, File::get($newest), 'the copy is the file as it was before the change');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($newest)), -4));
        $this->assertStringContainsString("\nLISTEN_LIVE_ACTIONS=false\n", $this->env());
        $this->assertSame([], glob($this->dir.'/.env.saving-*') ?: [], 'no temporary file is left behind');
    }

    public function test_a_save_is_logged_with_the_admin_and_key_names_but_never_the_values(): void
    {
        Log::spy();
        $page = Livewire::test(EnvEditor::class);
        $entries = $page->get('data.entries');
        $entries['new-row'] = ['key' => 'STRIPE_SECRET', 'value' => 'sk_test_never_logged', 'is_new' => true];
        $page->set('data.entries', $entries)->call('save');

        Log::shouldHaveReceived('info')->with('Env editor saved .env.', Mockery::on(
            fn (array $context): bool => $context['admin_id'] === auth()->id()
                && $context['keys'] === ['STRIPE_SECRET']
                && ! str_contains(json_encode($context), 'sk_test_never_logged')
        ));
    }

    private function env(): string
    {
        return File::get($this->dir.'/.env');
    }

    /**
     * @return array<string, string|null>
     */
    private function readEnv(): array
    {
        $values = [];
        foreach ((new Parser)->parse($this->env()) as $entry) {
            $value = $entry->getValue();
            $values[$entry->getName()] = $value->isDefined() ? $value->get()->getChars() : null;
        }

        return $values;
    }
}
