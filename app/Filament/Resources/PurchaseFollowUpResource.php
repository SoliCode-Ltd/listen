<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseFollowUpResource\Pages;
use App\Models\PurchaseFollowUp;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Newsletter → Purchase follow-ups: every member who nearly bought a plan (their first
 * payment was declined, or they opened the checkout and left), newest first, with the
 * follow-up email each got (or why none went) and whether they bought a plan afterwards.
 * Read-only; the emails' words and switches are on its "Emails and settings" page.
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
                    ->color(fn (PurchaseFollowUp $record): string => $record->kind === PurchaseFollowUp::KIND_PAYMENT_FAILED ? 'danger' : 'warning')
                    ->description(fn (PurchaseFollowUp $record): ?string => $record->kind === PurchaseFollowUp::KIND_PAYMENT_FAILED
                        ? $record->reasonLabel()
                        : ((int) $record->opens > 1 ? 'Opened '.$record->opens.' times' : null)),
                Tables\Columns\TextColumn::make('plan_slug')
                    ->label('Plan')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => ucwords(str_replace('_', ' ', (string) $state)))
                    ->description(fn (PurchaseFollowUp $record): string => $record->billing_cycle === 'yearly' ? 'Yearly' : 'Monthly'),
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
                    ->label('Bought a plan after')
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
                        PurchaseFollowUp::KIND_PAYMENT_FAILED => 'Payment declined',
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
                    ->label('Bought a plan after')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('paid_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('paid_at'),
                    ),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No purchase follow-ups yet')
            ->emptyStateDescription('When a first payment is declined, or a member opens the checkout and leaves without paying, it shows here with its email and whether they bought a plan afterwards.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseFollowUps::route('/'),
            'emails' => Pages\PurchaseFollowUpEmails::route('/emails'),
        ];
    }
}
