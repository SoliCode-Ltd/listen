<?php

namespace App\Filament\Resources\GenerationErrorResource\Pages;

use App\Filament\Resources\GenerationErrorResource;
use App\Models\GenerationError;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

class ViewGenerationError extends ViewRecord
{
    protected static string $resource = GenerationErrorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resolve')
                ->label('Mark resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (GenerationError $record): bool => $record->resolved_at === null)
                ->action(function (GenerationError $record): void {
                    GenerationErrorResource::setResolved(new Collection([$record]), true);
                    $record->refresh();
                }),
            Actions\Action::make('reopen')
                ->label('Reopen')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (GenerationError $record): bool => $record->resolved_at !== null)
                ->action(function (GenerationError $record): void {
                    GenerationErrorResource::setResolved(new Collection([$record]), false);
                    $record->refresh();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
