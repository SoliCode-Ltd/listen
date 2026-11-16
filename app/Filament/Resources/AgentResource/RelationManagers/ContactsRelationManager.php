<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use App\Filament\Resources\AgentContactResource;
use App\Models\AgentContact;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The customers who left their details with this agent; each opens the call they left them on. Read only. */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Customers';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-identification';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->contacts()->count();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('conversation:id,public_id'))
            ->columns(AgentContactResource::tableColumns(withAgent: false))
            ->defaultSort('last_contact_at', 'desc')
            ->recordUrl(fn (AgentContact $record): ?string => AgentContactResource::callUrl($record))
            ->recordActions([
                AgentContactResource::openCallAction(),
            ])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Callers who leave their name and phone number with this agent show here.');
    }
}
