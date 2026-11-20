<?php

namespace App\Filament\Resources\AudioGenerationResource\Pages;

use Filament\Actions\EditAction;
use App\Filament\Resources\AudioGenerationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAudioGeneration extends ViewRecord
{
    protected static string $resource = AudioGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Each refreshes the page, so the "Hidden from the member" section shows at once.
            AudioGenerationResource::hideAction()->after(fn () => $this->refreshFormData(['hidden_at', 'hidden_by', 'hidden_note'])),
            AudioGenerationResource::showAction()->after(fn () => $this->refreshFormData(['hidden_at', 'hidden_by', 'hidden_note'])),
            EditAction::make(),
        ];
    }
}
