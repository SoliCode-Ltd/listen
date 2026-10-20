<?php

namespace App\Filament\Resources\VideoDubResource\Pages;

use App\Filament\Resources\VideoDubResource;
use App\Filament\Widgets\VideoDubStats;
use Filament\Resources\Pages\ListRecords;

class ListVideoDubs extends ListRecords
{
    protected static string $resource = VideoDubResource::class;

    protected function getHeaderWidgets(): array
    {
        return [VideoDubStats::class];
    }
}
