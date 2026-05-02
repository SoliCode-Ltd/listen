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
                Tables\Actions\Action::make('view_post')
                    ->label('View post')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (SocialShareLog $record): ?string => self::postSearchUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (SocialShareLog $record): bool => self::postSearchUrl($record) !== null)
                    ->tooltip('Open the platform and find any public post that includes this referral URL.'),
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

    /**
     * Build a "find the post" URL for the given share log.
     *
     * Web composers don't return the resulting post URL to us, so we open the
     * platform's content search (or a Google site:-search fallback) for the
     * exact referral URL. Anything publicly posted with that link will surface
     * there. For private channels (copy_link, whatsapp) there's no useful
     * destination, so the action hides itself.
     */
    public static function postSearchUrl(SocialShareLog $record): ?string
    {
        $link = trim((string) ($record->referral_link ?? ''));
        if ($link === '') {
            return null;
        }

        $q = rawurlencode($link);
        // For Google site: queries we want the URL inside quotes for an exact match.
        $googleSite = fn (string $domain): string =>
            'https://www.google.com/search?q='.rawurlencode('site:'.$domain.' "'.$link.'"');

        return match ($record->platform) {
            'facebook'  => 'https://www.facebook.com/search/posts/?q='.$q,
            'twitter'   => 'https://x.com/search?q='.$q.'&src=typed_query&f=live',
            'linkedin'  => 'https://www.linkedin.com/search/results/content/?keywords='.$q,
            // Telegram & Instagram don't expose URL search — fall back to Google
            // `site:` which surfaces public posts indexed by the search engine.
            'telegram'  => $googleSite('t.me'),
            'instagram' => $googleSite('instagram.com'),
            // copy_link / whatsapp = private destinations, untrackable.
            default     => null,
        };
    }
}
