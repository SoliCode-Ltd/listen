<?php

namespace App\Filament\Resources\AgentConversationResource\Pages;

use App\Filament\Concerns\SimplePages;
use Filament\Schemas\Components\Tabs\Tab;
use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentResource;
use App\Models\AgentConversation;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAgentConversations extends ListRecords
{
    use SimplePages;

    protected static string $resource = AgentConversationResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All calls'),
            'live' => Tab::make('Live now')
                ->icon('heroicon-m-signal')
                ->modifyQueryUsing(fn (Builder $query): Builder => AgentConversationResource::whereLiveNow($query))
                ->badge(fn (): ?int => ($count = AgentConversationResource::whereLiveNow(AgentConversation::query())->count()) > 0 ? $count : null)
                ->badgeColor('success'),
            'members' => Tab::make('Members\' agents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent, false))),
            'house' => Tab::make('Listen\'s own agents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent))),
        ];
    }
}
