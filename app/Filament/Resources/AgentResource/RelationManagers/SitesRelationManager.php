<?php

namespace App\Filament\Resources\AgentResource\RelationManagers;

use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentResource;
use App\Models\AgentConversation;
use App\Models\AgentWidgetSite;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every website the agent's call button loaded on (AgentWidgetSite): where it shows (allowed)
 * and where someone put it without the owner's say (refused), when it was first and last seen,
 * how it was added, and the calls that came from each. Read only.
 */
class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'Websites';

    protected static ?string $icon = 'heroicon-o-globe-alt';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->sites()->count();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('origin')
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('origin')
                    ->label('Website')
                    ->formatStateUsing(fn (string $state, AgentWidgetSite $record): string => $record->host())
                    ->url(fn (AgentWidgetSite $record): ?string => AgentConversationResource::safeUrl($record->origin), true)
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('allowed')
                    ->label('Button')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Shows there' : 'Refused (not on its list)')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('installed_via')
                    ->label('Added with')
                    ->formatStateUsing(fn (?string $state): string => AgentResource::INSTALLED_VIA[$state] ?? \Illuminate\Support\Str::headline((string) $state))
                    ->placeholder('Pasted code'),
                Tables\Columns\TextColumn::make('calls')
                    ->label('Calls from it')
                    ->state(fn (AgentWidgetSite $record): int => AgentConversation::query()
                        ->where('agent_id', $record->agent_id)->where('origin', $record->origin)->count())
                    ->numeric(),
                Tables\Columns\TextColumn::make('first_seen_at')->label('First seen')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('allowed')
                    ->label('Button')
                    ->placeholder('Shows or refused')
                    ->trueLabel('Shows there')
                    ->falseLabel('Refused'),
            ])
            ->emptyStateHeading('Not on a website yet')
            ->emptyStateDescription('A website shows here the first time it loads the agent\'s call button (written at most every ten minutes a website).');
    }
}
