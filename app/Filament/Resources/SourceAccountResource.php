<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SourceAccountResource\Pages;
use App\Models\SourceAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SourceAccountResource extends Resource
{
    protected static ?string $model = SourceAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Source Accounts';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Source Account Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user_id')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('provider')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('label')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('identifier')
                            ->maxLength(255),
                        Forms\Components\DateTimePicker::make('last_synced_at'),
                    ]),
                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->label('Settings (JSON)')
                            ->addActionLabel('Add Setting'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_id')
                    ->label('User ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('identifier')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->options(fn () => SourceAccount::query()->distinct()->pluck('provider', 'provider')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSourceAccounts::route('/'),
            'create' => Pages\CreateSourceAccount::route('/create'),
            'view' => Pages\ViewSourceAccount::route('/{record}'),
            'edit' => Pages\EditSourceAccount::route('/{record}/edit'),
        ];
    }
}
