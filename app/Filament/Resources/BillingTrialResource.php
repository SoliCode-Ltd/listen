<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingTrialResource\Pages;
use App\Filament\Widgets\TrialOutcomeStats;
use App\Models\BillingTrial;
use App\Services\Billing\TrialSubscriptionService;
use Filament\Notifications\Notification;
use Filament\Forms;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BillingTrialResource extends Resource
{
    protected static ?string $model = BillingTrial::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Trials';

    protected static ?string $modelLabel = 'Trial';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Outcome')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('status')->disabled(),
                    Forms\Components\Toggle::make('is_admin_test')->disabled(),
                    Forms\Components\TextInput::make('blocked_reason')->disabled(),
                    Forms\Components\TextInput::make('billing_cycle')->label('Billing')->disabled(),
                    Forms\Components\TextInput::make('amount_cents')->label('Price (cents)')->disabled(),
                    Forms\Components\TextInput::make('amount_paid_cents')->label('Paid (cents)')->disabled(),
                    Forms\Components\TextInput::make('currency')->disabled(),
                    Forms\Components\DateTimePicker::make('started_at')->disabled(),
                    Forms\Components\DateTimePicker::make('trial_ends_at')->disabled(),
                    Forms\Components\DateTimePicker::make('paid_at')->disabled(),
                    Forms\Components\DateTimePicker::make('canceled_at')->disabled(),
                    Forms\Components\DateTimePicker::make('charge_failed_at')->disabled(),
                ]),
            Forms\Components\Section::make('Account')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('user_id')->disabled(),
                    Forms\Components\TextInput::make('email_normalized')->disabled(),
                    Forms\Components\TextInput::make('phone_normalized')->disabled(),
                    Forms\Components\TextInput::make('device_id')->disabled(),
                    Forms\Components\TextInput::make('registration_ip')->disabled(),
                ]),
            Forms\Components\Section::make('Card')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('card_brand')->disabled(),
                    Forms\Components\TextInput::make('card_last4')->disabled(),
                    Forms\Components\TextInput::make('card_fingerprint')->disabled(),
                    Forms\Components\TextInput::make('cvc_check')->disabled(),
                    Forms\Components\Toggle::make('off_session_ready')->disabled(),
                    Forms\Components\TextInput::make('stripe_customer_id')->disabled(),
                    Forms\Components\TextInput::make('stripe_subscription_id')->disabled(),
                    Forms\Components\TextInput::make('stripe_invoice_id')->disabled(),
                    Forms\Components\TextInput::make('setup_intent_id')->disabled(),
                ]),
            Forms\Components\Section::make('Starter cap used during the trial')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('chars_used')->disabled(),
                    Forms\Components\TextInput::make('chars_limit')->disabled(),
                    Forms\Components\TextInput::make('minutes_used')->disabled(),
                    Forms\Components\TextInput::make('minutes_limit')->disabled(),
                    Forms\Components\TextInput::make('requests_used')->disabled(),
                    Forms\Components\TextInput::make('max_chars_per_request')->disabled(),
                    Forms\Components\TextInput::make('premium_minutes_limit')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('User')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        BillingTrial::STATUS_PAID => 'success',
                        BillingTrial::STATUS_TRIALING => 'info',
                        BillingTrial::STATUS_CANCELED => 'warning',
                        BillingTrial::STATUS_CHARGE_FAILED, BillingTrial::STATUS_BLOCKED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_admin_test')->boolean()->label('Admin test'),
                Tables\Columns\TextColumn::make('card_brand')->label('Card'),
                Tables\Columns\TextColumn::make('card_last4')->label('Last 4'),
                Tables\Columns\TextColumn::make('plan.name')->label('Plan'),
                Tables\Columns\TextColumn::make('billing_cycle')->label('Billing')->badge(),
                Tables\Columns\TextColumn::make('amount_cents')->label('Price')->formatStateUsing(fn ($state) => '$'.number_format(((int) $state) / 100, 2)),
                Tables\Columns\TextColumn::make('amount_paid_cents')->label('Paid')->formatStateUsing(fn ($state) => $state === null ? '-' : '$'.number_format(((int) $state) / 100, 2)),
                Tables\Columns\TextColumn::make('chars_used')->label('Chars')->numeric(),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('trial_ends_at')->dateTime()->label('Ends'),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('canceled_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('blocked_reason')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    BillingTrial::STATUS_TRIALING => 'In trial',
                    BillingTrial::STATUS_PAID => 'Paid',
                    BillingTrial::STATUS_CANCELED => 'Canceled',
                    BillingTrial::STATUS_CHARGE_FAILED => 'Charge failed',
                    BillingTrial::STATUS_BLOCKED => 'Blocked',
                ]),
                Tables\Filters\TernaryFilter::make('is_admin_test')->label('Admin test'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::configureCancelAction(Tables\Actions\Action::make('cancelTrial')),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * "Cancel trial": the member is not charged when the trial ends and keeps
     * the trial plan until then, as if they had cancelled it themselves.
     * Shared by the list row and the trial page.
     *
     * @template T of \Filament\Actions\Action|\Filament\Tables\Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    public static function configureCancelAction($action)
    {
        $enabled = TrialSubscriptionService::stripeTrialActionsEnabled();

        return $action
            ->label('Cancel trial')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (BillingTrial $record): bool => $record->status === BillingTrial::STATUS_TRIALING && $record->paid_at === null)
            ->disabled(! $enabled)
            ->tooltip($enabled ? null : 'Turned off on this server: staging uses the live Stripe keys. Set LISTEN_STRIPE_TRIAL_ACTIONS=true on live only.')
            ->requiresConfirmation()
            ->modalHeading(fn (BillingTrial $record): string => 'Cancel the free trial of '.($record->user?->email ?? 'this member').'?')
            ->modalDescription(fn (BillingTrial $record): string => 'Their card ('.($record->card_brand ?? 'card').' •'.($record->card_last4 ?? '????').')'
                .' will not be charged when the trial ends on '.($record->trial_ends_at?->format('j M Y') ?? 'its end date').'.'
                .' They keep the trial plan until then. This is done in Stripe and cannot be undone; they would have to start a new trial.')
            ->modalSubmitActionLabel('Cancel the trial')
            ->action(function (BillingTrial $record): void {
                if (! TrialSubscriptionService::stripeTrialActionsEnabled()) {
                    return;
                }

                $cancelled = app(TrialSubscriptionService::class)->cancelTrial($record, 'canceled_by_admin');

                $cancelled
                    ? Notification::make()->title('Trial cancelled')->body('The member will not be charged.')->success()->send()
                    : Notification::make()->title('The trial was not cancelled')->body('Stripe did not accept the cancellation, so the trial is still running. Try again or cancel it in Stripe.')->danger()->persistent()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillingTrials::route('/'),
            'view' => Pages\ViewBillingTrial::route('/{record}'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            TrialOutcomeStats::class,
        ];
    }
}
