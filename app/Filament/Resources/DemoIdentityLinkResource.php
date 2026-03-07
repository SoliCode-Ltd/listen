<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoIdentityLinkResource\Pages;
use App\Models\DemoIdentityLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DemoIdentityLinkResource extends Resource
{
    protected static ?string $model = DemoIdentityLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Identity Links';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identity Link')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(80),
                        Forms\Components\TextInput::make('link_group')
                            ->required()
                            ->maxLength(80),
                        Forms\Components\Select::make('identity_type')
                            ->options([
                                'fp' => 'Fingerprint',
                                'cv' => 'Canvas',
                                'gl' => 'WebGL',
                                'au' => 'Audio',
                                'ft' => 'Fonts',
                                'dv' => 'Device',
                                'ua' => 'User Agent',
                                'ip' => 'IP Address',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('confidence')
                            ->numeric()
                            ->default(1.0)
                            ->step(0.1)
                            ->minValue(0)
                            ->maxValue(1),
                        Forms\Components\Toggle::make('is_primary')
                            ->default(false),
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
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->identity_hash),
                Tables\Columns\TextColumn::make('link_group')
                    ->label('Link Group')
                    ->searchable()
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->link_group),
                Tables\Columns\BadgeColumn::make('identity_type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'fp',
                        'success' => fn ($state) => in_array($state, ['cv', 'gl', 'au']),
                        'warning' => fn ($state) => in_array($state, ['dv', 'ua', 'ft']),
                        'danger' => 'ip',
                    ]),
                Tables\Columns\TextColumn::make('confidence')
                    ->numeric(2)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('identity_type')
                    ->options([
                        'fp' => 'Fingerprint',
                        'cv' => 'Canvas',
                        'gl' => 'WebGL',
                        'au' => 'Audio',
                        'ft' => 'Fonts',
                        'dv' => 'Device',
                        'ua' => 'User Agent',
                        'ip' => 'IP Address',
                    ]),
                Tables\Filters\TernaryFilter::make('is_primary'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoIdentityLinks::route('/'),
        ];
    }
}
