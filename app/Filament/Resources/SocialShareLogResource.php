<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialShareLogResource\Pages;
use App\Models\SocialShareLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SocialShareLogResource extends Resource
{
    protected static ?string $model = SocialShareLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationGroup = 'Referrals';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Share Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->user?->email),
                Tables\Columns\BadgeColumn::make('platform')
                    ->colors([
                        'primary' => ['facebook', 'twitter', 'linkedin'],
                        'success' => ['whatsapp', 'telegram'],
                        'warning' => ['instagram'],
                        'gray' => ['copy_link'],
                    ])
                    ->searchable(),
                Tables\Columns\TextColumn::make('locale')
                    ->badge(),
                Tables\Columns\TextColumn::make('referral_code')
                    ->searchable()
                    ->copyable()
                    ->limit(16),
                Tables\Columns\TextColumn::make('visitor_ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->options(array_combine(SocialShareLog::PLATFORMS, SocialShareLog::PLATFORMS))
                    ->multiple(),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(['en' => 'English', 'ar' => 'Arabic']),
            ])
            ->actions([
                Tables\Actions\Action::make('open_referral_link')
                    ->label('Open link')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (SocialShareLog $record): ?string => $record->referral_link ?: null)
                    ->openUrlInNewTab()
                    ->visible(fn (SocialShareLog $record): bool => ! empty($record->referral_link))
                    ->tooltip('Open the referral link this user shared.'),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialShareLogs::route('/'),
        ];
    }

}
