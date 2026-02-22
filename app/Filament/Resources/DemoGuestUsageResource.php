<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoGuestUsageResource\Pages;
use App\Models\DemoGuestUsage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DemoGuestUsageResource extends Resource
{
    protected static ?string $model = DemoGuestUsage::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Demo Guest Usage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Guest Usage')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('used_chars')
                            ->numeric()
                            ->required(),
                        Forms\Components\DateTimePicker::make('last_seen_at'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('identity_hash')
                    ->label('Identity')
                    ->searchable()
                    ->limit(20),
                Tables\Columns\TextColumn::make('used_chars')
                    ->label('Chars Used')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoGuestUsages::route('/'),
            'view' => Pages\ViewDemoGuestUsage::route('/{record}'),
        ];
    }
}
