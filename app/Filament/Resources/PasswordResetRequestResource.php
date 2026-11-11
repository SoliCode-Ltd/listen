<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PasswordResetRequestResource\Pages;
use App\Models\PasswordResetRequest;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Newsletter → Password resets (under the paid welcome email): everyone who asked to reset a
 * password from the sign-in page, newest first, and what came of it: the password was changed,
 * the email is waiting, the link ran out, or no email went out and why. Read-only. The list
 * pages without counting every row, so it opens as fast with millions of rows as with ten.
 */
class PasswordResetRequestResource extends Resource
{
    protected static ?string $model = PasswordResetRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Password resets';

    protected static ?string $modelLabel = 'password reset';

    protected static ?string $pluralModelLabel = 'Password resets';

    protected static ?string $slug = 'password-resets';

    protected static ?int $navigationSort = 4;

    /** Until its migration is run there is no list to show (resets work all the same). */
    public static function shouldRegisterNavigation(): bool
    {
        return PasswordResetRequest::ready();
    }

    public static function canAccess(): bool
    {
        return PasswordResetRequest::ready() && parent::canAccess();
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
        $waiting = fn (Builder $query): Builder => $query->where('status', PasswordResetRequest::SENT)->whereNull('completed_at');
        $cutoff = fn () => now()->subMinutes(PasswordResetRequest::linkMinutes());

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Asked')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (PasswordResetRequest $record): string => (string) $record->created_at?->diffForHumans()),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email typed')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Member')
                    ->placeholder('No account')
                    ->url(fn (PasswordResetRequest $record): ?string => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null),
                Tables\Columns\TextColumn::make('outcome')
                    ->label('Result')
                    ->badge()
                    ->state(fn (PasswordResetRequest $record): string => $record->outcome()['label'])
                    ->color(fn (PasswordResetRequest $record): string => $record->outcome()['color']),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Password changed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('locale')
                    ->label('Email language')
                    ->formatStateUsing(fn (?string $state): string => $state === 'ar' ? 'Arabic' : 'English'),
                Tables\Columns\TextColumn::make('ip')
                    ->label('From')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('result')
                    ->label('Result')
                    ->options([
                        'changed' => 'Password changed',
                        'waiting' => 'Email sent, waiting',
                        'expired' => 'Link ran out unused',
                        'failed' => 'Email could not be sent',
                        'no_account' => 'No account with this email',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'changed' => $query->whereNotNull('completed_at'),
                        'waiting' => $waiting($query)->where('created_at', '>=', $cutoff()),
                        'expired' => $waiting($query)->where('created_at', '<', $cutoff()),
                        'failed' => $query->where('status', PasswordResetRequest::FAILED),
                        'no_account' => $query->where('status', PasswordResetRequest::NO_ACCOUNT),
                        default => $query,
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No password resets yet')
            ->emptyStateDescription('When someone uses "Forgot your password?" on the sign-in page, the request shows here with its result.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPasswordResetRequests::route('/'),
        ];
    }
}
