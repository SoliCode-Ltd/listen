<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;

class EnvEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Environment Editor';

    protected static string $view = 'filament.pages.env-editor';

    public ?array $data = [];

    /** Keys that are always masked and cannot be revealed. */
    protected array $sensitivePatterns = ['SECRET', 'PASSWORD', 'KEY', 'TOKEN', 'HASH'];

    public function mount(): void
    {
        // Only admins can access the env editor
        if (!auth()->user()?->is_admin) {
            abort(403, 'Only admins can access the environment editor.');
        }

        $envContent = $this->parseEnv();
        $this->form->fill(['entries' => $envContent]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Environment Variables')
                    ->description('Edit .env values. Sensitive keys are masked. Changes take effect immediately.')
                    ->schema([
                        Forms\Components\Repeater::make('entries')
                            ->schema([
                                Forms\Components\TextInput::make('key')
                                    ->label('Key')
                                    ->disabled()
                                    ->dehydrated(),
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->password(fn ($get) => $this->isSensitive($get('key') ?? ''))
                                    ->revealable(fn ($get) => $this->isSensitive($get('key') ?? '')),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
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

        $envPath = base_path('.env');
        $envContent = File::get($envPath);

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $value = $entry['value'] ?? '';

            // Wrap in quotes if the value contains spaces or special chars
            $escapedValue = $value;
            if (preg_match('/[\s#=]/', $value) || $value === '') {
                $escapedValue = '"' . addcslashes($value, '"') . '"';
            }

            // Replace the line in .env
            $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, "{$key}={$escapedValue}", $envContent);
            }
        }

        File::put($envPath, $envContent);

        Notification::make()
            ->title('.env file updated successfully')
            ->success()
            ->send();
    }

    protected function parseEnv(): array
    {
        $envPath = base_path('.env');
        if (!File::exists($envPath)) {
            return [];
        }

        $entries = [];
        $lines = explode("\n", File::get($envPath));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                $entries[] = ['key' => $key, 'value' => $value];
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
