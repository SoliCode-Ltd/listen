<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\UserFeatureGrant;
use App\Support\PlanFeatures;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
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

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('feature')
                ->label('Option')
                ->options(PlanFeatures::GRANTABLE)
                ->required(),
            Forms\Components\TextInput::make('days')
                ->label('For how many days')
                ->numeric()
                ->minValue(1)
                ->maxValue(30)
                ->default(2)
                ->required()
                ->helperText('The option switches itself off after this, for this member only.')
                ->visibleOn('create'),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Ends')
                ->required()
                ->visibleOn('edit'),
            Forms\Components\TextInput::make('note')
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
                Tables\Columns\TextColumn::make('feature')
                    ->label('Option')
                    ->formatStateUsing(fn (string $state): string => PlanFeatures::GRANTABLE[$state] ?? $state),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Ends')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (UserFeatureGrant $record): string => $record->isActive() ? 'Active' : 'Ended')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('grantedBy.email')
                    ->label('Given by')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('note')
                    ->limit(60)
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Give an option for a few days')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['expires_at'] = now()->addDays(max(1, min(30, (int) ($data['days'] ?? 2))));
                        $data['granted_by'] = auth()->id();
                        unset($data['days']);

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('end')
                    ->label('End now')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (UserFeatureGrant $record): bool => $record->isActive())
                    ->action(fn (UserFeatureGrant $record) => $record->update(['expires_at' => now()])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
