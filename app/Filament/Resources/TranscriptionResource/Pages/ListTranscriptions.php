<?php

namespace App\Filament\Resources\TranscriptionResource\Pages;

use App\Filament\Resources\TranscriptionResource;
use App\Filament\Widgets\TranscriptionStats;
use Filament\Resources\Pages\ListRecords;

class ListTranscriptions extends ListRecords
{
    protected static string $resource = TranscriptionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [TranscriptionStats::class];
    }
}
