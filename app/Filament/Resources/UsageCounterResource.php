<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UsageCounterResource\Pages;
use App\Models\UsageCounter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UsageCounterResource extends Resource
{
    protected static ?string $model = UsageCounter::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Usage Counters';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Usage Data')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user_id')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('period_ym')
                            ->label('Period (YYYY-MM)')
                            ->required()
                            ->maxLength(7),
                        Forms\Components\TextInput::make('total_minutes')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('total_chars')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('total_requests')
                            ->numeric()
                            ->default(0),
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
                Tables\Columns\TextColumn::make('period_ym')
                    ->label('Period')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_minutes')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_chars')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bonus_chars')
                    ->label('Gifted chars')
                    ->state(fn ($record): int => (int) ($record->bonus_chars ?? 0))
                    ->numeric(),
                Tables\Columns\TextColumn::make('total_requests')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('period_ym')
                    ->form([
                        Forms\Components\TextInput::make('period')
                            ->placeholder('YYYY-MM'),
                    ])
                    ->query(fn ($query, array $data) => $query->when($data['period'], fn ($q, $v) => $q->where('period_ym', $v))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('period_ym', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsageCounters::route('/'),
            'view' => Pages\ViewUsageCounter::route('/{record}'),
        ];
    }
}
