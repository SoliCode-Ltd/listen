<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class BillingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Billing Settings';

    protected static string $view = 'filament.pages.billing-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $state = [
            'stripe_key_set' => ! empty(config('cashier.key', config('services.stripe.key'))),
            'stripe_secret_set' => ! empty(config('cashier.secret', config('services.stripe.secret'))),
            'stripe_webhook_secret_set' => ! empty(config('cashier.webhook.secret', config('services.stripe.webhook_secret'))),
            'yearly_discount_percentage' => (int) SiteSetting::get('billing_yearly_discount_percentage', '20'),
            'require_saved_payment_method' => (string) SiteSetting::get('billing_require_saved_payment_method', config('listen.billing.require_saved_payment_method', '1')) === '1',
            'grace_days' => (int) SiteSetting::get('billing_grace_days', config('listen.billing.grace_days', 7)),
            'webhook_emails_enabled' => (string) SiteSetting::get('billing_webhook_emails', config('listen.billing.webhook_email_enabled', '1')) === '1',
            'trial_enabled' => (string) SiteSetting::get('billing_trial_enabled', '1') === '1',
            'trial_plan_slug' => (string) SiteSetting::get('billing_trial_plan_slug', (string) config('listen.billing.creator_trial.plan_slug', 'creator')),
            'trial_quota_plan_slug' => (string) SiteSetting::get('billing_trial_quota_plan_slug', (string) config('listen.billing.creator_trial.quota_plan_slug', 'starter')),
            'trial_days' => (int) SiteSetting::get('billing_trial_days', (int) config('listen.billing.creator_trial.days', 7)),
            'pricing_forced_country_code' => (string) SiteSetting::get('pricing_forced_country_code', ''),
            'pricing_local_country_code' => (string) SiteSetting::get('pricing_local_country_code', 'EG'),
            'pricing_local_currency_code' => (string) SiteSetting::get('pricing_local_currency_code', 'EGP'),
            'pricing_local_detect_public_ip' => (string) SiteSetting::get('pricing_local_detect_public_ip', '0') === '1',
            'pricing_fallback_country_code' => (string) SiteSetting::get('pricing_fallback_country_code', 'US'),
            'pricing_fallback_currency_code' => (string) SiteSetting::get('pricing_fallback_currency_code', 'USD'),
            'manual_instapay_enabled' => (string) SiteSetting::get('manual_instapay_enabled', '0') === '1',
            'manual_instapay_payment_link' => (string) SiteSetting::get('manual_instapay_payment_link', ''),
            'manual_instapay_qr_image' => (string) SiteSetting::get('manual_instapay_qr_image', ''),
            'payment_logo_instapay' => (string) SiteSetting::get('payment_logo_instapay', ''),
            'manual_vodafone_cash_enabled' => (string) SiteSetting::get('manual_vodafone_cash_enabled', '0') === '1',
            'manual_vodafone_cash_payment_link' => (string) SiteSetting::get('manual_vodafone_cash_payment_link', ''),
            'manual_vodafone_cash_qr_image' => (string) SiteSetting::get('manual_vodafone_cash_qr_image', ''),
            'payment_logo_vodafone_cash' => (string) SiteSetting::get('payment_logo_vodafone_cash', ''),
            'payment_logo_stripe' => (string) SiteSetting::get('payment_logo_stripe', ''),
            'manual_payment_instructions_en' => (string) SiteSetting::get('manual_payment_instructions_en', ''),
            'manual_payment_instructions_ar' => (string) SiteSetting::get('manual_payment_instructions_ar', ''),
        ];

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Stripe Connection')
                    ->description('Stripe API keys are set via environment variables (.env). This panel shows their current status.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Placeholder::make('stripe_key_status')
                            ->label('Publishable Key')
                            ->content(fn (Forms\Get $get): string => $get('stripe_key_set')
                                ? '✅ Configured'
                                : '❌ Not set — add STRIPE_KEY to .env'),
                        Forms\Components\Placeholder::make('stripe_secret_status')
                            ->label('Secret Key')
                            ->content(fn (Forms\Get $get): string => $get('stripe_secret_set')
                                ? '✅ Configured'
                                : '❌ Not set — add STRIPE_SECRET to .env'),
                        Forms\Components\Placeholder::make('stripe_webhook_status')
                            ->label('Webhook Secret')
                            ->content(fn (Forms\Get $get): string => $get('stripe_webhook_secret_set')
                                ? '✅ Configured'
                                : '⚠️ Not set — add STRIPE_WEBHOOK_SECRET to .env'),
                    ]),

                Forms\Components\Section::make('Stripe Products & Prices')
                    ->description('Stripe prices are now automatically synced from the Plans resource. Use the "Sync Stripe" button on each plan.')
                    ->schema([
                        Forms\Components\Placeholder::make('stripe_sync_info')
                            ->label('How it works')
                            ->content('Go to Billing → Plans, then click "Sync Stripe" on any plan to create its product and prices in Stripe automatically. You no longer need to manually enter Price IDs.'),
                        Forms\Components\Placeholder::make('stripe_plans_status')
                            ->label('Plans Status')
                            ->content(function (): string {
                                $plans = Plan::where('is_active', true)->where('price_cents', '>', 0)->get();
                                $synced = $plans->filter(fn ($p) => $p->hasStripeConfig())->count();
                                $total = $plans->count();
                                return "{$synced} / {$total} plans synced to Stripe";
                            }),
                    ]),

                Forms\Components\Section::make('Billing Behavior')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('yearly_discount_percentage')
                            ->label('Yearly Billing Discount (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99)
                            ->default(20)
                            ->suffix('%')
                            ->helperText('Discount applied when users choose yearly billing (e.g. 20 = 20% off). Affects pricing page, checkout, and Stripe prices.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('yearly_discount_preview', $state)),
                        Forms\Components\Placeholder::make('yearly_discount_preview')
                            ->label('Yearly Multiplier Preview')
                            ->content(function (Forms\Get $get): string {
                                $percent = max(0, min(99, (int) ($get('yearly_discount_percentage') ?? 20)));
                                $multiplier = round(1 - ($percent / 100), 2);
                                return "Monthly price x 12 x {$multiplier} = yearly price ({$percent}% off)";
                            }),
                        Forms\Components\Toggle::make('require_saved_payment_method')
                            ->label('Require Saved Payment Method')
                            ->helperText('Require users to have a payment method saved before subscribing.'),
                        Forms\Components\TextInput::make('grace_days')
                            ->label('Grace Period (days)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(90)
                            ->default(7)
                            ->helperText('Days after cancellation before access is revoked.'),
                        Forms\Components\Toggle::make('webhook_emails_enabled')
                            ->label('Webhook Email Notifications')
                            ->helperText('Send email notifications on subscription lifecycle events.'),
                    ]),

                Forms\Components\Section::make('Card trial')
                    ->description('Turn the free card trial on or off, and choose which plan it sells. Confirm and Pay is unchanged.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('trial_enabled')
                            ->label('Enable free card trial')
                            ->helperText('Off hides the trial button. People already in a trial keep it until it ends or they cancel. Confirm and Pay stays available.')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('trial_plan_slug')
                            ->label('Trial plan')
                            ->options(fn (): array => Plan::query()
                                ->where('is_active', true)
                                ->where('price_cents', '>', 0)
                                ->orderBy('price_cents')
                                ->pluck('name', 'slug')
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText('The monthly price of this plan is what Stripe charges when the trial ends. Yearly checkout stays pay-now.'),
                        Forms\Components\Select::make('trial_quota_plan_slug')
                            ->label('Usage cap during the trial')
                            ->options(fn (): array => Plan::query()
                                ->where('is_active', true)
                                ->orderBy('price_cents')
                                ->pluck('name', 'slug')
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText('Features follow the trial plan. Character and minute limits follow this plan, with overage off.'),
                        Forms\Components\TextInput::make('trial_days')
                            ->label('Trial length (days)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(90)
                            ->required()
                            ->default(7)
                            ->helperText('New trials use this length. A trial that already started keeps its original end date.'),
                    ]),

                Forms\Components\Section::make('Country Detection & Fallback')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('pricing_forced_country_code')
                            ->label('Force Country Code')
                            ->maxLength(2)
                            ->placeholder('EG')
                            ->helperText('Optional global override for testing or temporary launches.'),
                        Forms\Components\TextInput::make('pricing_local_country_code')
                            ->label('Local Dev Country Code')
                            ->maxLength(2)
                            ->required()
                            ->default('EG')
                            ->helperText('Used on localhost, .test, .local, and private-network development requests.'),
                        Forms\Components\TextInput::make('pricing_local_currency_code')
                            ->label('Local Dev Currency')
                            ->maxLength(3)
                            ->required()
                            ->default('EGP')
                            ->helperText('Keeps local development pricing aligned with the local market override.'),
                        Forms\Components\Toggle::make('pricing_local_detect_public_ip')
                            ->label('Use Public IP Lookup on Localhost')
                            ->helperText('Optional. When enabled, localhost tries public-IP geolocation before using the local dev market.'),
                        Forms\Components\TextInput::make('pricing_fallback_country_code')
                            ->label('Fallback Country Code')
                            ->maxLength(2)
                            ->required()
                            ->default('US'),
                        Forms\Components\TextInput::make('pricing_fallback_currency_code')
                            ->label('Fallback Currency')
                            ->maxLength(3)
                            ->required()
                            ->default('USD'),
                        Forms\Components\Placeholder::make('pricing_detection_order')
                            ->label('Detection Order')
                            ->columnSpanFull()
                            ->content('For localhost/private-network requests, local market override is applied first (or public-IP lookup if enabled). For public traffic, trusted CDN headers are checked first, then IP geolocation providers and Accept-Language region. If no signal is available, fallback market is used.'),
                    ]),

                Forms\Components\Section::make('Egypt Manual Payment Methods')
                    ->description('Shown directly on the billing page for Egyptian visitors.')
                    ->schema([
                        Forms\Components\Tabs::make('manual_payment_tabs')
                            ->columnSpanFull()
                            ->tabs([
                                Forms\Components\Tabs\Tab::make('Stripe')
                                    ->schema([
                                        Forms\Components\FileUpload::make('payment_logo_stripe')
                                            ->label('Stripe Logo')
                                            ->disk('public')
                                            ->directory('settings/payments/logos')
                                            ->image()
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                            ->imageEditor()
                                            ->maxSize(2048)
                                            ->helperText('Recommended: transparent PNG/WebP, wide ratio for best checkout card rendering.'),
                                    ]),
                                Forms\Components\Tabs\Tab::make('InstaPay')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('manual_instapay_enabled')
                                            ->label('Enable InstaPay')
                                            ->columnSpanFull(),
                                        Forms\Components\FileUpload::make('payment_logo_instapay')
                                            ->label('InstaPay Logo')
                                            ->disk('public')
                                            ->directory('settings/payments/logos')
                                            ->image()
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                            ->imageEditor()
                                            ->maxSize(2048)
                                            ->helperText('Use the official brand logo image (PNG/WebP preferred).'),
                                        Forms\Components\TextInput::make('manual_instapay_payment_link')
                                            ->label('InstaPay Payment Link')
                                            ->url()
                                            ->maxLength(500)
                                            ->helperText('Optional. Users can open this link directly to continue payment.'),
                                        Forms\Components\FileUpload::make('manual_instapay_qr_image')
                                            ->label('InstaPay QR Image')
                                            ->disk('public')
                                            ->directory('settings/payments/qr')
                                            ->image()
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                            ->imageEditor()
                                            ->maxSize(4096)
                                            ->helperText('Optional. Upload PNG/JPG/WebP QR code image.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Tabs\Tab::make('Vodafone Cash')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('manual_vodafone_cash_enabled')
                                            ->label('Enable Vodafone Cash')
                                            ->columnSpanFull(),
                                        Forms\Components\FileUpload::make('payment_logo_vodafone_cash')
                                            ->label('Vodafone Cash Logo')
                                            ->disk('public')
                                            ->directory('settings/payments/logos')
                                            ->image()
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                            ->imageEditor()
                                            ->maxSize(2048)
                                            ->helperText('Use the official brand logo image (PNG/WebP preferred).'),
                                        Forms\Components\TextInput::make('manual_vodafone_cash_payment_link')
                                            ->label('Vodafone Cash Payment Link')
                                            ->url()
                                            ->maxLength(500)
                                            ->helperText('Optional. Users can open this link directly to continue payment.'),
                                        Forms\Components\FileUpload::make('manual_vodafone_cash_qr_image')
                                            ->label('Vodafone Cash QR Image')
                                            ->disk('public')
                                            ->directory('settings/payments/qr')
                                            ->image()
                                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                            ->imageEditor()
                                            ->maxSize(4096)
                                            ->helperText('Optional. Upload PNG/JPG/WebP QR code image.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Tabs\Tab::make('Instructions')
                                    ->schema([
                                        Forms\Components\Textarea::make('manual_payment_instructions_en')
                                            ->label('Manual Instructions (English)')
                                            ->rows(3),
                                        Forms\Components\Textarea::make('manual_payment_instructions_ar')
                                            ->label('Manual Instructions (Arabic)')
                                            ->rows(3),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $group = 'billing';
        // Save yearly discount
        if (array_key_exists('yearly_discount_percentage', $data)) {
            SiteSetting::set('billing_yearly_discount_percentage', (string) max(0, min(99, (int) $data['yearly_discount_percentage'])), $group);
        }
        // Save billing behavior settings
        if (array_key_exists('require_saved_payment_method', $data)) {
            SiteSetting::set('billing_require_saved_payment_method', $data['require_saved_payment_method'] ? '1' : '0', $group);
        }
        if (array_key_exists('grace_days', $data)) {
            SiteSetting::set('billing_grace_days', (string) (int) $data['grace_days'], $group);
        }
        if (array_key_exists('webhook_emails_enabled', $data)) {
            SiteSetting::set('billing_webhook_emails', $data['webhook_emails_enabled'] ? '1' : '0', $group);
        }
        if (array_key_exists('trial_enabled', $data)) {
            SiteSetting::set('billing_trial_enabled', $data['trial_enabled'] ? '1' : '0', $group);
        }
        if (array_key_exists('trial_plan_slug', $data)) {
            SiteSetting::set('billing_trial_plan_slug', strtolower(trim((string) $data['trial_plan_slug'])), $group);
        }
        if (array_key_exists('trial_quota_plan_slug', $data)) {
            SiteSetting::set('billing_trial_quota_plan_slug', strtolower(trim((string) $data['trial_quota_plan_slug'])), $group);
        }
        if (array_key_exists('trial_days', $data)) {
            SiteSetting::set('billing_trial_days', (string) max(1, min(90, (int) $data['trial_days'])), $group);
        }
        if (array_key_exists('pricing_forced_country_code', $data)) {
            SiteSetting::set('pricing_forced_country_code', strtoupper(trim((string) $data['pricing_forced_country_code'])), $group);
        }
        if (array_key_exists('pricing_local_country_code', $data)) {
            SiteSetting::set('pricing_local_country_code', strtoupper(trim((string) $data['pricing_local_country_code'])), $group);
        }
        if (array_key_exists('pricing_local_currency_code', $data)) {
            SiteSetting::set('pricing_local_currency_code', strtoupper(trim((string) $data['pricing_local_currency_code'])), $group);
        }
        if (array_key_exists('pricing_local_detect_public_ip', $data)) {
            SiteSetting::set('pricing_local_detect_public_ip', $data['pricing_local_detect_public_ip'] ? '1' : '0', $group);
        }
        if (array_key_exists('pricing_fallback_country_code', $data)) {
            SiteSetting::set('pricing_fallback_country_code', strtoupper(trim((string) $data['pricing_fallback_country_code'])), $group);
        }
        if (array_key_exists('pricing_fallback_currency_code', $data)) {
            SiteSetting::set('pricing_fallback_currency_code', strtoupper(trim((string) $data['pricing_fallback_currency_code'])), $group);
        }
        if (array_key_exists('manual_instapay_enabled', $data)) {
            SiteSetting::set('manual_instapay_enabled', $data['manual_instapay_enabled'] ? '1' : '0', $group);
        }
        if (array_key_exists('manual_instapay_payment_link', $data)) {
            SiteSetting::set('manual_instapay_payment_link', trim((string) $data['manual_instapay_payment_link']), $group);
        }
        if (array_key_exists('manual_instapay_qr_image', $data)) {
            SiteSetting::set('manual_instapay_qr_image', trim((string) $data['manual_instapay_qr_image']), $group);
        }
        if (array_key_exists('payment_logo_instapay', $data)) {
            SiteSetting::set('payment_logo_instapay', trim((string) $data['payment_logo_instapay']), $group);
        }
        if (array_key_exists('manual_vodafone_cash_enabled', $data)) {
            SiteSetting::set('manual_vodafone_cash_enabled', $data['manual_vodafone_cash_enabled'] ? '1' : '0', $group);
        }
        if (array_key_exists('manual_vodafone_cash_payment_link', $data)) {
            SiteSetting::set('manual_vodafone_cash_payment_link', trim((string) $data['manual_vodafone_cash_payment_link']), $group);
        }
        if (array_key_exists('manual_vodafone_cash_qr_image', $data)) {
            SiteSetting::set('manual_vodafone_cash_qr_image', trim((string) $data['manual_vodafone_cash_qr_image']), $group);
        }
        if (array_key_exists('payment_logo_vodafone_cash', $data)) {
            SiteSetting::set('payment_logo_vodafone_cash', trim((string) $data['payment_logo_vodafone_cash']), $group);
        }
        if (array_key_exists('payment_logo_stripe', $data)) {
            SiteSetting::set('payment_logo_stripe', trim((string) $data['payment_logo_stripe']), $group);
        }
        if (array_key_exists('manual_payment_instructions_en', $data)) {
            SiteSetting::set('manual_payment_instructions_en', trim((string) $data['manual_payment_instructions_en']), $group);
        }
        if (array_key_exists('manual_payment_instructions_ar', $data)) {
            SiteSetting::set('manual_payment_instructions_ar', trim((string) $data['manual_payment_instructions_ar']), $group);
        }

        SiteSetting::flushGroupCache($group);

        Notification::make()
            ->title('Billing settings saved')
            ->success()
            ->send();
    }
}
