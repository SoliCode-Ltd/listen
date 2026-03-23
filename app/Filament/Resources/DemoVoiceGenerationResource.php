<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoVoiceGenerationResource\Pages;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanAccessService;
use App\Support\AudioUrl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DemoVoiceGenerationResource extends Resource
{
    protected static ?string $model = DemoVoiceGeneration::class;
    /** @var array<int, array{label:string,is_paid:bool}> */
    private static array $planBadgeByUserId = [];

    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';

    protected static ?string $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Voice Generations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Generation')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('User')
                            ->disabled(),
                        Forms\Components\TextInput::make('user.email')
                            ->label('Email')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_label')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_name')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_provider')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_tier')
                            ->disabled(),
                        Forms\Components\TextInput::make('source_language')
                            ->disabled(),
                        Forms\Components\TextInput::make('target_language')
                            ->disabled(),
                        Forms\Components\Toggle::make('translate')
                            ->disabled(),
                        Forms\Components\TextInput::make('billable_chars')
                            ->numeric()
                            ->disabled(),
                        Forms\Components\TextInput::make('duration_seconds')
                            ->numeric()
                            ->disabled(),
                        Forms\Components\TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Studio Controls')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('dialect')->disabled(),
                        Forms\Components\TextInput::make('performance_style')->disabled(),
                        Forms\Components\TextInput::make('emotion')->disabled(),
                        Forms\Components\TextInput::make('speed')->numeric()->disabled(),
                        Forms\Components\TextInput::make('pitch')->numeric()->disabled(),
                        Forms\Components\Textarea::make('style_prompt')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Text')
                    ->schema([
                        Forms\Components\Textarea::make('input_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                        Forms\Components\Textarea::make('speech_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Tracking')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('tts_model')->disabled(),
                        Forms\Components\TextInput::make('tts_latency_ms')->numeric()->disabled(),
                        Forms\Components\Toggle::make('tts_fallback_used')->disabled(),
                        Forms\Components\TextInput::make('tts_cost_estimate_usd')->numeric()->disabled(),
                        Forms\Components\TextInput::make('quota_limit_chars')->numeric()->disabled(),
                        Forms\Components\TextInput::make('quota_remaining_chars')->numeric()->disabled(),
                        Forms\Components\Textarea::make('metadata')
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
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('paid_user_status')
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
                Tables\Columns\TextColumn::make('voice_label')
                    ->label('Voice')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'info',
                        'google_cloud' => 'success',
                        'cache' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'hd' => 'warning',
                        'standard' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_language')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('translate')
                    ->boolean(),
                Tables\Columns\TextColumn::make('billable_chars')
                    ->label('Chars')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->suffix(' s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quota_remaining_chars')
                    ->label('Quota Left')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('public_audio_url')
                    ->label('Public URL')
                    ->state(fn (DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('target_language')
                    ->options([
                        'ar' => 'Arabic',
                        'en' => 'English',
                    ]),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'hd' => 'HD',
                    ]),
                Tables\Filters\SelectFilter::make('voice_provider')
                    ->options([
                        'google_cloud' => 'Google Cloud',
                        'gemini' => 'Gemini',
                        'cache' => 'Cache',
                    ]),
                Tables\Filters\TernaryFilter::make('translate')
                    ->label('Translated'),
                Tables\Filters\TernaryFilter::make('paid_user')
                    ->label('Paid User')
                    ->queries(
                        true: fn ($query) => $query->where('metadata->paid_user', true),
                        false: fn ($query) => $query->where(function ($inner) {
                            $inner->whereNull('metadata->paid_user')->orWhere('metadata->paid_user', false);
                        }),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('audio')
                    ->label('Open Audio')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DemoVoiceGeneration $record): ?string => $record->audio_path ? AudioUrl::fromPath($record->audio_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (DemoVoiceGeneration $record): bool => filled($record->audio_path)),
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
            'index' => Pages\ListDemoVoiceGenerations::route('/'),
            'view' => Pages\ViewDemoVoiceGeneration::route('/{record}'),
        ];
    }

    private static function resolvePublicAudioUrl(?DemoVoiceGeneration $record): string
    {
        if (! $record || ! filled($record->audio_path)) {
            return 'N/A';
        }

        $relativePath = 'storage/' . ltrim($record->audio_path, '/');
        $request = request();

        if ($request) {
            return $request->getSchemeAndHttpHost() . '/' . ltrim($relativePath, '/');
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
