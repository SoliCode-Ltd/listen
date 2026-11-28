<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionCancellationResource\Pages\ListSubscriptionCancellations;
use App\Filament\Widgets\CancellationReasonStats;
use App\Models\SubscriptionCancellation;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Billing → Cancellations: every member who cancelled their subscription, with the
 * reason they chose in the cancel dialog and anything they wrote. Read only.
 */
class SubscriptionCancellationResource extends Resource
{
    protected static ?string $model = SubscriptionCancellation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-right-start-on-rectangle';

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Cancellations';

    protected static ?string $modelLabel = 'Cancellation';

    protected static ?int $navigationSort = 6;

    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    /** Only cancellations that went through (Stripe refusing one removes it). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('cancelled_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cancelled_at')->label('Cancelled')->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('user.email')->label('Member')->searchable()->copyable(),
                TextColumn::make('plan_name')->label('Plan')->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SubscriptionCancellation::reasonLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'price' => 'warning',
                        'quality', 'technical' => 'danger',
                        'not_needed' => 'info',
                        'other' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder(SubscriptionCancellation::reasonLabel(null)),
                // Each note in its own direction: Arabic right to left, English left to right.
                TextColumn::make('note')->label('In their words')->wrap()->limit(160)->placeholder('—')->searchable()
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('ends_at')->label('Plan ends')->date('j M Y')->placeholder('—')->sortable(),
                IconColumn::make('ended_now')->label('Ended at once')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('locale')->label('Language')->badge()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('email_sent_at')->label('Email sent')->boolean()
                    ->getStateUsing(fn (SubscriptionCancellation $record): bool => $record->email_sent_at !== null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(collect(SubscriptionCancellation::REASONS)
                        ->mapWithKeys(fn (string $reason): array => [$reason => SubscriptionCancellation::reasonLabel($reason)])
                        ->all()),
                Filter::make('with_note')
                    ->label('Wrote something')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('note')),
            ])
            ->defaultSort('cancelled_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionCancellations::route('/'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            CancellationReasonStats::class,
        ];
    }
}
