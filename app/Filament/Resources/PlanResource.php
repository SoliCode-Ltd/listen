<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use App\Services\StripeProductSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Plan Identity')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('URL-safe identifier (e.g. "free", "creator", "pro")'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Internal name (fallback if localized names are empty)'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive plans are hidden from pricing page'),
                        Forms\Components\Toggle::make('is_featured')
                            ->label('Featured Plan')
                            ->default(false)
                            ->helperText('Highlighted as "Most Popular" on pricing pages.'),
                    ]),
                Forms\Components\Section::make('Localized Content (English)')
                    ->description('English display names and descriptions shown on pricing pages.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('name_en')
                            ->label('Plan Name (English)')
                            ->maxLength(255)
                            ->placeholder('e.g. Creator, Professional')
                            ->helperText('Leave empty to use translation file or fallback name'),
                        Forms\Components\Textarea::make('description_en')
                            ->label('Plan Description (English)')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('Short tagline for the pricing card'),
                        Forms\Components\TextInput::make('button_text_en')
                            ->label('Button Text (English)')
                            ->maxLength(50)
                            ->placeholder('e.g. Get Started, Upgrade Now'),
                    ]),
                Forms\Components\Section::make('المحتوى المترجم (العربية)')
                    ->description('الأسماء والأوصاف العربية المعروضة على صفحات الأسعار.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('name_ar')
                            ->label('اسم الخطة (العربية)')
                            ->maxLength(255)
                            ->placeholder('مثل: المبدع، الاحترافي')
                            ->helperText('اتركه فارغاً لاستخدام ملف الترجمة'),
                        Forms\Components\Textarea::make('description_ar')
                            ->label('وصف الخطة (العربية)')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('وصف قصير لبطاقة الأسعار'),
                        Forms\Components\TextInput::make('button_text_ar')
                            ->label('نص الزر (العربية)')
                            ->maxLength(50)
                            ->placeholder('مثل: ابدأ الآن، ترقية'),
                    ]),
                Forms\Components\Section::make('Pricing')
                    ->description('Enter prices in dollars. Stored as cents in the database. Yearly auto-calculated at 20% discount.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('price_dollars')
                            ->label('Monthly Price ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->required()
                            ->dehydrated(false) // Virtual field — we handle saving manually
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record) {
                                    $component->state(number_format($record->price_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            })
                            ->helperText('Yearly price is auto-calculated at 20% discount'),
                        Forms\Components\Placeholder::make('yearly_preview')
                            ->label('Yearly Price Preview')
                            ->content(function (?Plan $record) {
                                if (!$record || $record->price_cents <= 0) return 'Free';
                                $yearlyDollars = round(($record->price_cents / 100) * 12 * 0.8, 2);
                                return '$' . number_format($yearlyDollars, 2) . '/year ($' . number_format($yearlyDollars / 12, 2) . '/mo)';
                            }),
                    ]),
                Forms\Components\Section::make('Voice & Quality')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('voice_tier')
                            ->label('Voice Quality Tier')
                            ->options([
                                'standard' => 'Standard',
                                'hd' => 'Premium',
                            ])
                            ->required()
                            ->helperText('Voice quality level users get on this plan'),
                        Forms\Components\Select::make('processing_priority')
                            ->options([
                                'standard' => 'Standard',
                                'high' => 'High',
                            ])
                            ->required()
                            ->default('standard'),
                        Forms\Components\Toggle::make('commercial_use_allowed')
                            ->label('Commercial Use Allowed')
                            ->helperText('Whether users can use generated audio commercially'),
                    ]),
                Forms\Components\Section::make('Usage Limits')
                    ->description('Set 0 for unlimited requests.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('monthly_chars')
                            ->label('Monthly Characters')
                            ->numeric()
                            ->required()
                            ->helperText('Total chars allowed per month'),
                        Forms\Components\TextInput::make('monthly_requests')
                            ->label('Monthly Requests')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->helperText('0 = unlimited requests'),
                        Forms\Components\TextInput::make('max_chars_per_request')
                            ->label('Max Characters Per Request')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('recent_voices_days')
                            ->label('Recent Voices Retention (days)')
                            ->numeric()
                            ->default(7)
                            ->helperText('How many days generated voices are retained. 0 = unlimited.'),
                    ]),
                Forms\Components\Section::make('Overage & Top-Up Pricing')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('allow_overage')
                            ->label('Allow Overage')
                            ->reactive()
                            ->helperText('If enabled, users can exceed their limit at additional cost'),
                        Forms\Components\TextInput::make('overage_price_dollars')
                            ->label('Overage Price per 1M chars ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->dehydrated(false) // Virtual field — handled manually
                            ->visible(fn (Get $get) => $get('allow_overage'))
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record && $record->overage_price_per_million_cents !== null) {
                                    $component->state(number_format($record->overage_price_per_million_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            }),
                        Forms\Components\TextInput::make('topup_char_price_dollars')
                            ->label('Top-Up Price per 1K chars ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record && $record->topup_char_price_cents !== null) {
                                    $component->state(number_format($record->topup_char_price_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            })
                            ->helperText('Price per 1,000 extra characters (stored as cents).'),
                        Forms\Components\TextInput::make('topup_minute_price_dollars')
                            ->label('Top-Up Price per Minute ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record && $record->topup_minute_price_cents !== null) {
                                    $component->state(number_format($record->topup_minute_price_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            })
                            ->helperText('Price per extra audio minute (stored as cents).'),
                    ]),
                Forms\Components\Section::make('TTS & Hybrid Engine')
                    ->description('Controls which TTS engine users on this plan receive and advanced audio features.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('tts_provider_policy')
                            ->label('TTS Provider Policy')
                            ->options([
                                'default' => 'Use Global Setting',
                                'google_only' => 'Google Cloud TTS Only',
                                'gemini_only' => 'Gemini TTS Only',
                                'gemini_preferred' => 'Gemini Preferred (fallback to Google)',
                            ])
                            ->default('default')
                            ->helperText('Override the global TTS mode for this plan. "Default" follows the system-wide setting.'),
                        Forms\Components\Select::make('quality_tier')
                            ->label('Audio Quality Tier')
                            ->options([
                                'standard' => 'Standard (WaveNet)',
                                'high' => 'High (Gemini)',
                            ])
                            ->default('standard')
                            ->helperText('Standard = Google WaveNet, High = Gemini 2.5 Flash.'),
                        Forms\Components\Toggle::make('dialogue_enabled')
                            ->label('Dialogue Mode')
                            ->helperText('Allow users to create multi-speaker dialogue audio.')
                            ->default(false),
                        Forms\Components\Toggle::make('custom_prompt_enabled')
                            ->label('Custom Prompt')
                            ->helperText('Allow users to provide custom voice styling instructions (Gemini voices).')
                            ->default(false),
                        Forms\Components\TextInput::make('queue_priority_weight')
                            ->label('Queue Priority Weight')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->default(0)
                            ->helperText('Higher weight = higher priority in job queue (0-1000).'),
                    ]),
                Forms\Components\Section::make('Voice Cloning')
                    ->description('Control voice cloning access and limits for this plan.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('voice_cloning_enabled')
                            ->label('Enable Voice Cloning')
                            ->helperText('Allow users on this plan to clone voices. The global voice cloning feature must also be enabled.')
                            ->default(false)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('voice_cloning_max_voices')
                            ->label('Max Cloned Voices')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->helperText('Max cloned voices for users on this plan. The dashboard uses this exact number.'),
                    ]),
                Forms\Components\Section::make('Plan Features (English)')
                    ->description('Features shown on the English pricing card. Auto-populated from plan data, edit as needed.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Repeater::make('features_en')
                            ->label('Features List (EN)')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->placeholder('e.g. Requests')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->placeholder('e.g. Unlimited')
                                    ->columnSpan(1),
                                Forms\Components\Select::make('icon')
                                    ->label('Icon')
                                    ->options([
                                        'activity' => '📈 Activity (Requests)',
                                        'type' => '🔤 Type (Max Input)',
                                        'mic' => '🎙️ Mic (Voice Quality)',
                                        'star' => '⭐ Star (Quality/Performance)',
                                        'heart' => '❤️ Heart (Emotion Controls)',
                                        'bolt' => '⚡ Bolt (Priority)',
                                        'lock' => '🔒 Lock (Commercial Use)',
                                        'clock' => '🕐 Clock (Recent Voices)',
                                        'check' => '✓ Checkmark',
                                        'globe' => '🌐 Globe (Languages)',
                                        'shield' => '🛡️ Shield (Protection)',
                                        'download' => '📥 Download',
                                        'users' => '👥 Users (Dialogue)',
                                        'sliders' => '🎚️ Sliders (Controls)',
                                    ])
                                    ->default('check')
                                    ->required()
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->afterStateHydrated(function (Forms\Components\Repeater $component, ?Plan $record) {
                                if (!$record) return;

                                // If already has custom features, use them
                                $existing = $record->features_en;
                                if (is_array($existing) && !empty($existing)) {
                                    $component->state(self::normalizeRepeaterItems($existing));
                                    return;
                                }

                                $component->state(self::normalizeRepeaterItems(self::buildDefaultFeaturesEn($record)));
                            })
                            ->addActionLabel('Add Feature')
                            ->defaultItems(0)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->reorderableWithDragAndDrop(false)
                            ->collapsible(),
                    ]),
                Forms\Components\Section::make('خصائص الخطة (العربية)')
                    ->description('الخصائص المعروضة على بطاقة الأسعار العربية. يتم ملؤها تلقائياً، عدّل حسب الحاجة.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Repeater::make('features_ar')
                            ->label('قائمة الخصائص (AR)')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('التسمية')
                                    ->required()
                                    ->placeholder('مثل: الطلبات')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('value')
                                    ->label('القيمة')
                                    ->required()
                                    ->placeholder('مثل: غير محدود')
                                    ->columnSpan(1),
                                Forms\Components\Select::make('icon')
                                    ->label('الأيقونة')
                                    ->options([
                                        'activity' => '📈 النشاط (الطلبات)',
                                        'type' => '🔤 النص (الحد الأقصى)',
                                        'mic' => '🎙️ الميكروفون (جودة الصوت)',
                                        'star' => '⭐ النجمة (الأداء)',
                                        'heart' => '❤️ القلب (التحكم بالمشاعر)',
                                        'bolt' => '⚡ السرعة (الأولوية)',
                                        'lock' => '🔒 القفل (الاستخدام التجاري)',
                                        'clock' => '🕐 الساعة (الأصوات الأخيرة)',
                                        'check' => '✓ علامة صح',
                                        'globe' => '🌐 العالم (اللغات)',
                                        'shield' => '🛡️ الدرع (الحماية)',
                                        'download' => '📥 التحميل',
                                        'users' => '👥 المستخدمين (الحوار)',
                                        'sliders' => '🎚️ المنزلقات (التحكم)',
                                    ])
                                    ->default('check')
                                    ->required()
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->afterStateHydrated(function (Forms\Components\Repeater $component, ?Plan $record) {
                                if (!$record) return;

                                // If already has custom features, use them
                                $existing = $record->features_ar;
                                if (is_array($existing) && !empty($existing)) {
                                    $component->state(self::normalizeRepeaterItems($existing));
                                    return;
                                }

                                $component->state(self::normalizeRepeaterItems(self::buildDefaultFeaturesAr($record)));
                            })
                            ->addActionLabel('إضافة خاصية')
                            ->defaultItems(0)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->reorderableWithDragAndDrop(false)
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('price_cents')
                    ->label('Monthly')
                    ->formatStateUsing(fn (int $state) => $state <= 0 ? 'Free' : '$' . number_format($state / 100, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->label('Voice Tier')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'hd' => 'Premium',
                        default => 'Standard',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'hd' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('monthly_chars')
                    ->label('Chars/mo')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('monthly_requests')
                    ->label('Requests/mo')
                    ->formatStateUsing(fn (int $state) => $state <= 0 ? 'Unlimited' : number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('quality_tier')
                    ->label('Quality')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'high' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tts_provider_policy')
                    ->label('TTS Policy')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini_only', 'gemini_preferred' => 'success',
                        'google_only' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'google_only' => 'Google',
                        'gemini_only' => 'Gemini',
                        'gemini_preferred' => 'Gemini+FB',
                        default => 'Default',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\IconColumn::make('dialogue_enabled')
                    ->label('Dialogue')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('custom_prompt_enabled')
                    ->label('Custom Prompt')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('commercial_use_allowed')
                    ->label('Commercial')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('voice_cloning_enabled')
                    ->label('Voice Cloning')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\IconColumn::make('stripe_synced')
                    ->label('Stripe')
                    ->state(fn (Plan $record): bool => $record->hasStripeConfig())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Plan $record): string => $record->hasStripeConfig()
                        ? 'Product ID: ' . ($record->stripe_product_id ?? '-')
                        : 'Not synced to Stripe'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'hd' => 'Premium',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('syncStripe')
                    ->label('Sync Stripe')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Plan to Stripe')
                    ->modalDescription('This will create or update the Stripe product and prices for this plan. Continue?')
                    ->action(function (Plan $record) {
                        try {
                            $syncService = app(StripeProductSyncService::class);
                            $syncService->syncPlan($record);

                            Notification::make()
                                ->title('Stripe Sync Successful')
                                ->body("Plan '{$record->name}' has been synced to Stripe.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Stripe Sync Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('syncAllStripe')
                        ->label('Sync All to Stripe')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $syncService = app(StripeProductSyncService::class);
                            $success = 0;
                            $failed = 0;

                            foreach ($records as $plan) {
                                try {
                                    $syncService->syncPlan($plan);
                                    $success++;
                                } catch (\Exception $e) {
                                    $failed++;
                                }
                            }

                            Notification::make()
                                ->title('Stripe Sync Complete')
                                ->body("Synced: {$success}, Failed: {$failed}")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'view' => Pages\ViewPlan::route('/{record}'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }

    /**
     * Build default English features from plan attributes.
     *
     * @return array<int, array{label: string, value: string, icon: string}>
     */
    private static function buildDefaultFeaturesEn(Plan $record): array
    {
        $isFree = (int) $record->price_cents <= 0;
        $tier = \App\Support\VoiceTier::normalize((string) ($record->voice_tier ?: 'standard'));

        $priority = match ((string) ($record->processing_priority ?? 'standard')) {
            'standard_plus' => 'Standard+',
            'high' => 'High',
            'priority' => 'Priority',
            'highest' => 'Highest',
            default => 'Standard',
        };

        $recentDays = (int) ($record->recent_voices_days ?? 7);
        $recentValue = $recentDays <= 0 ? 'Unlimited' : "{$recentDays} days";

        $emotionValue = $isFree ? 'Basic' : 'Full Controls';

        $features = [
            ['label' => 'Requests', 'value' => (int) $record->monthly_requests <= 0 ? 'Unlimited' : number_format((int) $record->monthly_requests), 'icon' => 'activity'],
            ['label' => 'Max Input', 'value' => number_format((int) $record->max_chars_per_request) . ' chars', 'icon' => 'type'],
            ['label' => 'Voice Quality', 'value' => \App\Support\VoiceTier::label($tier), 'icon' => 'mic'],
            ['label' => 'Emotion Controls', 'value' => $emotionValue, 'icon' => 'heart'],
            ['label' => 'Priority Queue', 'value' => $priority, 'icon' => 'bolt'],
            ['label' => 'Commercial Use', 'value' => $record->commercial_use_allowed ? 'Allowed' : 'Personal only', 'icon' => 'lock'],
            ['label' => 'Recent Voices', 'value' => $recentValue, 'icon' => 'clock'],
        ];

        if ((bool) ($record->dialogue_enabled ?? false)) {
            $features[] = ['label' => 'Dialogue Mode', 'value' => 'Included', 'icon' => 'users'];
        }

        // Custom Prompt is enabled per-plan
        if ((bool) ($record->custom_prompt_enabled ?? false)) {
            $features[] = ['label' => 'Custom Prompt', 'value' => 'Included', 'icon' => 'star'];
        }

        // Voice Cloning
        if ((bool) ($record->voice_cloning_enabled ?? false)) {
            $maxVoices = (int) ($record->voice_cloning_max_voices ?? 0);
            $features[] = ['label' => 'Voice Cloning', 'value' => "Up to {$maxVoices} voices", 'icon' => 'mic'];
        }

        return $features;
    }

    /**
     * Build default Arabic features from plan attributes.
     *
     * @return array<int, array{label: string, value: string, icon: string}>
     */
    private static function buildDefaultFeaturesAr(Plan $record): array
    {
        $isFree = (int) $record->price_cents <= 0;
        $tier = \App\Support\VoiceTier::normalize((string) ($record->voice_tier ?: 'standard'));

        $priority = match ((string) ($record->processing_priority ?? 'standard')) {
            'standard_plus' => 'عادية+',
            'high' => 'عالية',
            'priority' => 'أولوية',
            'highest' => 'أعلى أولوية',
            default => 'عادية',
        };

        $tierLabel = match ($tier) {
            'hd' => 'متميزة',
            default => 'عادية',
        };

        $recentDays = (int) ($record->recent_voices_days ?? 7);
        $recentValue = $recentDays <= 0 ? 'غير محدود' : "{$recentDays} يوم";

        $emotionValue = $isFree ? 'أساسي' : 'تحكم كامل';

        $features = [
            ['label' => 'الطلبات', 'value' => (int) $record->monthly_requests <= 0 ? 'غير محدود' : number_format((int) $record->monthly_requests), 'icon' => 'activity'],
            ['label' => 'الحد الأقصى للإدخال', 'value' => number_format((int) $record->max_chars_per_request) . ' حرف', 'icon' => 'type'],
            ['label' => 'جودة الصوت', 'value' => $tierLabel, 'icon' => 'mic'],
            ['label' => 'التحكم في المشاعر', 'value' => $emotionValue, 'icon' => 'heart'],
            ['label' => 'أولوية المعالجة', 'value' => $priority, 'icon' => 'bolt'],
            ['label' => 'الاستخدام التجاري', 'value' => $record->commercial_use_allowed ? 'مسموح' : 'شخصي فقط', 'icon' => 'lock'],
            ['label' => 'الأصوات الأخيرة', 'value' => $recentValue, 'icon' => 'clock'],
        ];

        if ((bool) ($record->dialogue_enabled ?? false)) {
            $features[] = ['label' => 'وضع الحوار', 'value' => 'متاح', 'icon' => 'users'];
        }

        // Custom Prompt is enabled per-plan
        if ((bool) ($record->custom_prompt_enabled ?? false)) {
            $features[] = ['label' => 'التعليمات المخصصة', 'value' => 'متاح', 'icon' => 'star'];
        }

        // Voice Cloning
        if ((bool) ($record->voice_cloning_enabled ?? false)) {
            $maxVoices = (int) ($record->voice_cloning_max_voices ?? 0);
            $features[] = [
                'label' => "\u{0627}\u{0633}\u{062A}\u{0646}\u{0633}\u{0627}\u{062E} \u{0627}\u{0644}\u{0623}\u{0635}\u{0648}\u{0627}\u{062A}",
                'value' => "\u{062D}\u{062A}\u{0649} {$maxVoices} \u{0623}\u{0635}\u{0648}\u{0627}\u{062A}",
                'icon' => 'mic',
            ];
        }

        return $features;
    }

    /**
     * Normalize repeater state to a numerically indexed array for Livewire reorder operations.
     *
     * @param  mixed  $items
     * @return array<int, array{label?: string, value?: string, icon?: string}>
     */
    public static function normalizeRepeaterItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, fn ($item) => is_array($item)));
    }
}

