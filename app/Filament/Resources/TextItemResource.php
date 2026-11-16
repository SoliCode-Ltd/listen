<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\TextItemResource\Pages\ListTextItems;
use App\Filament\Resources\TextItemResource\Pages\CreateTextItem;
use App\Filament\Resources\TextItemResource\Pages\ViewTextItem;
use App\Filament\Resources\TextItemResource\Pages\EditTextItem;
use App\Filament\Resources\TextItemResource\Pages;
use App\Models\TextItem;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TextItemResource extends Resource
{
    protected static ?string $model = TextItem::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Schema::hasTable('blocked_generations')) {
            return $query;
        }

        return $query->whereNotExists(function ($subQuery) {
            $subQuery->select(DB::raw(1))
                ->from('blocked_generations as bg')
                ->whereColumn('bg.user_id', 'text_items.user_id')
                ->whereColumn('bg.input_text', 'text_items.content');
        });
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                Section::make('Text Item Details')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('source_type')
                            ->maxLength(255),
                        TextInput::make('external_id')
                            ->maxLength(255),
                        TextInput::make('title')
                            ->maxLength(255),
                        Select::make('language')
                            ->searchable()
                            ->options([
                                'en' => 'English',
                                'es' => 'Spanish',
                                'fr' => 'French',
                                'de' => 'German',
                                'ar' => 'Arabic',
                                'ja' => 'Japanese',
                                'ko' => 'Korean',
                                'zh' => 'Chinese',
                            ]),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                            ]),
                        DateTimePicker::make('received_at'),
                    ]),
                Section::make('Content')
                    ->schema([
                        Textarea::make('content')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('source_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('language')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('source_type')
                    ->options(fn () => TextItem::query()->distinct()->whereNotNull('source_type')->pluck('source_type', 'source_type')->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
            'index' => ListTextItems::route('/'),
            'create' => CreateTextItem::route('/create'),
            'view' => ViewTextItem::route('/{record}'),
            'edit' => EditTextItem::route('/{record}/edit'),
        ];
    }
}
