<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoiceResource\Pages;
use App\Models\Voice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VoiceResource extends Resource
{
    protected static ?string $model = Voice::class;

    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Voice Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('internal_voice_id')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('language')
                            ->required()
                            ->searchable()
                            ->options([
                                'en-US' => 'English (US)',
                                'en-GB' => 'English (UK)',
                                'en-AU' => 'English (AU)',
                                'es-ES' => 'Spanish (Spain)',
                                'es-US' => 'Spanish (US)',
                                'fr-FR' => 'French',
                                'de-DE' => 'German',
                                'it-IT' => 'Italian',
                                'pt-BR' => 'Portuguese (BR)',
                                'ja-JP' => 'Japanese',
                                'ko-KR' => 'Korean',
                                'zh-CN' => 'Chinese (CN)',
                                'ar-XA' => 'Arabic',
                                'hi-IN' => 'Hindi',
                                'ru-RU' => 'Russian',
                                'nl-NL' => 'Dutch',
                                'pl-PL' => 'Polish',
                                'sv-SE' => 'Swedish',
                                'tr-TR' => 'Turkish',
                            ]),
                        Forms\Components\Select::make('gender')
                            ->required()
                            ->options([
                                'MALE' => 'Male',
                                'FEMALE' => 'Female',
                                'NEUTRAL' => 'Neutral',
                            ]),
                        Forms\Components\Select::make('tier')
                            ->required()
                            ->options([
                                'standard' => 'Standard',
                                'premium' => 'Premium',
                                'studio' => 'Studio',
                            ]),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('internal_voice_id')
                    ->label('Voice ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('language')
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('gender')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'MALE' => 'info',
                        'FEMALE' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tier')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'premium' => 'warning',
                        'studio' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('language')
                    ->options(fn () => Voice::query()->distinct()->pluck('language', 'language')->toArray()),
                Tables\Filters\SelectFilter::make('gender')
                    ->options([
                        'MALE' => 'Male',
                        'FEMALE' => 'Female',
                        'NEUTRAL' => 'Neutral',
                    ]),
                Tables\Filters\SelectFilter::make('tier')
                    ->options([
                        'standard' => 'Standard',
                        'premium' => 'Premium',
                        'studio' => 'Studio',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
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
            'index' => Pages\ListVoices::route('/'),
            'create' => Pages\CreateVoice::route('/create'),
            'view' => Pages\ViewVoice::route('/{record}'),
            'edit' => Pages\EditVoice::route('/{record}/edit'),
        ];
    }
}
