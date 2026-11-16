<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialShareLogResource\Pages;
use App\Filament\Resources\SocialShareLogResource\Pages\ListSocialShareLogs;
use App\Models\SocialShareLog;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;

class SocialShareLogResource extends Resource
{
    protected static ?string $model = SocialShareLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static string|\UnitEnum|null $navigationGroup = 'Referrals';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Share Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->user?->email),
                BadgeColumn::make('platform')
                    ->colors([
                        'primary' => ['facebook', 'twitter', 'linkedin'],
                        'success' => ['whatsapp', 'telegram'],
                        'warning' => ['instagram'],
                        'gray' => ['copy_link'],
                    ])
                    ->searchable(),
                TextColumn::make('locale')
                    ->badge(),
                TextColumn::make('referral_code')
                    ->searchable()
                    ->copyable()
                    ->limit(16),
                TextColumn::make('visitor_ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->options(array_combine(SocialShareLog::PLATFORMS, SocialShareLog::PLATFORMS))
                    ->multiple(),
                SelectFilter::make('locale')
                    ->options(['en' => 'English', 'ar' => 'Arabic']),
            ])
            ->recordActions([
                Action::make('view_post')
                    ->label('View post')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (SocialShareLog $record): ?string => self::postSearchUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (SocialShareLog $record): bool => self::postSearchUrl($record) !== null)
                    ->tooltip('Open the platform and find any public post that includes this referral URL.'),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialShareLogs::route('/'),
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
        $googleSite = fn (string $domain): string => 'https://www.google.com/search?q='.rawurlencode('site:'.$domain.' "'.$link.'"');

        return match ($record->platform) {
            'facebook' => 'https://www.facebook.com/search/posts/?q='.$q,
            'twitter' => 'https://x.com/search?q='.$q.'&src=typed_query&f=live',
            'linkedin' => 'https://www.linkedin.com/search/results/content/?keywords='.$q,
            // Telegram & Instagram don't expose URL search — fall back to Google
            // `site:` which surfaces public posts indexed by the search engine.
            'telegram' => $googleSite('t.me'),
            'instagram' => $googleSite('instagram.com'),
            // copy_link / whatsapp = private destinations, untrackable.
            default => null,
        };
    }
}
