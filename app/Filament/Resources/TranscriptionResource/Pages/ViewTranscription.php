<?php

namespace App\Filament\Resources\TranscriptionResource\Pages;

use App\Filament\Resources\TranscriptionResource;
use App\Models\Transcription;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTranscription extends ViewRecord
{
    protected static string $resource = TranscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TranscriptionResource::stopAction(Action::make('stop'))
                ->after(fn () => $this->refreshFormData(['status', 'error', 'error_code', 'charged_seconds'])),
            Action::make('deleteAudio')
                ->label('Delete only the recording')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (Transcription $record): bool => $record->audio_deleted_at === null && ! $record->isWorking())
                ->requiresConfirmation()
                ->modalDescription('Deletes the recording from the server now. The text, the minutes it used and its figures stay; the member can no longer play it. This cannot be undone.')
                ->action(function (Transcription $record): void {
                    TranscriptionResource::deleteAudio($record);
                    $this->refreshFormData(['audio_path', 'audio_deleted_at']);
                }),
        ];
    }
}
