<?php

namespace App\Filament\Resources\AgentContactResource\Pages;

use App\Filament\Resources\AgentContactResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentContacts extends ListRecords
{
    protected static string $resource = AgentContactResource::class;

    public function getSubheading(): ?string
    {
        return 'Callers who left their details with an agent, so its business can call them back. Click one to open the call.';
    }
}
