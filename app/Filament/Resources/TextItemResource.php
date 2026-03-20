<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TextItemResource\Pages;
use App\Models\TextItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TextItemResource extends Resource
{
    protected static ?string $model = TextItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Text Item Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('source_type')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('external_id')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('title')
                            ->maxLength(255),
                        Forms\Components\Select::make('language')
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
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                            ]),
                        Forms\Components\DateTimePicker::make('received_at'),
                    ]),
                Forms\Components\Section::make('Content')
                    ->schema([
                        Forms\Components\Textarea::make('content')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('language')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('source_type')
                    ->options(fn () => TextItem::query()->distinct()->whereNotNull('source_type')->pluck('source_type', 'source_type')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListTextItems::route('/'),
            'create' => Pages\CreateTextItem::route('/create'),
            'view' => Pages\ViewTextItem::route('/{record}'),
            'edit' => Pages\EditTextItem::route('/{record}/edit'),
        ];
    }
}
