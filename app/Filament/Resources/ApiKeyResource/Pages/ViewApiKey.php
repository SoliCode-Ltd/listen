<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** One API key: its limits, every period's use and all its requests (below). It can be revoked here. */
class ViewApiKey extends ViewRecord
{
    protected static string $resource = ApiKeyResource::class;

    public function getTitle(): string|Htmlable
    {
        return (string) $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var ApiKey $key */
        $key = $this->getRecord();

        return $key->maskedKey().' · '.($key->user?->email ?? 'member account deleted');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revoke')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getRecord()->isRevoked())
                ->requiresConfirmation()
                ->modalDescription('The key stops working at once. The member can make a new one if their plan includes the API.')
                ->action(function (): void {
                    $this->getRecord()->revoke();
                    Notification::make()->title('Key revoked')->success()->send();
                }),
        ];
    }
}
