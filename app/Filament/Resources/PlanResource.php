<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\StripeProductSyncService;
use App\Support\DiscountTerms;
use App\Support\PlanPricingLines;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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
                        Forms\Components\Select::make('kind')
                            ->label('Type')
                            ->options([
                                Plan::KIND_SUBSCRIPTION => 'Monthly plan (subscription)',
                                Plan::KIND_PACK => 'One-time pack',
                            ])
                            ->default(Plan::KIND_SUBSCRIPTION)
                            ->required()
                            ->live()
                            ->helperText('A pack is paid once (card, InstaPay or Vodafone Cash) and never renews. Members on the free plan who buy it get its features while its characters last.'),
                        Forms\Components\TextInput::make('pack_days')
                            ->label('Days the pack lasts')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(30)
                            ->suffix('days')
                            ->required(fn (Get $get): bool => $get('kind') === Plan::KIND_PACK)
                            ->visible(fn (Get $get): bool => $get('kind') === Plan::KIND_PACK)
                            ->helperText('Counted from each purchase. Buying again adds the characters and starts this many days again; what is unused at the end is lost.'),
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
                    ->schema([
                        // The Arabic pages (pricing, dashboard, developer docs) show only Arabic.
                        Forms\Components\TextInput::make('name_ar')
                            ->label('اسم الخطة (العربية)')
                            ->maxLength(255)
                            ->placeholder('مثل: المبدع، الاحترافي')
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, $state, ?Plan $record): void {
                                // Prefilled from the site's own translation, when the plan has one.
                                $key = 'ui.pricing.plan_name_'.($record?->slug ?? '');
                                if (blank($state) && $record && __($key, [], 'ar') !== $key) {
                                    $component->state(__($key, [], 'ar'));
                                }
                            })
                            ->helperText('املأه دائمًا: تعرض الصفحات العربية (الأسعار ولوحة التحكم وتوثيق المطورين) الاسم العربي فقط، وإن تُرك فارغًا ظهر الاسم الإنجليزي فيها.'),
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
                            ->minValue(0)
                            ->live(onBlur: true)
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
                            ->helperText(fn () => 'Yearly price is auto-calculated at ' . SiteSetting::yearlyDiscountPercent() . '% discount (configurable in Billing Settings)'),
                        Forms\Components\Placeholder::make('yearly_preview')
                            ->label('Yearly Price Preview')
                            ->content(function (?Plan $record) {
                                if (!$record || $record->price_cents <= 0) return 'Free';
                                $multiplier = SiteSetting::yearlyDiscountMultiplier();
                                $percent = SiteSetting::yearlyDiscountPercent();
                                $yearlyDollars = round(($record->price_cents / 100) * 12 * $multiplier, 2);
                                return '$' . number_format($yearlyDollars, 2) . '/year ($' . number_format($yearlyDollars / 12, 2) . '/mo) — ' . $percent . '% off';
                            }),
                    ]),
                Forms\Components\Section::make('Discount / Promotion')
                    ->description('Set a time-limited discount. The discounted price shows on the pricing page with a countdown timer, and the pricing page and checkout say exactly how long each buyer keeps it and what they pay after. After the offer ends, new buyers pay the normal price.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('discount_percentage')
                            ->label('Discount (%)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(99)
                            ->suffix('%')
                            ->nullable()
                            ->reactive()
                            ->helperText('Leave empty to disable discount'),
                        Forms\Components\DateTimePicker::make('discount_ends_at')
                            ->label('Offer Ends At')
                            ->nullable()
                            ->native(false)
                            ->displayFormat('M d, Y H:i')
                            ->visible(fn (Get $get) => ! empty($get('discount_percentage')))
                            ->helperText('When new buyers stop getting the discount. Leave empty to keep the offer on.'),
                        Forms\Components\Select::make('discount_duration')
                            ->label('How long each buyer keeps it')
                            ->options([
                                Plan::DISCOUNT_FIRST_PAYMENT => 'First payment only',
                                Plan::DISCOUNT_FIRST_MONTHS => 'First few months',
                                Plan::DISCOUNT_FOREVER => 'For as long as they stay subscribed',
                            ])
                            ->default(Plan::DISCOUNT_FIRST_PAYMENT)
                            ->selectablePlaceholder(false)
                            ->reactive()
                            ->visible(fn (Get $get) => ! empty($get('discount_percentage')))
                            ->helperText('Buyers who already have the discount keep what they bought. A yearly plan gets it on its first yearly payment, unless it is "for as long as they stay subscribed".'),
                        Forms\Components\TextInput::make('discount_duration_months')
                            ->label('Number of months')
                            ->numeric()
                            ->minValue(2)
                            ->maxValue(12)
                            ->default(3)
                            ->suffix('months')
                            ->reactive()
                            ->visible(fn (Get $get) => ! empty($get('discount_percentage')) && $get('discount_duration') === Plan::DISCOUNT_FIRST_MONTHS)
                            ->required(fn (Get $get) => ! empty($get('discount_percentage')) && $get('discount_duration') === Plan::DISCOUNT_FIRST_MONTHS)
                            ->helperText('2 to 12 monthly payments.'),
                        Forms\Components\Placeholder::make('discount_preview')
                            ->label('What buyers see')
                            ->visible(fn (Get $get) => ! empty($get('discount_percentage')))
                            ->columnSpanFull()
                            ->content(function (Get $get, ?Plan $record) {
                                $priceDollars = (float) ($get('price_dollars') ?? ($record ? $record->price_cents / 100 : 0));
                                $discount = (int) ($get('discount_percentage') ?? 0);
                                if ($priceDollars <= 0 || $discount <= 0) return 'N/A';
                                $discounted = round($priceDollars * (1 - $discount / 100), 2);
                                $endsAt = $get('discount_ends_at');
                                $endLabel = $endsAt ? 'Offer until ' . \Carbon\Carbon::parse($endsAt)->format('M j, Y g:i A') : 'Offer has no end date';
                                $preview = new Plan([
                                    'discount_duration' => $get('discount_duration'),
                                    'discount_duration_months' => $get('discount_duration_months'),
                                ]);
                                $terms = DiscountTerms::line($preview, 'monthly', $discount, '$' . number_format($priceDollars, 2));

                                return '$' . number_format($priceDollars, 2) . ' → $' . number_format($discounted, 2) . '/mo. ' . $endLabel . '. Monthly buyers read: "' . str_replace(["\u{2066}", "\u{2069}"], '', $terms) . '"';
                            }),
                    ]),
                Forms\Components\Section::make('Voice & Quality')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('voice_tier')
                            ->label('Voice Quality Tier')
                            ->live()
                            ->options([
                                'standard' => 'Standard',
                                'hd' => 'Premium',
                            ])
                            ->required()
                            ->helperText('Voice quality level users get on this plan'),
                        Forms\Components\Select::make('processing_priority')
                            ->options([
                                'standard' => 'Standard',
                                'standard_plus' => 'Standard plus',
                                'high' => 'High',
                                'priority' => 'Priority',
                                'highest' => 'Highest',
                            ])
                            ->required()
                            ->default('standard')
                            ->helperText('Sets the developer API\'s default limits when the plan sets none (see Developer API below). High, priority and highest show "priority processing" on the pricing card; only High also uses the faster queue for background generations.'),
                        Forms\Components\Toggle::make('commercial_use_allowed')
                            ->label('Commercial Use Allowed')
                            ->helperText('Whether users can use generated audio commercially'),
                    ]),
                Forms\Components\Section::make('Usage Limits')
                    ->description('Set 0 for unlimited requests.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('monthly_chars')
                            ->label(fn (Get $get): string => $get('kind') === Plan::KIND_PACK ? 'Credits in the pack' : 'Monthly credits')
                            ->numeric()
                            // A paid plan with no credits would stop every member on it at once.
                            ->minValue(fn (Get $get): int => is_numeric($get('price_dollars')) && (float) $get('price_dollars') > 0 ? 1 : 0)
                            ->required()
                            ->live(debounce: 600)
                            ->afterStateUpdated(fn (Set $set, $state) => $set('monthly_minutes', \App\Support\Credits::minutesFor((int) $state)))
                            ->helperText(fn (Get $get): string => ($get('kind') === Plan::KIND_PACK
                                ? 'Credits each purchase adds (members are charged 15 credits a second of audio they get). Unused credits move into the plan a member upgrades to. '
                                : 'Members are charged for the audio they get: 15 credits a second (900 a minute). ')
                                .\App\Support\PlanCostEstimate::adminLine(
                                    (int) $get('monthly_chars'),
                                    is_numeric($get('price_dollars')) ? (float) $get('price_dollars') : null,
                                    $get('voice_tier') === 'hd',
                                )),
                        Forms\Components\TextInput::make('monthly_minutes')
                            ->label('Voice minutes a month')
                            ->suffix('min')
                            ->disabled()
                            ->dehydrated()
                            ->afterStateHydrated(fn (Forms\Components\TextInput $component, Get $get) => $component->state(\App\Support\Credits::minutesFor((int) $get('monthly_chars'))))
                            ->dehydrateStateUsing(fn (Get $get): int => \App\Support\Credits::minutesFor((int) $get('monthly_chars')))
                            ->helperText('Set by the credits ('.number_format(\App\Support\Credits::PER_MINUTE).' credits = 1 minute of audio). Credits are charged for the real length of each audio, so members always get exactly these minutes.'),
                        Forms\Components\TextInput::make('monthly_requests')
                            ->label('Monthly Requests')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0)
                            ->helperText('0 = unlimited requests'),
                        Forms\Components\TextInput::make('daily_requests')
                            ->label('Generations per day')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText('Most generations a member on this plan can make in one day, in the dashboard, the browser extension and the developer API together. Failed ones do not count. 0 = no daily limit. The day restarts at midnight '.config('listen.billing.daily_limit_timezone', 'Africa/Cairo').' time.'),
                        Forms\Components\TextInput::make('max_chars_per_request')
                            ->label('Max Characters Per Request')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('The studio and the browser extension take at most '.number_format(\App\Services\Api\ApiSpeechService::MAX_TEXT).' characters in one request, whatever is set here; under 200 counts as 200, and 0 hides this line on the pricing card. The developer API also caps one request at '.number_format(\App\Services\Api\ApiSpeechService::SYNC_MAX_TEXT).' when the client waits for the file and '.number_format(\App\Services\Api\ApiSpeechService::ASYNC_MAX_TEXT).' in the background.'),
                        Forms\Components\TextInput::make('recent_voices_days')
                            ->label('Recent Voices Retention (days)')
                            ->numeric()
                            ->minValue(0)
                            ->default(7)
                            ->helperText('How many days generated voices are retained. 0 = unlimited.'),
                    ]),
                Forms\Components\Section::make('Overage & Top-Up Pricing')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('allow_overage')
                            ->label('Allow Overage')
                            ->disabled()
                            ->helperText('Off for every plan: overage is not charged, so members always stop at their credits and voice minutes.'),
                        Forms\Components\TextInput::make('overage_price_dollars')
                            ->label('Overage Price per 1M chars ($)')
                            ->numeric()
                            ->minValue(0)
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
                            ->minValue(0)
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
                            ->minValue(0)
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
                        Forms\Components\Toggle::make('sound_effects_enabled')
                            ->label('Sound environments')
                            ->helperText('Echo, hall, phone call, radio and the other effects on the whole recording. Needs ffmpeg on the server. Plans without it see the option locked, with an upgrade button.')
                            ->default(false),
                        Forms\Components\TextInput::make('queue_priority_weight')
                            ->label('Queue Priority Weight')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->default(0)
                            ->helperText('Higher weight = higher priority in job queue (0-1000).'),
                    ]),
                Forms\Components\Section::make('Downloads')
                    ->description('The audio file types members on this plan can download. With more than one, the download button opens a small menu.')
                    ->schema([
                        Forms\Components\CheckboxList::make('download_formats')
                            ->label('File types')
                            ->options(\App\Support\AudioFormats::options())
                            ->default(\App\Support\AudioFormats::DEFAULT)
                            ->columns(5)
                            ->helperText(fn (): string => 'Nothing ticked keeps MP3. MP3 and WAV are made in the browser. M4A, OGG and FLAC are converted on the server'
                                .(\App\Support\AudioFormats::serverCanConvert()
                                    ? '. Ready on this server: '.(implode(', ', array_filter(['m4a', 'ogg', 'flac'], fn (string $f): bool => \App\Support\AudioFormats::serverSupports($f))) ?: 'none').'. A type the server cannot make stays hidden from members.'
                                    : '; this server has no ffmpeg yet, so they stay hidden from members until it does.')),
                    ]),
                Forms\Components\Section::make('Developer API')
                    ->description('Whether members on this plan can create API keys and call the developer API (/v1), and how fast. API generations use the plan\'s credits exactly like the dashboard. Leave the limits empty to use the defaults for the plan\'s processing priority.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Toggle::make('api_access_enabled')
                            ->label('Include the developer API')
                            ->helperText('On by default. The API only works on a paid plan (or pack) on the Premium voices that also allows commercial use: never on a free plan, a Standard-voices plan or during an unpaid trial. Turning it off, or turning commercial use off, stops this plan\'s keys at once (the keys are kept). Limits, credits, voices and the developer docs always follow this plan\'s current settings.')
                            ->default(true)
                            ->columnSpan(3),
                        Forms\Components\TextInput::make('api_requests_per_minute')
                            ->label('Generation requests a minute (whole account)')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->helperText('For all of a member\'s keys together. Status checks and other reads get three times this, at least 300 a minute. Empty or 0: the default for the plan\'s priority.')
                            ->placeholder(fn (Forms\Get $get): string => 'Default: '.(config('listen.api.requests_per_minute')[(string) ($get('processing_priority') ?: 'standard')] ?? 60)),
                        Forms\Components\TextInput::make('api_concurrency')
                            ->label('Generations at the same time')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(\App\Services\Api\ApiConcurrency::MAX_MEMBER_SLOTS)
                            ->helperText('While the client waits for the file. Background generations waiting or running: five times this, at least 10. Empty or 0: the default for the plan\'s priority.')
                            ->placeholder(fn (Forms\Get $get): string => 'Default: '.(config('listen.api.concurrency')[(string) ($get('processing_priority') ?: 'standard')] ?? 2)),
                    ]),
                Forms\Components\Section::make('Voice Cloning')
                    ->description('Turn cloning on for this plan, then set how many cloned voices each subscriber can keep. 0 uses the site-wide default.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('voice_cloning_enabled')
                            ->label('Enable Voice Cloning')
                            ->helperText('Subscribers on this plan can clone voices only while this is on. The site-wide voice cloning switch must also be on.')
                            ->default(false)
                            ->live()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('voice_cloning_max_voices')
                            ->label('Max Cloned Voices')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->required()
                            ->suffix('voices')
                            ->helperText('Leave this at 0 to use the site-wide default. Set 1–100 for an exact cap on this plan.'),
                    ]),
                Forms\Components\Section::make('Pricing page')
                    ->description('The lines under the price on this plan\'s card. They are written for you in English and Arabic from this plan\'s settings above, so changing a setting updates the card by itself. Tick the ones to show.')
                    ->schema([
                        Forms\Components\Toggle::make('pricing_use_custom_text')
                            ->label('Use my own text instead')
                            ->default(false)
                            ->live()
                            ->helperText('Off: the card shows the lines ticked below. On: it shows the English and Arabic lists you write yourself, which open below. Lists you wrote before are still saved.'),
                        Forms\Components\CheckboxList::make('pricing_lines')
                            ->label('Show on the pricing page')
                            ->options(PlanPricingLines::adminOptions())
                            ->descriptions(fn (Get $get, ?Plan $record): array => self::pricingLineDescriptions(self::planFromForm($get, $record)))
                            ->formatStateUsing(fn ($state): array => PlanPricingLines::shownKeys($state))
                            ->dehydrateStateUsing(fn ($state): array => PlanPricingLines::switchesFrom($state))
                            ->columns(2)
                            ->bulkToggleable()
                            ->live()
                            ->hidden(fn (Get $get): bool => (bool) $get('pricing_use_custom_text')),
                        Forms\Components\Grid::make(2)
                            ->hidden(fn (Get $get): bool => (bool) $get('pricing_use_custom_text'))
                            ->schema([
                                self::extraLinesRepeater('pricing_extra_lines_en', 'Extra lines (English)', 'e.g. Arabic and English voices'),
                                self::extraLinesRepeater('pricing_extra_lines_ar', 'Extra lines (Arabic)', 'مثل: أصوات عربية وإنجليزية'),
                            ]),
                        Forms\Components\Placeholder::make('pricing_preview')
                            ->label('Preview')
                            ->content(fn (Get $get, ?Plan $record): HtmlString => self::pricingPreview(self::planFromForm($get, $record), $get))
                            ->hidden(fn (Get $get): bool => (bool) $get('pricing_use_custom_text')),
                    ]),
                Forms\Components\Section::make('Plan Features (English)')
                    ->description('Your own lines for the English card, used while "Use my own text instead" is on. An empty list starts from the automatic lines.')
                    ->visible(fn (Get $get): bool => (bool) $get('pricing_use_custom_text'))
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

                                $component->state(self::normalizeRepeaterItems(PlanPricingLines::automatic($record, 'en')));
                            })
                            ->addActionLabel('Add Feature')
                            ->defaultItems(0)
                            // Drag a line by its handle; the arrows still move it one place.
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => self::lineLabel($state))
                            ->collapsible(),
                    ]),
                Forms\Components\Section::make('خصائص الخطة (العربية)')
                    ->description('سطورك الخاصة لبطاقة الأسعار العربية، وتُستخدم عند تفعيل "Use my own text instead". القائمة الفارغة تبدأ من السطور التلقائية.')
                    ->visible(fn (Get $get): bool => (bool) $get('pricing_use_custom_text'))
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

                                $component->state(self::normalizeRepeaterItems(PlanPricingLines::automatic($record, 'ar')));
                            })
                            ->addActionLabel('إضافة خاصية')
                            ->defaultItems(0)
                            // Drag a line by its handle; the arrows still move it one place.
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => self::lineLabel($state))
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
                    ->label('Credits/mo')
                    ->numeric()
                    ->description(fn (Plan $record): string => number_format(\App\Support\Credits::minutesFor((int) $record->monthly_chars)).' voice min')
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
                Tables\Columns\IconColumn::make('sound_effects_enabled')
                    ->label('Sound environments')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('commercial_use_allowed')
                    ->label('Commercial')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('discount_percentage')
                    ->label('Discount')
                    ->formatStateUsing(fn (?int $state, Plan $record) => $record->hasActiveDiscount()
                        ? $state . '%'
                        : ($state ? $state . '% (expired)' : '—'))
                    ->color(fn (Plan $record) => $record->hasActiveDiscount() ? 'success' : 'gray')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\IconColumn::make('voice_cloning_enabled')
                    ->label('Voice Cloning')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('voice_cloning_max_voices')
                    ->label('Clone limit')
                    ->formatStateUsing(function ($state, Plan $record): string {
                        if (! $record->voice_cloning_enabled) {
                            return 'Off';
                        }

                        $configured = (int) $state;

                        return $configured > 0 ? $configured.' voices' : 'Site default';
                    })
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
                Tables\Columns\TextColumn::make('stripe_price_monthly')
                    ->label('Stripe monthly price')
                    ->placeholder('-')
                    ->copyable()
                    ->color(fn (Plan $record): ?string => self::priceIsShared($record->stripe_price_monthly) ? 'danger' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stripe_price_yearly')
                    ->label('Stripe yearly price')
                    ->placeholder('-')
                    ->copyable()
                    ->color(fn (Plan $record): ?string => self::priceIsShared($record->stripe_price_yearly) ? 'danger' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->hidden(fn (Plan $record): bool => $record->isPack())
                    ->requiresConfirmation()
                    ->modalHeading('Sync Plan to Stripe')
                    ->modalDescription('This will create or update the Stripe product and prices for this plan. Continue?')
                    ->action(function (Plan $record) {
                        try {
                            $syncService = app(StripeProductSyncService::class);
                            self::stripeSyncNotice(
                                $syncService->syncPlan($record),
                                'Stripe Sync Successful',
                                "Plan '{$record->name}' has been synced to Stripe.",
                            )->send();
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
                            $skipped = 0;

                            foreach ($records as $plan) {
                                try {
                                    $result = $syncService->syncPlan($plan);
                                    if ($result['success']) {
                                        $success++;
                                    } elseif (! empty($result['skipped'])) {
                                        $skipped++;
                                    } else {
                                        $failed++;
                                    }
                                } catch (\Exception $e) {
                                    $failed++;
                                }
                            }

                            self::stripeSyncCountsNotice($success, $failed, $skipped)->send();
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
     * The plan as the form currently has it, so the preview follows unsaved changes.
     */
    private static function planFromForm(Get $get, ?Plan $record): Plan
    {
        $plan = $record ? $record->replicate() : new Plan;

        foreach (['voice_tier', 'max_chars_per_request', 'monthly_chars', 'monthly_requests', 'processing_priority', 'commercial_use_allowed',
            'recent_voices_days', 'dialogue_enabled', 'custom_prompt_enabled', 'voice_cloning_enabled', 'voice_cloning_max_voices',
            'download_formats', 'allow_overage'] as $field) {
            $plan->setAttribute($field, $get($field));
        }

        $topup = (float) ($get('topup_char_price_dollars') ?? 0);
        $plan->topup_char_price_cents = $topup > 0 ? (int) round($topup * 100) : null;
        $overage = (float) ($get('overage_price_dollars') ?? 0);
        $plan->overage_price_per_million_cents = $overage > 0 ? (int) round($overage * 100) : null;

        return $plan;
    }

    /**
     * Under each switch: what the line will say, or why this plan has no such line.
     *
     * @return array<string, string>
     */
    private static function pricingLineDescriptions(Plan $plan): array
    {
        $descriptions = [];
        foreach (array_keys(PlanPricingLines::LINES) as $key) {
            $en = PlanPricingLines::value($plan, $key, 'en');
            $ar = PlanPricingLines::value($plan, $key, 'ar');
            $descriptions[$key] = match (true) {
                $en === null => (string) PlanPricingLines::whyHidden($plan, $key),
                $en === __('ui.pricing.lines.included', [], 'en') => 'Shows: included (✓)',
                $en === $ar => 'Shows: '.$en,
                default => 'Shows: '.$en.'  /  '.$ar,
            };
        }

        return $descriptions;
    }

    private static function extraLinesRepeater(string $name, string $label, string $placeholder): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($name)
            ->label($label)
            ->helperText('Shown after the automatic lines. Leave the value empty for a check mark.')
            ->schema([
                Forms\Components\TextInput::make('label')
                    ->label('Text')
                    ->required()
                    ->maxLength(80)
                    ->placeholder($placeholder)
                    ->live(onBlur: true)
                    ->columnSpan(2),
                Forms\Components\TextInput::make('value')
                    ->label('Value')
                    ->maxLength(40)
                    ->placeholder('✓')
                    ->live(onBlur: true),
                Forms\Components\Select::make('icon')
                    ->label('Icon')
                    ->options(PlanPricingLines::ICONS)
                    ->default('check')
                    ->required()
                    ->live(),
            ])
            ->columns(4)
            ->defaultItems(0)
            ->addActionLabel('Add a line')
            ->reorderableWithButtons()
            ->itemLabel(fn (array $state): ?string => self::lineLabel($state))
            ->live();
    }

    /**
     * A line's text in its header, so even a collapsed list can be dragged into order.
     *
     * @param  array<string, mixed>  $state
     */
    private static function lineLabel(array $state): ?string
    {
        $label = trim((string) ($state['label'] ?? ''));
        $value = trim((string) ($state['value'] ?? ''));
        if ($label === '') {
            return null;
        }

        return $value === '' ? $label : $label.' — '.$value;
    }

    private static function pricingPreview(Plan $plan, Get $get): HtmlString
    {
        $plan->pricing_use_custom_text = false;
        $plan->pricing_lines = PlanPricingLines::switchesFrom($get('pricing_lines'));
        $plan->pricing_extra_lines_en = $get('pricing_extra_lines_en');
        $plan->pricing_extra_lines_ar = $get('pricing_extra_lines_ar');

        $columns = '';
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
            $rows = '';
            foreach (PlanPricingLines::forPlan($plan, $locale) as $line) {
                $rows .= '<li style="display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid rgba(127,127,127,.2)">'
                    .'<span>'.e($line['label']).'</span><strong>'.e($line['value']).'</strong></li>';
            }
            $headline = number_format((int) $plan->monthly_chars).' '.e(__('ui.pricing.characters_per_month', [], $locale))
                .' · '.e(__('ui.pricing.voice_minutes_short', ['count' => number_format(\App\Support\Credits::minutesFor((int) $plan->monthly_chars))], $locale));
            $columns .= '<div dir="'.$dir.'" style="flex:1;min-width:16rem">'
                .'<div style="font-weight:600;margin-bottom:.4rem">'.$headline.'</div>'
                .'<ul style="margin:0;padding:0;list-style:none">'.($rows !== '' ? $rows : '<li>—</li>').'</ul></div>';
        }

        return new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:2rem">'.$columns.'</div>');
    }

    /**
     * What a plan's Stripe sync really did (StripeProductSyncService::syncPlan()):
     * synced, left alone because this is not the live site, or failed.
     *
     * @param  array{success: bool, message: string, skipped?: bool}  $result
     */
    public static function stripeSyncNotice(array $result, string $syncedTitle, string $syncedBody): Notification
    {
        if ($result['success']) {
            return Notification::make()->title($syncedTitle)->body($syncedBody)->success();
        }

        if (! empty($result['skipped'])) {
            return Notification::make()->title('Stripe not changed')->body($result['message'])->warning();
        }

        return Notification::make()
            ->title('Stripe Sync Failed')
            ->body($result['message'].' Stripe may not match the plan yet: use "Sync Stripe" on the plan to try again.')
            ->danger()
            ->persistent();
    }

    /** The same for several plans at once. */
    public static function stripeSyncCountsNotice(int $synced, int $failed, int $skipped): Notification
    {
        $body = "Synced: {$synced}, Failed: {$failed}";
        if ($skipped > 0) {
            $body .= ", Not changed (not the live site): {$skipped}";
        }

        $notice = Notification::make()->title('Stripe Sync Complete')->body($body);

        return match (true) {
            $failed > 0 => $notice->danger()->persistent(),
            $skipped > 0 => $notice->warning(),
            default => $notice->success(),
        };
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

    private static function priceIsShared(?string $priceId): bool
    {
        $priceId = trim((string) $priceId);
        if ($priceId === '') {
            return false;
        }

        return Plan::query()
            ->where(fn ($query) => $query->where('stripe_price_monthly', $priceId)->orWhere('stripe_price_yearly', $priceId))
            ->count() > 1;
    }
}
