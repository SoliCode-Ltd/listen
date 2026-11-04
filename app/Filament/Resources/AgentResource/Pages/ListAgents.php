<?php

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Filament\Widgets\AgentStats;
use Filament\Resources\Pages\ListRecords;

class ListAgents extends ListRecords
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [AgentStats::class];
    }
}
