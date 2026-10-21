<?php

namespace App\Filament\Pages;

use App\Services\Tts\GeminiKeyManager;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GeminiKeys extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Gemini API Keys';

    protected static string $view = 'filament.pages.gemini-keys';

    public string $newApiKey = '';

    public function mount(): void
    {
        if (!auth()->user()?->is_admin) {
            abort(403, 'Only admins can access this page.');
        }
    }

    /**
     * Get all keys with their stats.
     */
    public function getKeysStats(): array
    {
        return app(GeminiKeyManager::class)->getAggregateStats();
    }

    /**
     * Add a new API key.
     */
    public function addKey(): void
    {
        $key = trim($this->newApiKey);
        
        if (empty($key)) {
            Notification::make()
                ->title('Please enter an API key')
                ->warning()
                ->send();
            return;
        }

        // Only a sanity check: Google AI Studio keys no longer all start with "AIza"
        // (newer ones use other prefixes), so any one-word key of a sensible length is accepted.
        if (preg_match('/\s/', $key) || strlen($key) < 20) {
            Notification::make()
                ->title('Invalid API key format')
                ->body('Paste the whole key from Google AI Studio, with no spaces.')
                ->danger()
                ->send();
            return;
        }

        $manager = app(GeminiKeyManager::class);
        $keyName = $manager->addKey($key);

        $this->newApiKey = '';

        Notification::make()
            ->title('API Key Added')
            ->body("Key saved as {$keyName}")
            ->success()
            ->send();
    }

    /**
     * Remove an API key.
     */
    public function removeKey(string $keyName): void
    {
        $manager = app(GeminiKeyManager::class);
        
        // Don't allow removing the last key
        $keys = $manager->getAllKeys();
        if (count($keys) <= 1) {
            Notification::make()
                ->title('Cannot remove the last key')
                ->body('You must have at least one API key configured.')
                ->danger()
                ->send();
            return;
        }

        $manager->removeKey($keyName);

        Notification::make()
            ->title('API Key Removed')
            ->body("{$keyName} has been removed from .env")
            ->success()
            ->send();
    }

    /**
     * Reset all usage counters.
     */
    public function resetCounters(): void
    {
        app(GeminiKeyManager::class)->resetAllCounters();

        Notification::make()
            ->title('Counters Reset')
            ->body('All usage counters have been reset to zero.')
            ->success()
            ->send();
    }

    /**
     * Calculate percentage of usage.
     */
    public function calculatePercentage(int $used, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }
        return min(100, (int) round(($used / $limit) * 100));
    }

    /**
     * Get status color based on percentage.
     */
    public function getStatusColor(int $percentage): string
    {
        if ($percentage >= 90) {
            return 'danger';
        }
        if ($percentage >= 70) {
            return 'warning';
        }
        return 'success';
    }
}
