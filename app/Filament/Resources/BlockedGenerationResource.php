<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlockedGenerationResource\Pages;
use App\Models\BlockedGeneration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BlockedGenerationResource extends Resource
{
    protected static ?string $model = BlockedGeneration::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('ui.admin.blocked_generations.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('ui.admin.blocked_generations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.admin.blocked_generations.plural_model_label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('ui.admin.blocked_generations.details_section'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label(__('ui.admin.blocked_generations.user_name'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('user.email')
                            ->label(__('ui.admin.blocked_generations.user_email'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('matched_language')
                            ->label(__('ui.admin.blocked_generations.language'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('matched_term')
                            ->label(__('ui.admin.blocked_generations.matched_term'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('route_name')
                            ->label(__('ui.admin.blocked_generations.route_name'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('request_path')
                            ->label(__('ui.admin.blocked_generations.request_path'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('attribute')
                            ->label(__('ui.admin.blocked_generations.attribute'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label(__('ui.admin.blocked_generations.created_at'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Forms\Components\Section::make(__('ui.admin.blocked_generations.text_section'))
                    ->schema([
                        Forms\Components\Textarea::make('input_text')
                            ->label(__('ui.admin.blocked_generations.full_text'))
                            ->rows(10)
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Forms\Components\Section::make(__('ui.admin.blocked_generations.metadata_section'))
                    ->schema([
                        Forms\Components\Textarea::make('metadata')
                            ->label(__('ui.admin.blocked_generations.metadata'))
                            ->rows(8)
                            ->formatStateUsing(static function ($state): string {
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                                }

                                return is_string($state) ? $state : '';
                            })
                            ->disabled()
                            ->dehydrated(false),
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
                    ->label(__('ui.admin.blocked_generations.user_name'))
                    ->placeholder(__('ui.admin.blocked_generations.guest'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label(__('ui.admin.blocked_generations.user_email'))
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('matched_language')
                    ->label(__('ui.admin.blocked_generations.language'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('matched_term')
                    ->label(__('ui.admin.blocked_generations.matched_term'))
                    ->limit(24)
                    ->tooltip(fn (BlockedGeneration $record): ?string => $record->matched_term),
                Tables\Columns\TextColumn::make('text_excerpt')
                    ->label(__('ui.admin.blocked_generations.text_excerpt'))
                    ->state(function (BlockedGeneration $record): string {
                        $normalized = preg_replace('/\s+/u', ' ', (string) $record->input_text);

                        return Str::limit(trim((string) $normalized), 90);
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('route_name')
                    ->label(__('ui.admin.blocked_generations.route_name'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('request_path')
                    ->label(__('ui.admin.blocked_generations.request_path'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.admin.blocked_generations.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('matched_language')
                    ->label(__('ui.admin.blocked_generations.language_filter'))
                    ->options([
                        'ar' => __('ui.admin.blocked_generations.arabic'),
                        'en' => __('ui.admin.blocked_generations.english'),
                    ]),
                Tables\Filters\Filter::make('has_user')
                    ->label(__('ui.admin.blocked_generations.with_user'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('user_id')),
                Tables\Filters\Filter::make('guest')
                    ->label(__('ui.admin.blocked_generations.guest_only'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('user_id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListBlockedGenerations::route('/'),
            'view' => Pages\ViewBlockedGeneration::route('/{record}'),
        ];
    }
}
