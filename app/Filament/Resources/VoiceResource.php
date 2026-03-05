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
                                'ar' => 'Arabic',
                                'en' => 'English',
                            ]),
                        Forms\Components\Select::make('gender')
                            ->required()
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                            ]),
                        Forms\Components\Select::make('tier')
                            ->required()
                            ->options([
                                'standard' => 'Standard (WaveNet)',
                                'hd' => 'HD (Gemini Chirp3)',
                            ]),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Forms\Components\Section::make('Provider & Studio')
                    ->description('Voice studio settings for the hybrid TTS engine.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\Select::make('provider')
                            ->label('TTS Provider')
                            ->options([
                                'google_cloud' => 'Google Cloud TTS',
                                'gemini' => 'Gemini TTS',
                            ])
                            ->default('google_cloud')
                            ->helperText('Which TTS engine this voice belongs to.'),
                        Forms\Components\TextInput::make('provider_voice_id')
                            ->label('Provider Voice ID')
                            ->maxLength(255)
                            ->helperText('The voice identifier used by the provider (e.g. Gemini voice name).'),
                        Forms\Components\Select::make('dialect')
                            ->label('Arabic Dialect')
                            ->options([
                                '' => 'None / Not Arabic',
                                'ar-XA' => 'MSA (Modern Standard Arabic)',
                                'ar-SA' => 'Saudi Arabic',
                                'ar-AE' => 'Emirati Arabic',
                                'ar-EG' => 'Egyptian Arabic',
                                'ar-LB' => 'Lebanese Arabic',
                                'ar-SY' => 'Syrian Arabic',
                                'ar-JO' => 'Jordanian Arabic',
                                'ar-IQ' => 'Iraqi Arabic',
                                'ar-MA' => 'Moroccan Arabic',
                                'ar-DZ' => 'Algerian Arabic',
                                'ar-TN' => 'Tunisian Arabic',
                                'ar-KW' => 'Kuwaiti Arabic',
                                'ar-QA' => 'Qatari Arabic',
                                'ar-BH' => 'Bahraini Arabic',
                                'ar-OM' => 'Omani Arabic',
                                'ar-YE' => 'Yemeni Arabic',
                                'ar-SD' => 'Sudanese Arabic',
                                'ar-LY' => 'Libyan Arabic',
                                'ar-PS' => 'Palestinian Arabic',
                            ])
                            ->searchable()
                            ->helperText('Specific Arabic dialect for this voice.'),
                        Forms\Components\TextInput::make('country')
                            ->label('Country Code')
                            ->maxLength(5)
                            ->placeholder('e.g. SA, US, GB')
                            ->helperText('ISO country code for flag display.'),
                        Forms\Components\TagsInput::make('supported_emotions')
                            ->label('Supported Emotions')
                            ->placeholder('Add emotion...')
                            ->suggestions([
                                'neutral', 'warm', 'sad', 'excited', 'formal',
                                'storytelling', 'ad_style', 'news', 'calm', 'angry',
                            ])
                            ->helperText('Emotions this voice can express (Gemini voices only).'),
                        Forms\Components\TextInput::make('preview_audio_path')
                            ->label('Preview Audio Path')
                            ->maxLength(500)
                            ->helperText('Path to a sample audio clip for this voice (relative to public/).'),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first in the voice picker.'),
                        Forms\Components\Toggle::make('is_featured')
                            ->label('Featured Voice')
                            ->helperText('Show this voice in the featured section of the voice picker.')
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
                        'male' => 'info',
                        'female' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tier')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'hd' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'success',
                        'google_cloud' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('dialect')
                    ->label('Dialect')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                Tables\Filters\SelectFilter::make('tier')
                    ->options([
                        'standard' => 'Standard (WaveNet)',
                        'hd' => 'HD (Gemini Chirp3)',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\SelectFilter::make('provider')
                    ->options([
                        'google_cloud' => 'Google Cloud',
                        'gemini' => 'Gemini',
                    ]),
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Featured'),
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
