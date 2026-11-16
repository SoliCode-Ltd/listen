<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\VoiceSampleResource\Pages\ListVoiceSamples;
use App\Filament\Resources\VoiceSampleResource\Pages\CreateVoiceSample;
use App\Filament\Resources\VoiceSampleResource\Pages\EditVoiceSample;
use App\Filament\Resources\VoiceSampleResource\Pages;
use App\Models\VoiceSample;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VoiceSampleResource extends Resource
{
    protected static ?string $model = VoiceSample::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-play-circle';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Voice Samples';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sample Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display title (English).'),
                        TextInput::make('title_ar')
                            ->label('Title (Arabic)')
                            ->maxLength(255)
                            ->helperText('Display title for Arabic locale.'),
                        TextInput::make('description')
                            ->maxLength(500)
                            ->helperText('Short English description shown on card.'),
                        TextInput::make('description_ar')
                            ->label('Description (Arabic)')
                            ->maxLength(500)
                            ->helperText('Short Arabic description shown on card.'),
                        TextInput::make('icon')
                            ->maxLength(100)
                            ->helperText('Flag emoji or icon (e.g. 🇸🇦).'),
                        TextInput::make('country')
                            ->maxLength(10)
                            ->helperText('2-letter country code (e.g. SA, EG, US).'),
                        Textarea::make('sample_text')
                            ->label('Sample Text')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('The text that was spoken in this sample.'),
                        Textarea::make('sample_text_ar')
                            ->label('Sample Text (Arabic)')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Arabic version of the sample text. Falls back to sample_text if empty.'),
                        TextInput::make('audio_path')
                            ->label('Audio File Path')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Path to audio file relative to public/ (e.g. audio/samples/greeting.mp3).'),
                    ]),
                Section::make('Classification')
                    ->columns(3)
                    ->schema([
                        Select::make('language')
                            ->options([
                                'ar' => 'Arabic',
                                'en' => 'English',
                                'fr' => 'French',
                                'de' => 'German',
                                'es' => 'Spanish',
                                'ja' => 'Japanese',
                                'ko' => 'Korean',
                                'zh' => 'Chinese',
                            ])
                            ->default('ar')
                            ->required(),
                        Select::make('dialect')
                            ->options([
                                '' => 'None',
                                'ar-XA' => 'MSA',
                                'ar-SA' => 'Saudi',
                                'ar-EG' => 'Egyptian',
                                'ar-LB' => 'Lebanese',
                                'ar-AE' => 'Emirati',
                                'ar-SY' => 'Syrian',
                                'ar-JO' => 'Jordanian',
                                'ar-IQ' => 'Iraqi',
                                'ar-MA' => 'Moroccan',
                                'ar-KW' => 'Kuwaiti',
                                'ar-TN' => 'Tunisian',
                                'en-US' => 'American',
                                'en-GB' => 'British',
                                'en-AU' => 'Australian',
                                'en-IN' => 'Indian',
                            ])
                            ->searchable(),
                        Select::make('emotion')
                            ->options([
                                'neutral' => 'Neutral',
                                'warm' => 'Warm',
                                'sad' => 'Sad',
                                'excited' => 'Excited',
                                'formal' => 'Formal',
                                'storytelling' => 'Storytelling',
                                'ad_style' => 'Ad Style',
                                'news' => 'News',
                                'calm' => 'Calm',
                                'angry' => 'Angry',
                            ])
                            ->default('neutral'),
                        Select::make('category')
                            ->options([
                                'hero' => 'Hero Section',
                                'demo' => 'Demo Page',
                                'feature' => 'Feature Showcase',
                                'testimonial' => 'Testimonial',
                                'comparison' => 'Quality Comparison',
                            ])
                            ->default('hero')
                            ->required()
                            ->helperText('Content grouping used by the site. Display placement is controlled below.'),
                        TextInput::make('voice_name')
                            ->label('Voice Name')
                            ->maxLength(255)
                            ->helperText('Name of the TTS voice used.'),
                        TextInput::make('provider')
                            ->maxLength(50)
                            ->helperText('e.g. google_cloud or gemini'),
                        Select::make('gender')
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                            ])
                            ->default('male'),
                    ]),
                Section::make('Display')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Global availability. Turn this off to hide the sample everywhere.'),
                        Toggle::make('show_in_hero')
                            ->label('Show In Hero')
                            ->default(false)
                            ->helperText('Allow this sample to be used for the homepage hero audio player.'),
                        Toggle::make('show_in_showcase')
                            ->label('Show In Voice Samples')
                            ->default(true)
                            ->helperText('Show this sample in the voice samples gallery/cards section.'),
                        Toggle::make('is_featured')
                            ->label('Featured')
                            ->default(false)
                            ->helperText('Featured items are prioritized first within hero/showcase lists.'),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower = appears first.'),
                        KeyValue::make('metadata')
                            ->label('Metadata (JSON)')
                            ->addActionLabel('Add Field')
                            ->helperText('Extra data: duration, quality tier, etc.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')->searchable()->sortable()->limit(40),
                TextColumn::make('language')->badge()->sortable(),
                TextColumn::make('dialect')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('emotion')->badge()->color('info')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'hero' => 'success',
                        'demo' => 'info',
                        'feature' => 'warning',
                        'comparison' => 'primary',
                        default => 'gray',
                    })->sortable(),
                TextColumn::make('provider')->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                IconColumn::make('show_in_hero')->label('Hero')->boolean(),
                IconColumn::make('show_in_showcase')->label('Showcase')->boolean(),
                TextColumn::make('sort_order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options([
                        'hero' => 'Hero Section',
                        'demo' => 'Demo Page',
                        'feature' => 'Feature Showcase',
                        'comparison' => 'Quality Comparison',
                    ]),
                SelectFilter::make('language')
                    ->options(fn () => VoiceSample::query()->distinct()->pluck('language', 'language')->toArray()),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_featured')->label('Featured'),
                TernaryFilter::make('show_in_hero')->label('Show In Hero'),
                TernaryFilter::make('show_in_showcase')->label('Show In Voice Samples'),
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
            ->defaultSort('sort_order', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoiceSamples::route('/'),
            'create' => CreateVoiceSample::route('/create'),
            'edit' => EditVoiceSample::route('/{record}/edit'),
        ];
    }
}
