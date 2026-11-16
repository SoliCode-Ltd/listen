<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoVoiceGenerationResource\Pages\ListDemoVoiceGenerations;
use App\Filament\Resources\DemoVoiceGenerationResource\Pages\ViewDemoVoiceGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanAccessService;
use App\Support\AudioUrl;
use App\Support\GenerationChoiceLabels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DemoVoiceGenerationResource extends Resource
{
    protected static ?string $model = DemoVoiceGeneration::class;

    /** @var array<int, array{label:string,is_paid:bool}> */
    private static array $planBadgeByUserId = [];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-speaker-wave';

    protected static string|\UnitEnum|null $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Voice Generations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Generation')
                    ->columns(2)
                    ->schema([
                        TextInput::make('user_name')
                            ->label('User')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record?->user?->name),
                        TextInput::make('user_email')
                            ->label('Email')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record?->user?->email),
                        Placeholder::make('voice_choice')
                            ->label('Voice')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::voice($record?->voice_name)),
                        TextInput::make('voice_provider')
                            ->label('Voice engine')
                            ->disabled(),
                        Placeholder::make('tier_choice')
                            ->label('Quality')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::tier($record?->voice_tier)),
                        Placeholder::make('source_language_choice')
                            ->label('Text language')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::language($record?->source_language)),
                        Placeholder::make('target_language_choice')
                            ->label('Voice language')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::language($record?->target_language)),
                        Toggle::make('translate')
                            ->disabled(),
                        TextInput::make('billable_chars')
                            ->numeric()
                            ->disabled(),
                        TextInput::make('duration_seconds')
                            ->numeric()
                            ->disabled(),
                        TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Section::make('Studio Controls')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('accent_choice')
                            ->label('Accent')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::accent($record?->dialect, $record?->target_language, $record?->voice_tier))
                            ->columnSpanFull(),
                        Placeholder::make('performance_style_choice')
                            ->label('Performance style')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::performanceStyle($record?->performance_style)),
                        Placeholder::make('emotion_choice')
                            ->label('Emotion')
                            ->content(fn (?DemoVoiceGeneration $record): string => GenerationChoiceLabels::emotion($record?->emotion)),
                        TextInput::make('speed')->numeric()->disabled(),
                        TextInput::make('pitch')->numeric()->disabled(),
                        Textarea::make('style_prompt')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Section::make('Text')
                    ->schema([
                        Textarea::make('input_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                        Textarea::make('speech_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Section::make('Tracking')
                    ->columns(2)
                    ->schema([
                        TextInput::make('tts_model')->disabled(),
                        TextInput::make('tts_latency_ms')->numeric()->disabled(),
                        Toggle::make('tts_fallback_used')->disabled(),
                        TextInput::make('tts_cost_estimate_usd')->numeric()->disabled(),
                        TextInput::make('quota_limit_chars')->numeric()->disabled(),
                        TextInput::make('quota_remaining_chars')->numeric()->disabled(),
                        Textarea::make('metadata')
                            ->rows(12)
                            ->columnSpanFull()
                            ->formatStateUsing(static function ($state): string {
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
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
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('paid_user_status')
                    ->label('Plan')
                    ->state(fn (DemoVoiceGeneration $record): string => self::resolvePlanBadge($record)['label'])
                    ->formatStateUsing(fn (?string $state): string => self::cleanPlanLabel((string) $state))
                    ->badge()
                    ->extraHeaderAttributes(['class' => 'whitespace-nowrap'])
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->weight('600')
                    ->color(function (DemoVoiceGeneration $record): string {
                        $badge = self::resolvePlanBadge($record);
                        $label = strtolower(self::cleanPlanLabel((string) ($badge['label'] ?? '')));

                        if ($label === 'free') {
                            return 'gray';
                        }

                        return ($badge['is_paid'] ?? false) ? 'success' : 'gray';
                    }),
                TextColumn::make('voice_label')
                    ->label('Voice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('voice_provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'info',
                        'google_cloud' => 'success',
                        'cache' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('voice_tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'hd' => 'warning',
                        'standard' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('target_language')
                    ->badge()
                    ->sortable(),
                TextColumn::make('dialect')
                    ->label('Accent')
                    ->state(fn (DemoVoiceGeneration $record): string => $record->dialect
                        ? GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier)
                        : '—')
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('translate')
                    ->boolean(),
                TextColumn::make('billable_chars')
                    ->label('Chars')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->suffix(' s')
                    ->sortable(),
                TextColumn::make('quota_remaining_chars')
                    ->label('Quota Left')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('public_audio_url')
                    ->label('Public URL')
                    ->state(fn (DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('target_language')
                    ->options([
                        'ar' => 'Arabic',
                        'en' => 'English',
                    ]),
                SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'hd' => 'HD',
                    ]),
                SelectFilter::make('voice_provider')
                    ->options([
                        'google_cloud' => 'Google Cloud',
                        'gemini' => 'Gemini',
                        'cache' => 'Cache',
                    ]),
                SelectFilter::make('dialect')
                    ->label('Accent')
                    ->options(fn (): array => GenerationChoiceLabels::accentOptions())
                    ->searchable(),
                TernaryFilter::make('translate')
                    ->label('Translated'),
                TernaryFilter::make('paid_user')
                    ->label('Paid User')
                    ->queries(
                        true: fn ($query) => $query->where('metadata->paid_user', true),
                        false: fn ($query) => $query->where(function ($inner) {
                            $inner->whereNull('metadata->paid_user')->orWhere('metadata->paid_user', false);
                        }),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('audio')
                    ->label('Open Audio')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DemoVoiceGeneration $record): ?string => $record->audio_path ? AudioUrl::fromPath($record->audio_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (DemoVoiceGeneration $record): bool => filled($record->audio_path)),
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
            'index' => ListDemoVoiceGenerations::route('/'),
            'view' => ViewDemoVoiceGeneration::route('/{record}'),
        ];
    }

    private static function resolvePublicAudioUrl(?DemoVoiceGeneration $record): string
    {
        if (! $record || ! filled($record->audio_path)) {
            return 'N/A';
        }

        $relativePath = 'storage/'.ltrim($record->audio_path, '/');
        $request = request();

        if ($request) {
            return $request->getSchemeAndHttpHost().'/'.ltrim($relativePath, '/');
        }

        return url($relativePath);
    }

    /**
     * @return array{label:string,is_paid:bool}
     */
    private static function resolvePlanBadge(DemoVoiceGeneration $record): array
    {
        $userId = (int) $record->user_id;
        if ($userId <= 0) {
            return ['label' => 'Free User', 'is_paid' => false];
        }

        if (isset(self::$planBadgeByUserId[$userId])) {
            return self::$planBadgeByUserId[$userId];
        }

        $metadata = is_array($record->metadata) ? $record->metadata : [];
        $metaPlanSlug = self::cleanPlanToken((string) ($metadata['paid_plan_slug'] ?? ''));
        $metaIsPaid = (bool) ($metadata['paid_user'] ?? false);

        $activePlan = app(PlanAccessService::class)->resolveActivePlanForUserId($userId);
        $effectivePlan = $activePlan;

        $latestSubscriptionPlan = Subscription::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->with('plan')
            ->first()
            ?->plan;

        $latestNonFreeSubscriptionPlan = Subscription::query()
            ->where('user_id', $userId)
            ->whereHas('plan', static fn ($query) => $query->where('slug', '<>', 'free'))
            ->latest('id')
            ->with('plan')
            ->first()
            ?->plan;

        if (
            ($effectivePlan === null || strtolower((string) ($effectivePlan->slug ?? 'free')) === 'free')
            && $latestSubscriptionPlan
            && strtolower((string) ($latestSubscriptionPlan->slug ?? '')) !== 'free'
        ) {
            $effectivePlan = $latestSubscriptionPlan;
        }

        if (
            ($effectivePlan === null || strtolower((string) ($effectivePlan->slug ?? 'free')) === 'free')
            && $latestNonFreeSubscriptionPlan
        ) {
            $effectivePlan = $latestNonFreeSubscriptionPlan;
        }

        if (! $effectivePlan && $metaPlanSlug !== '') {
            $effectivePlan = Plan::query()->where('slug', $metaPlanSlug)->first();
        }

        $isPaid = (int) ($effectivePlan?->price_cents ?? 0) > 0
            || (($effectivePlan?->slug) !== null && strtolower((string) $effectivePlan->slug) !== 'free')
            || ($metaIsPaid && $metaPlanSlug !== 'free');

        if ($effectivePlan) {
            $planSlug = self::cleanPlanToken((string) ($effectivePlan->slug ?? ''));
            $label = self::formatPlanBadgeLabel((string) $effectivePlan->getLocalizedName('en'), $planSlug);
        } elseif ($isPaid) {
            $label = $metaPlanSlug !== '' && $metaPlanSlug !== 'free'
                ? ucfirst(str_replace('_', ' ', $metaPlanSlug))
                : 'Paid';
        } else {
            $label = 'Free';
        }

        return self::$planBadgeByUserId[$userId] = [
            'label' => self::cleanPlanLabel($label),
            'is_paid' => $isPaid,
        ];
    }

    private static function cleanPlanToken(string $value): string
    {
        $clean = str_replace("\xEF\xBB\xBF", '', $value);
        $clean = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $clean) ?? $clean;
        $clean = str_replace('&#xFEFF;', '', $clean);

        return strtolower(trim($clean));
    }

    private static function cleanPlanLabel(string $value): string
    {
        $clean = str_replace("\xEF\xBB\xBF", '', $value);
        $clean = preg_replace('/[\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $clean) ?? $clean;
        $clean = str_replace('&#xFEFF;', '', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        return $clean !== '' ? $clean : 'Free';
    }

    private static function formatPlanBadgeLabel(string $localizedName, string $slug): string
    {
        $name = self::cleanPlanLabel($localizedName);
        if ($slug === 'free') {
            return 'Free';
        }

        if ($name === '') {
            return 'Paid';
        }

        return $name;
    }
}
