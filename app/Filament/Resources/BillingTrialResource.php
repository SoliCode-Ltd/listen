<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingTrialResource\Pages;
use App\Filament\Resources\BillingTrialResource\Pages\ListBillingTrials;
use App\Filament\Resources\BillingTrialResource\Pages\ViewBillingTrial;
use App\Filament\Widgets\TrialOutcomeStats;
use App\Models\BillingTrial;
use App\Services\Billing\TrialSubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Model;

class BillingTrialResource extends Resource
{
    protected static ?string $model = BillingTrial::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Trials';

    protected static ?string $modelLabel = 'Trial';

    protected static ?int $navigationSort = 5;

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canEdit() alike): never allowed here.
    public static function getEditAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canDelete() alike): never allowed here.
    public static function getDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Outcome')
                ->columns(3)
                ->schema([
                    TextInput::make('status')->disabled(),
                    Toggle::make('is_admin_test')->disabled(),
                    TextInput::make('blocked_reason')->disabled(),
                    TextInput::make('billing_cycle')->label('Billing')->disabled(),
                    TextInput::make('amount_cents')->label('Price (cents)')->disabled(),
                    TextInput::make('amount_paid_cents')->label('Paid (cents)')->disabled(),
                    TextInput::make('currency')->disabled(),
                    DateTimePicker::make('started_at')->disabled(),
                    DateTimePicker::make('trial_ends_at')->disabled(),
                    DateTimePicker::make('paid_at')->disabled(),
                    DateTimePicker::make('canceled_at')->disabled(),
                    DateTimePicker::make('charge_failed_at')->disabled(),
                ]),
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('user_id')->disabled(),
                    TextInput::make('email_normalized')->disabled(),
                    TextInput::make('phone_normalized')->disabled(),
                    TextInput::make('device_id')->disabled(),
                    TextInput::make('registration_ip')->disabled(),
                ]),
            Section::make('Card')
                ->columns(3)
                ->schema([
                    TextInput::make('card_brand')->disabled(),
                    TextInput::make('card_last4')->disabled(),
                    TextInput::make('card_fingerprint')->disabled(),
                    TextInput::make('cvc_check')->disabled(),
                    Toggle::make('off_session_ready')->disabled(),
                    TextInput::make('stripe_customer_id')->disabled(),
                    TextInput::make('stripe_subscription_id')->disabled(),
                    TextInput::make('stripe_invoice_id')->disabled(),
                    TextInput::make('setup_intent_id')->disabled(),
                ]),
            Section::make('Starter cap used during the trial')
                ->columns(3)
                ->schema([
                    TextInput::make('chars_used')->disabled(),
                    TextInput::make('chars_limit')->disabled(),
                    TextInput::make('minutes_used')->disabled()
                        ->helperText(fn (?BillingTrial $record): ?string => $record ? number_format((int) $record->seconds_used).' seconds of audio' : null),
                    TextInput::make('minutes_limit')->disabled(),
                    TextInput::make('requests_used')->disabled(),
                    TextInput::make('max_chars_per_request')->disabled(),
                    TextInput::make('premium_minutes_limit')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('user.email')->label('User')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        BillingTrial::STATUS_PAID => 'success',
                        BillingTrial::STATUS_TRIALING => 'info',
                        BillingTrial::STATUS_CANCELED => 'warning',
                        BillingTrial::STATUS_CHARGE_FAILED, BillingTrial::STATUS_BLOCKED => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_admin_test')->boolean()->label('Admin test'),
                TextColumn::make('card_brand')->label('Card'),
                TextColumn::make('card_last4')->label('Last 4'),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('billing_cycle')->label('Billing')->badge(),
                TextColumn::make('amount_cents')->label('Price')->formatStateUsing(fn ($state) => '$'.number_format(((int) $state) / 100, 2)),
                TextColumn::make('amount_paid_cents')->label('Paid')->formatStateUsing(fn ($state) => $state === null ? '-' : '$'.number_format(((int) $state) / 100, 2)),
                TextColumn::make('chars_used')->label('Chars')->numeric(),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('trial_ends_at')->dateTime()->label('Ends'),
                TextColumn::make('paid_at')->dateTime()->toggleable(),
                TextColumn::make('canceled_at')->dateTime()->toggleable(),
                TextColumn::make('blocked_reason')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    BillingTrial::STATUS_TRIALING => 'In trial',
                    BillingTrial::STATUS_PAID => 'Paid',
                    BillingTrial::STATUS_CANCELED => 'Canceled',
                    BillingTrial::STATUS_CHARGE_FAILED => 'Charge failed',
                    BillingTrial::STATUS_BLOCKED => 'Blocked',
                ]),
                TernaryFilter::make('is_admin_test')->label('Admin test'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::configureCancelAction(Action::make('cancelTrial')),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * "Cancel trial": the member is not charged when the trial ends and keeps
     * the trial plan until then, as if they had cancelled it themselves.
     * Shared by the list row and the trial page.
     *
     * @template T of Action
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
            'index' => ListBillingTrials::route('/'),
            'view' => ViewBillingTrial::route('/{record}'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            TrialOutcomeStats::class,
        ];
    }
}
