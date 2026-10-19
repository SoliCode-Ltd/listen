<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use App\Support\EnvFile;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EnvEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Environment Editor';

    protected static string $view = 'filament.pages.env-editor';

    public ?array $data = [];

    /** Values as loaded, so saving only rewrites the lines that changed. */
    public array $originalValues = [];

    /**
     * Keys whose value runs over several lines, which a one-line field cannot
     * show: those are shown, and saved, as written in .env (with \n and the like).
     */
    public array $rawKeys = [];

    /** Keys that are always masked and cannot be revealed. */
    protected array $sensitivePatterns = ['SECRET', 'PASSWORD', 'KEY', 'TOKEN', 'HASH'];

    public function mount(): void
    {
        // Only admins can access the env editor
        if (!auth()->user()?->is_admin) {
            abort(403, 'Only admins can access the environment editor.');
        }

        $this->loadEntries();
    }

    protected function loadEntries(): void
    {
        $entries = $this->parseEnv();
        $this->originalValues = [];
        foreach ($entries as $entry) {
            $this->originalValues[$entry['key']] = $entry['value'];
        }

        $this->form->fill(['entries' => $entries]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Environment Variables')
                    ->description('Edit .env values, or add a new one with "Add variable" at the bottom. Sensitive keys are masked. Existing variables cannot be renamed or removed here.')
                    ->schema([
                        Forms\Components\Repeater::make('entries')
                            ->schema([
                                Forms\Components\Hidden::make('is_new')
                                    ->default(true),
                                Forms\Components\TextInput::make('key')
                                    ->label('Key')
                                    ->placeholder('NEW_SETTING_NAME')
                                    ->required()
                                    ->maxLength(100)
                                    ->regex('/^[A-Z][A-Z0-9_]*$/')
                                    ->validationMessages(['regex' => 'Use capital letters, digits and underscores, starting with a letter (e.g. LISTEN_STRIPE_TRIAL_ACTIONS).'])
                                    ->disabled(fn (Get $get): bool => ! $get('is_new'))
                                    ->dehydrated(),
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->password(fn ($get) => $this->isSensitive($get('key') ?? ''))
                                    ->revealable(fn ($get) => $this->isSensitive($get('key') ?? '')),
                            ])
                            ->columns(2)
                            ->addable()
                            ->addActionLabel('Add variable')
                            // Only rows added on this page (not saved yet) can be removed.
                            ->deletable()
                            ->deleteAction(fn (Action $action) => $action->visible(
                                fn (array $arguments, Repeater $component): bool => (bool) ($component->getState()[$arguments['item']]['is_new'] ?? false)
                            ))
                            ->reorderable(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (!auth()->user()?->is_admin) {
            Notification::make()
                ->title('Only admins can edit environment variables')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();
        $entries = $data['entries'] ?? [];

        $envPath = $this->envPath();
        $envContent = File::exists($envPath) ? File::get($envPath) : '';
        $before = $envContent;
        $changed = [];

        $seen = [];
        foreach ($entries as $entry) {
            $key = trim((string) ($entry['key'] ?? ''));
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            if (isset($seen[$key])) {
                Notification::make()
                    ->title("{$key} is listed twice")
                    ->body('Nothing was saved. Remove the duplicate and save again.')
                    ->danger()
                    ->send();

                return;
            }
            $seen[$key] = true;

            $value = (string) ($entry['value'] ?? '');
            if (array_key_exists($key, $this->originalValues) && $this->originalValues[$key] === $value) {
                continue; // unchanged: leave the line exactly as it is
            }

            $line = $key.'='.(isset($this->rawKeys[$key]) ? $this->writtenValue($value) : $this->escapeValue($value));
            $changed[] = $key;

            // Replace the line in .env, or add it at the end if it is new.
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace_callback($pattern, fn () => $line, $envContent);
            } else {
                $envContent = rtrim($envContent, "\n")."\n".$line."\n";
            }
        }

        $backupNote = '';
        if ($envContent !== $before) {
            try {
                $backup = EnvFile::write($envPath, $envContent);
            } catch (RuntimeException $e) {
                Notification::make()
                    ->title('Nothing was saved')
                    ->body($e->getMessage())
                    ->danger()
                    ->persistent()
                    ->send();

                return;
            }

            // Names only: the values are secrets.
            Log::info('Env editor saved .env.', [
                'admin_id' => auth()->id(),
                'keys' => $changed,
                'backup' => $backup !== null ? basename($backup) : null,
            ]);
            $backupNote = $backup !== null
                ? ' The previous file was kept as storage/app/listen-deploy/'.basename($backup).'.'
                : ' No copy of the previous file could be kept.';
        }

        $cacheNote = $this->refreshConfigCache();

        $this->loadEntries();

        Notification::make()
            ->title('.env file updated successfully')
            ->body($cacheNote.$backupNote)
            ->success()
            ->send();
    }

    /**
     * The app reads most settings from the cached config, so a saved .env
     * only takes effect once that cache is rebuilt.
     */
    protected function refreshConfigCache(): string
    {
        try {
            if (app()->configurationIsCached()) {
                Artisan::call('config:cache');

                return 'Settings cache rebuilt: the new values are in use now.';
            }

            return 'The new values are in use from the next request.';
        } catch (\Throwable $e) {
            Log::error('Env editor could not rebuild the config cache.', ['error' => $e->getMessage()]);

            return 'Saved, but the settings cache could not be rebuilt. Run "php artisan config:cache" on the server.';
        }
    }

    protected function escapeValue(string $value): string
    {
        return EnvFile::value($value);
    }

    /**
     * A value edited as written in .env (see $rawKeys): its \n and the like
     * stay escapes, and only a quote that is not escaped yet gets escaped.
     */
    protected function writtenValue(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        return '"'.preg_replace('/(?<!\\\\)"/', '\\"', $value).'"';
    }

    protected function envPath(): string
    {
        return app()->environmentFilePath();
    }

    protected function parseEnv(): array
    {
        $envPath = $this->envPath();
        if (!File::exists($envPath)) {
            return [];
        }

        $entries = [];
        $this->rawKeys = [];
        $lines = explode("\n", File::get($envPath));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);

                // Shown as the app reads it, so saving it back changes nothing.
                $read = EnvFile::read($line);
                if ($read !== null && ! preg_match('/[\r\n]/', $read)) {
                    $value = $read;
                } else {
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    $this->rawKeys[$key] = true;
                }

                $entries[] = ['key' => $key, 'value' => $value, 'is_new' => false];
            }
        }

        return $entries;
    }

    protected function isSensitive(string $key): bool
    {
        foreach ($this->sensitivePatterns as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
