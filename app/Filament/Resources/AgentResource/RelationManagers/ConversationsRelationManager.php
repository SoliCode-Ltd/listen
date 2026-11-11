<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use App\Filament\Concerns\SimplePages;
use Filament\Actions\Action;
use App\Filament\Resources\AgentConversationResource;
use App\Models\AgentConversation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An agent's calls, newest first; each opens on its own page in Calls. Read only. */
class ConversationsRelationManager extends RelationManager
{
    use SimplePages;

    protected static string $relationship = 'conversations';

    protected static ?string $title = 'Calls';

    protected static string | \BackedEnum | null $icon = 'heroicon-o-phone';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->conversations()->count();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('public_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'widget:id,agent_id,slug,public_key',
                'agent:id,public_id,user_id,name,business_name,settings,deleted_at',
            ]))
            ->columns(AgentConversationResource::tableColumns(withAgent: false))
            ->filters(AgentConversationResource::tableFilters(withAgent: false))
            ->defaultSort('started_at', 'desc')
            // Pages without counting every call first: quick however many calls the agent has had.
            ->paginationPageOptions([25, 50, 100])
            ->recordUrl(fn (AgentConversation $record): string => AgentConversationResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->url(fn (AgentConversation $record): string => AgentConversationResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No calls yet')
            ->emptyStateDescription('Its calls show here: the owner\'s tests, its website button and its link page.');
    }
}
