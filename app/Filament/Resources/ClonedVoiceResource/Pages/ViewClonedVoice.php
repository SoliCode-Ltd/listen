<?php

namespace App\Filament\Resources\ClonedVoiceResource\Pages;

use App\Filament\Resources\ClonedVoiceResource;
use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewClonedVoice extends ViewRecord
{
    protected static string $resource = ClonedVoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resend')
                ->label('Resend to Listen')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (ClonedVoice $record): bool => VoiceCloningService::canRetry($record))
                ->requiresConfirmation()
                ->modalDescription('Sends the saved recordings to Listen again.')
                ->action(function (ClonedVoice $record): void {
                    ClonedVoiceResource::resend($record);
                    $this->refreshFormData(['status', 'provider_voice_key', 'failure_reason', 'metadata']);
                }),
            Actions\Action::make('purge')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Deletes the Listen voice and both recordings. This cannot be undone.')
                ->action(function (ClonedVoice $record): void {
                    app(VoiceCloningService::class)->purge($record);
                    Notification::make()->title('Cloned voice deleted')->success()->send();
                    $this->redirect(ClonedVoiceResource::getUrl('index'));
                }),
        ];
    }
}
