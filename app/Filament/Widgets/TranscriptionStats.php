<?php

namespace App\Filament\Widgets;

use App\Models\Transcription;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

/**
 * Speech to text at a glance, above the list of transcripts (Speech to text →
 * Transcripts): how many, free and on plans, the minutes heard and used, what
 * it cost us a minute against the figure the plans' economics use, how long
 * members waited (short files apart), what fails and who uses it.
 */
class TranscriptionStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected ?string $heading = 'Speech to text';

    protected ?string $description = 'Last 30 days unless it says otherwise. Our cost is from the tokens each transcript used (deleted ones included).';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        try {
            return $this->stats();
        } catch (Throwable $e) {
            // The list of transcripts must open whatever goes wrong here.
            report($e);

            return [Stat::make('Figures', '—')->description('Could not be worked out: '.mb_substr($e->getMessage(), 0, 120))->color('danger')];
        }
    }

    /** @return array<int, Stat> */
    private function stats(): array
    {
        $month = Transcription::withTrashed()->where('created_at', '>=', now()->subDays(30))
            ->get(['id', 'user_id', 'status', 'source', 'is_free', 'seconds', 'charged_seconds', 'cost_usd', 'error_code', 'created_at', 'finished_at']);
        $today = $month->filter(fn (Transcription $t): bool => $t->created_at >= now()->startOfDay());
        $ready = $month->where('status', Transcription::READY);
        $failed = $month->where('status', Transcription::FAILED);
        $finished = $ready->count() + $failed->count();
        $heard = $ready->sum(fn (Transcription $t): float => (float) $t->seconds) / 60;
        $used = $ready->where('is_free', false)->sum('charged_seconds') / 60;
        $cost = (float) $month->sum('cost_usd');
        $perMinute = $heard > 0 ? $cost / $heard : null;
        $waits = $ready->map(fn (Transcription $t): ?float => $t->finished_at ? (float) $t->created_at->diffInRealSeconds($t->finished_at) : null)->filter(fn ($w) => $w !== null);
        $short = $ready->filter(fn (Transcription $t): bool => (float) $t->seconds <= 180 && $t->finished_at !== null)
            ->map(fn (Transcription $t): float => (float) $t->created_at->diffInRealSeconds($t->finished_at));
        $freeCap = TranscriptionSettings::freePerDay();
        $freeToday = $today->where('is_free', true)->where('status', '!=', Transcription::FAILED)->count();
        $byDay = $month->countBy(fn (Transcription $t): string => $t->created_at->toDateString());
        $daily = collect(range(29, 0))->map(fn (int $ago): int => (int) ($byDay[now()->subDays($ago)->toDateString()] ?? 0))->all();
        $reasons = $failed->countBy(fn (Transcription $t): string => (string) ($t->error_code ?: 'other'))->sortDesc()->take(3)
            ->map(fn (int $n, string $code): string => $code.' '.$n)->implode(' · ');
        $siteLeft = TranscriptionAccess::siteSecondsLeftToday();
        $outside = $month->whereIn('source', ['api', 'mcp'])->count();

        return [
            Stat::make('Transcripts today', number_format($today->count()))
                ->description($today->where('is_free', true)->count().' free · '.$today->where('is_free', false)->count().' on plans · '.number_format($month->count()).' in 30 days')
                ->descriptionIcon('heroicon-m-document-text')
                ->chart($daily)
                ->color('primary'),
            Stat::make('Free tries today', $freeToday.' / '.$freeCap)
                ->description('The most a day for the whole site (Settings)')
                ->descriptionIcon('heroicon-m-gift')
                ->color($freeCap > 0 && $freeToday >= $freeCap ? 'danger' : 'gray'),
            Stat::make('Audio written out', number_format($heard, 1).' min')
                ->description(number_format($used, 1).' min of plan minutes used · the rest free tries')
                ->descriptionIcon('heroicon-m-clock'),
            Stat::make('Cost to us', '$'.number_format($cost, 2))
                ->description($perMinute === null ? 'Nothing written out yet' : '$'.number_format($perMinute, 4).' a minute (the plans count $'.number_format(TranscriptionAccess::costPerMinute(), 4).')')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color($perMinute !== null && $perMinute > TranscriptionAccess::costPerMinute() * 1.25 ? 'danger' : 'gray'),
            Stat::make('Failed', $finished > 0 ? round($failed->count() / $finished * 100).'%' : '—')
                ->description($failed->count().' of '.$finished.' finished, minutes given back'.($reasons !== '' ? ' · '.$reasons : ''))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($finished > 0 && $failed->count() / $finished > 0.2 ? 'danger' : 'gray'),
            Stat::make('Average wait', $waits->isEmpty() ? '—' : number_format($waits->avg(), 0).' s')
                ->description($short->isEmpty() ? 'From sending to ready' : 'Files up to 3 min: '.number_format($short->avg(), 0).' s on average, longest '.number_format($short->max(), 0).' s')
                ->descriptionIcon('heroicon-m-bolt'),
            Stat::make('Members using it', number_format($month->pluck('user_id')->unique()->count()))
                ->description($outside.' transcripts through the API or MCP')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),
            Stat::make('Site minutes left today', $siteLeft === null ? 'No ceiling' : number_format($siteLeft / 60, 0).' min')
                ->description($siteLeft === null ? 'Settings → Minutes a day: 0' : 'of '.number_format(TranscriptionSettings::dailyMinutes()).' a day (Settings)')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($siteLeft !== null && $siteLeft < 600 ? 'danger' : 'gray'),
        ];
    }
}
