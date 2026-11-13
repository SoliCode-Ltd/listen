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
            'all' => Tab::make('All'),
            'live' => Tab::make('Live now')
                ->icon('heroicon-m-signal')
                ->modifyQueryUsing(fn (Builder $query): Builder => AgentConversationResource::whereLiveNow($query))
                ->badge(fn (): ?int => ($count = AgentConversationResource::whereLiveNow(AgentConversation::query())->count()) > 0 ? $count : null)
                ->badgeColor('success'),
            'chats' => Tab::make('Written chats')
                ->icon('heroicon-m-chat-bubble-left-ellipsis')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('agent_conversations.channel', ['chat', 'chat_link']))
                ->badge(fn (): ?int => ($count = AgentConversation::query()->where('status', \App\Services\Agents\AgentChats::OPEN)->count()) > 0 ? $count : null)
                ->badgeColor('success'),
            'voice' => Tab::make('Voice calls')
                ->icon('heroicon-m-phone')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('agent_conversations.channel', ['chat', 'chat_link'])),
            'members' => Tab::make('Members\' agents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent, false))),
            'house' => Tab::make('Listen\'s own agents')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('agent', fn (Builder $agent): Builder => AgentResource::whereHouse($agent))),
        ];
    }
}
