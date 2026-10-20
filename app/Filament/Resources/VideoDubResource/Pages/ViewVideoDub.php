<?php

namespace App\Filament\Resources\VideoDubResource\Pages;

use App\Filament\Resources\VideoDubResource;
use App\Models\VideoDub;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewVideoDub extends ViewRecord
{
    protected static string $resource = VideoDubResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('failStuck')
                ->label('Mark failed and refund')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (VideoDub $record): bool => $record->isWorking())
                ->requiresConfirmation()
                ->modalDescription('Use this only for a dub that has been stuck for a long time. The member gets back any credits it took.')
                ->action(function (VideoDub $record): void {
                    VideoDubResource::failStuck($record);
                    $this->refreshFormData(['status', 'error_message']);
                }),
            Actions\Action::make('deleteFiles')
                ->label('Delete the videos now')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (VideoDub $record): bool => $record->files_deleted_at === null && ! $record->isWorking())
                ->requiresConfirmation()
                ->modalDescription('Deletes the dubbed videos from the server. The figures stay. This cannot be undone.')
                ->action(function (VideoDub $record): void {
                    VideoDubResource::deleteFiles($record);
                    $this->refreshFormData(['files_deleted_at']);
                }),
        ];
    }
}
