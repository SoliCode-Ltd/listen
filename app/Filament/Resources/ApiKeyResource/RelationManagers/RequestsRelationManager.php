<?php

namespace App\Filament\Resources\ApiKeyResource\RelationManagers;

use App\Filament\Resources\ApiRequestLogResource;
use App\Models\ApiRequestLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every request made with this key (kept 90 days), newest first, with the same columns and
 * filters as API requests; each opens on its own page with everything kept about it. Pages
 * without counting every row, so a key with millions of requests opens as fast as a new one.
 */
class RequestsRelationManager extends RelationManager
{
    use \App\Filament\Concerns\SimplePages;

    protected static string $relationship = 'requestLogs';

    protected static ?string $title = 'Requests';

    protected static ?string $icon = 'heroicon-o-signal';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        // This period's count (kept with the key), not a count over the whole log.
        $n = \App\Support\Api\ApiKeyStatus::of($ownerRecord)['requests'];

        return $n > 0 ? number_format($n).' this period' : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('request_id')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user:id,email', 'apiKey:id,name,last_four']))
            ->columns(ApiRequestLogResource::tableColumns(withKey: false))
            ->filters(ApiRequestLogResource::tableFilters())
            ->recordUrl(fn (ApiRequestLog $record): string => ApiRequestLogResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No requests yet')
            ->emptyStateDescription('Requests made with this key in the last 90 days show here.');
    }
}
