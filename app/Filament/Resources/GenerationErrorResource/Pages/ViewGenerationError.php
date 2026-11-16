<?php

namespace App\Filament\Resources\GenerationErrorResource\Pages;

use App\Filament\Resources\GenerationErrorResource;
use App\Models\GenerationError;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

class ViewGenerationError extends ViewRecord
{
    protected static string $resource = GenerationErrorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resolve')
                ->label('Mark resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (GenerationError $record): bool => $record->resolved_at === null)
                ->action(function (GenerationError $record): void {
                    GenerationErrorResource::setResolved(new Collection([$record]), true);
                    $record->refresh();
                }),
            Action::make('reopen')
                ->label('Reopen')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (GenerationError $record): bool => $record->resolved_at !== null)
                ->action(function (GenerationError $record): void {
                    GenerationErrorResource::setResolved(new Collection([$record]), false);
                    $record->refresh();
                }),
            DeleteAction::make(),
        ];
    }
}
