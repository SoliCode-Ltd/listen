<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseFollowUpResource\Pages;
use App\Models\PurchaseFollowUp;
use App\Services\Billing\PurchaseFollowUps;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Newsletter → Purchase follow-ups: every payment that did not happen, newest first: a
 * member who nearly bought a plan (their first payment was declined, or they opened the
 * checkout and left) and a paying member whose renewal was declined. Each with the email
 * it got (or why none went) and whether the member paid afterwards.
 * The cases are not edited; the emails' words and switches are on its "Emails and settings"
 * page. Two things can be done to a case by hand: send its email now, and take a
 * percentage off a declined renewal (its email is then the offer's, with the lower price).
 */
class PurchaseFollowUpResource extends Resource
{
    protected static ?string $model = PurchaseFollowUp::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Purchase follow-ups';

    protected static ?string $modelLabel = 'purchase follow-up';

    protected static ?string $pluralModelLabel = 'Purchase follow-ups';

    protected static ?string $slug = 'purchase-follow-ups';

    protected static ?int $navigationSort = 7;

    /** Until its migration is run there is nothing to list (and nothing is recorded or sent). */
    public static function shouldRegisterNavigation(): bool
    {
        return PurchaseFollowUp::ready();
    }

    public static function canAccess(): bool
    {
        return PurchaseFollowUp::ready() && parent::canAccess();
    }

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->columns([
                Tables\Columns\TextColumn::make('happened_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (PurchaseFollowUp $record): string => (string) $record->happened_at?->diffForHumans()),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Member')
                    ->placeholder('Account deleted')
                    ->description(fn (PurchaseFollowUp $record): ?string => $record->user?->name)
                    ->searchable()
                    ->copyable()
                    ->url(fn (PurchaseFollowUp $record): ?string => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null),
                Tables\Columns\TextColumn::make('kind')
                    ->label('What happened')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseFollowUp $record): string => $record->what())
                    ->color(fn (PurchaseFollowUp $record): string => $record->isDeclinedPayment() ? 'danger' : 'warning')
                    ->description(fn (PurchaseFollowUp $record): ?string => $record->isDeclinedPayment()
                        ? collect([
                            $record->reasonLabel(),
                            ($until = $record->planKeptUntil()) !== null ? 'Plan stays on until '.$until->format('j M') : null,
                        ])->filter()->implode(' · ') ?: null
                        : ((int) $record->opens > 1 ? 'Opened '.$record->opens.' times' : null)),
                Tables\Columns\TextColumn::make('plan_slug')
                    ->label('Plan')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => ucwords(str_replace('_', ' ', (string) $state)))
                    ->description(fn (PurchaseFollowUp $record): string => collect([
                        $record->billing_cycle === 'yearly' ? 'Yearly' : 'Monthly',
                        $record->hasDiscount()
                            ? $record->discount_percent.'% off: '.PurchaseFollowUps::money($record->priceCents(), $record->currency).' instead of '.PurchaseFollowUps::money($record->amount_cents, $record->currency)
                                .((int) $record->discount_months > 0 ? ', and off the next '.$record->discount_months.' '.((int) $record->discount_months === 1 ? 'renewal' : 'renewals') : ', this renewal only')
                            : ($record->isDeclinedPayment() && $record->amount_cents ? PurchaseFollowUps::money($record->amount_cents, $record->currency) : null),
                    ])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('email_status')
                    ->label('Email')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseFollowUp $record): string => $record->emailState()['label'])
                    ->color(fn (PurchaseFollowUp $record): string => $record->emailState()['color'])
                    ->description(fn (PurchaseFollowUp $record): ?string => match ($record->email_status) {
                        PurchaseFollowUp::SENT => $record->email_sent_at?->format('j M, H:i'),
                        PurchaseFollowUp::WAITING => $record->email_due_at ? 'Due '.$record->email_due_at->format('j M, H:i') : null,
                        default => $record->email_note,
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Paid after')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Not yet')
                    ->description(fn (PurchaseFollowUp $record): ?string => $record->paid_at ? ucwords(str_replace('_', ' ', (string) $record->paid_plan_slug)) : null)
                    ->color(fn (PurchaseFollowUp $record): ?string => $record->paid_at ? 'success' : null),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('What happened')
                    ->options([
                        PurchaseFollowUp::KIND_RENEWAL_FAILED => 'Renewal declined',
                        PurchaseFollowUp::KIND_PAYMENT_FAILED => 'First payment declined',
                        PurchaseFollowUp::KIND_CHECKOUT_LEFT => 'Left the checkout',
                    ]),
                Tables\Filters\SelectFilter::make('email_status')
                    ->label('Email')
                    ->options([
                        PurchaseFollowUp::SENT => 'Sent',
                        PurchaseFollowUp::WAITING => 'Waiting to send',
                        PurchaseFollowUp::SKIPPED => 'Not sent',
                        PurchaseFollowUp::OFF => 'Emails were off',
                        PurchaseFollowUp::FAILED => 'Could not be sent',
                    ]),
                Tables\Filters\TernaryFilter::make('paid')
                    ->label('Paid after')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('paid_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('paid_at'),
                    ),
            ])
            ->recordActions([
                Action::make('discount')
                    ->label('Give a discount')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('warning')
                    ->visible(fn (PurchaseFollowUp $record): bool => $record->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED
                        && $record->user !== null
                        && $record->paid_at === null
                        && ! $record->hasDiscount()
                        && filled($record->stripe_invoice_id)
                        && PurchaseFollowUp::discountsReady())
                    ->modalHeading(fn (PurchaseFollowUp $record): string => 'Give '.($record->user?->email ?? 'this member').' a discount on this renewal')
                    ->modalDescription(fn (PurchaseFollowUp $record): string => 'For this member only. Takes a percentage off what they owe for this renewal'
                        .($record->amount_cents ? ' ('.PurchaseFollowUps::money($record->amount_cents, $record->currency).')' : '')
                        .': the payment itself is lowered, so its payment page shows the lower price, and when their card is tried again it is for the lower price. You can also take the same percentage off their next renewals. It cannot be taken back from here.')
                    ->modalSubmitActionLabel('Give the discount')
                    ->schema([
                        TextInput::make('percent')
                            ->label('Discount')
                            ->suffix('%')
                            ->numeric()->integer()->minValue(1)->maxValue(PurchaseFollowUps::MAX_DISCOUNT)
                            ->default(20)
                            ->required(),
                        Radio::make('scope')
                            ->label('It covers')
                            ->options([
                                'once' => 'This renewal only',
                                'months' => 'This renewal and their next renewals',
                            ])
                            ->descriptions([
                                'once' => 'Every renewal after this one is at the full price.',
                                'months' => 'The same percentage comes off the number of renewals you set, then the full price returns by itself. For a plan that renews every month.',
                            ])
                            ->default('once')
                            ->live()
                            ->required(),
                        TextInput::make('months')
                            ->label('How many renewals after this one')
                            ->numeric()->integer()->minValue(1)->maxValue(PurchaseFollowUps::MAX_DISCOUNT_MONTHS)
                            ->default(3)
                            ->visible(fn (Get $get): bool => $get('scope') === 'months')
                            ->required(fn (Get $get): bool => $get('scope') === 'months'),
                        Toggle::make('email')
                            ->label('Email them the offer now')
                            ->helperText('The email with the offer\'s words and the lower price (Emails and settings → Declined renewal with a discount). Off: send it later with "Send the email now".')
                            ->default(true),
                    ])
                    ->action(function (PurchaseFollowUp $record, array $data): void {
                        $followUps = app(PurchaseFollowUps::class);
                        try {
                            $followUps->giveDiscount($record, (int) $data['percent'], ($data['scope'] ?? 'once') === 'months' ? (int) ($data['months'] ?? 0) : 0);
                        } catch (Throwable $e) {
                            Notification::make()->title('No discount was given')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }
                        $given = $record->discount_percent.'% off: they now owe '.PurchaseFollowUps::money($record->priceCents(), $record->currency)
                            .((int) $record->discount_months > 0 ? ', and it also comes off their next '.$record->discount_months.' '.((int) $record->discount_months === 1 ? 'renewal' : 'renewals').'.' : ' for this renewal; later ones are at the full price.');
                        if (! ($data['email'] ?? false)) {
                            Notification::make()->title('Discount given')->body($given.' No email was sent.')->success()->send();

                            return;
                        }
                        try {
                            $followUps->sendNow($record);
                        } catch (Throwable $e) {
                            Notification::make()->title('Discount given, but the email was not sent')->body($given.' '.$e->getMessage())->warning()->persistent()->send();

                            return;
                        }
                        Notification::make()->title('Discount given and emailed')->body($given.' The offer went to '.$record->user?->email.'.')->success()->send();
                    }),
                Action::make('emailNow')
                    ->label('Send the email now')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->visible(fn (PurchaseFollowUp $record): bool => $record->user !== null && $record->paid_at === null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (PurchaseFollowUp $record): string => 'Send this email to '.($record->user?->email ?? 'this member').' now?')
                    ->modalDescription(fn (PurchaseFollowUp $record): string => match (true) {
                        $record->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED && $record->hasDiscount() => 'The email with the offer: '.$record->discount_percent.'% off, '.PurchaseFollowUps::money($record->priceCents(), $record->currency).' to pay'
                            .((int) $record->discount_months > 0 ? ', and the same off their next '.$record->discount_months.' '.((int) $record->discount_months === 1 ? 'renewal' : 'renewals') : '').'.',
                        $record->kind === PurchaseFollowUp::KIND_RENEWAL_FAILED => 'The warning that their renewal was declined, with the button to pay it. No discount has been given on it.',
                        $record->kind === PurchaseFollowUp::KIND_PAYMENT_FAILED => 'The email for a declined first payment, with the button to pay it.',
                        default => 'The reminder to finish the checkout they left.',
                    }.($record->email_sent_at ? ' They were last emailed on '.$record->email_sent_at->format('j M, H:i').'.' : '').' Its words are in Emails and settings.')
                    ->modalSubmitActionLabel('Send it')
                    ->action(function (PurchaseFollowUp $record): void {
                        try {
                            app(PurchaseFollowUps::class)->sendNow($record);
                        } catch (Throwable $e) {
                            Notification::make()->title('The email was not sent')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }
                        Notification::make()->title('Email sent to '.$record->user?->email)->success()->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No purchase follow-ups yet')
            ->emptyStateDescription('When a renewal or a first payment is declined, or a member opens the checkout and leaves without paying, it shows here with its email and whether they paid afterwards.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseFollowUps::route('/'),
            'emails' => Pages\PurchaseFollowUpEmails::route('/emails'),
        ];
    }
}
