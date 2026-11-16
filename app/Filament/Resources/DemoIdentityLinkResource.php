<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DemoIdentityLinkResource\Pages\ListDemoIdentityLinks;
use App\Filament\Resources\DemoIdentityLinkResource\Pages;
use App\Models\DemoIdentityLink;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DemoIdentityLinkResource extends Resource
{
    protected static ?string $model = DemoIdentityLink::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static string | \UnitEnum | null $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Identity Links';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity Link')
                    ->columns(2)
                    ->schema([
                        TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(80),
                        TextInput::make('link_group')
                            ->required()
                            ->maxLength(80),
                        Select::make('identity_type')
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
                        TextInput::make('confidence')
                            ->numeric()
                            ->default(1.0)
                            ->step(0.1)
                            ->minValue(0)
                            ->maxValue(1),
                        Toggle::make('is_primary')
                            ->default(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('identity_hash')
                    ->label('Identity')
                    ->searchable()
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->identity_hash),
                TextColumn::make('link_group')
                    ->label('Link Group')
                    ->searchable()
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->link_group),
                BadgeColumn::make('identity_type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'fp',
                        'success' => fn ($state) => in_array($state, ['cv', 'gl', 'au']),
                        'warning' => fn ($state) => in_array($state, ['dv', 'ua', 'ft']),
                        'danger' => 'ip',
                    ]),
                TextColumn::make('confidence')
                    ->numeric(2)
                    ->sortable(),
                IconColumn::make('is_primary')
                    ->label('Primary')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('identity_type')
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
                TernaryFilter::make('is_primary'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoIdentityLinks::route('/'),
        ];
    }
}
