<?php

namespace App\Filament\Resources\SourceAccountResource\Pages;

use App\Filament\Resources\SourceAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSourceAccount extends ViewRecord
{
    protected static string $resource = SourceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
