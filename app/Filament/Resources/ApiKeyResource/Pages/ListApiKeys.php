<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\SiteSetting;
use App\Support\Api\ApiPlanAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * An emergency switch for the whole developer API: paused, every /v1
     * request is answered 503 api_paused (nothing is charged); the keys,
     * plans and docs stay as they are, and turning it back on resumes at once.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleApi')
                ->label(fn (): string => ApiPlanAccess::siteEnabled() ? 'Pause the developer API' : 'Turn the developer API back on')
                ->color(fn (): string => ApiPlanAccess::siteEnabled() ? 'danger' : 'success')
                ->icon(fn (): string => ApiPlanAccess::siteEnabled() ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => ApiPlanAccess::siteEnabled()
                    ? 'Every API request will be refused with "api_paused" until you turn it back on. Nothing is charged meanwhile; keys and plans are kept.'
                    : 'API requests will be served again at once, with each plan\'s usual limits.')
                ->action(function (): void {
                    $enable = ! ApiPlanAccess::siteEnabled();
                    SiteSetting::set(ApiPlanAccess::SITE_SWITCH, $enable ? '1' : '0', 'developer_api');
                    Notification::make()->title($enable ? 'The developer API is on.' : 'The developer API is paused.')->success()->send();
                }),
        ];
    }
}
