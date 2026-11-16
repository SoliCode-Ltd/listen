<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\UserFeatureGrant;
use App\Support\PlanFeatures;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Give this one member a studio option for a few days (a trial), whatever
 * their plan. Nobody else is affected, and the option switches itself off
 * when the time runs out.
 */
class FeatureGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'featureGrants';

    protected static ?string $title = 'Feature trials';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('feature')
                ->label('Option')
                ->options(PlanFeatures::GRANTABLE)
                ->required(),
            TextInput::make('days')
                ->label('For how many days')
                ->numeric()
                ->minValue(1)
                ->maxValue(30)
                ->default(2)
                ->required()
                ->helperText('The option switches itself off after this, for this member only.')
                ->visibleOn('create'),
            DateTimePicker::make('expires_at')
                ->label('Ends')
                ->required()
                ->visibleOn('edit'),
            TextInput::make('note')
                ->label('Note (only admins see it)')
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('feature')
            ->defaultSort('expires_at', 'desc')
            ->columns([
                TextColumn::make('feature')
                    ->label('Option')
                    ->formatStateUsing(fn (string $state): string => PlanFeatures::GRANTABLE[$state] ?? $state),
                TextColumn::make('expires_at')
                    ->label('Ends')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (UserFeatureGrant $record): string => $record->isActive() ? 'Active' : 'Ended')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('grantedBy.email')
                    ->label('Given by')
                    ->toggleable(),
                TextColumn::make('note')
                    ->limit(60)
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Give an option for a few days')
                    ->mutateDataUsing(function (array $data): array {
                        $data['expires_at'] = now()->addDays(max(1, min(30, (int) ($data['days'] ?? 2))));
                        $data['granted_by'] = auth()->id();
                        unset($data['days']);

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('end')
                    ->label('End now')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (UserFeatureGrant $record): bool => $record->isActive())
                    ->action(fn (UserFeatureGrant $record) => $record->update(['expires_at' => now()])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
