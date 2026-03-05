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
        $this->form->fill([
            'stripe_key_set' => ! empty(config('cashier.key', config('services.stripe.key'))),
            'stripe_secret_set' => ! empty(config('cashier.secret', config('services.stripe.secret'))),
            'stripe_webhook_secret_set' => ! empty(config('cashier.webhook.secret', config('services.stripe.webhook_secret'))),
            'require_saved_payment_method' => (string) SiteSetting::get('billing_require_saved_payment_method', config('listen.billing.require_saved_payment_method', '1')) === '1',
            'grace_days' => (int) SiteSetting::get('billing_grace_days', config('listen.billing.grace_days', 7)),
            'webhook_emails_enabled' => (string) SiteSetting::get('billing_webhook_emails', config('listen.billing.webhook_email_enabled', '1')) === '1',
        ]);
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $group = 'billing';

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

        SiteSetting::flushGroupCache($group);

        Notification::make()
            ->title('Billing settings saved')
            ->success()
            ->send();
    }
}
