<?php

namespace App\Filament\Resources\UsageCounterResource\Pages;

use App\Filament\Resources\UsageCounterResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\MonthlyUsageBreakdown;
use App\Filament\Widgets\MonthlyUsageChart;
use App\Filament\Widgets\MonthlyUsageStats;
use App\Models\AudioGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\UsageInsights;
use Filament\Actions;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ListUsageCounters extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = UsageCounterResource::class;

    public function getSubheading(): ?string
    {
        return 'One row per member per month: what they generated in the dashboard and the browser extension from their monthly allowance. '
            .'Demo generations and premium pack characters are not counted in the rows. '
            .'The numbers and charts count every generation made in the month and plan picked in the filters, pack characters included. '
            .'Click a member to open their account, or Details for the month.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('idlePaying')
                ->label('Paying but idle')
                ->icon('heroicon-o-moon')
                ->color('gray')
                ->modalHeading(fn (): string => 'Paying members who made nothing in '.$this->monthLabel())
                ->modalDescription('On a paid plan now and no generation in the month: worth a nudge before they cancel. Admin accounts are left out.')
                ->modalContent(fn (): HtmlString => $this->idlePayingList())
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyUsageStats::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            MonthlyUsageChart::class,
            MonthlyUsageBreakdown::class,
        ];
    }

    private function month(): string
    {
        $value = $this->tableFilters['period_ym']['value'] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) ? $value : now()->format('Y-m');
    }

    private function monthLabel(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->month())->format('F Y');
    }

    private function idlePayingList(): HtmlString
    {
        $plan = $this->tableFilters['plan']['value'] ?? null;
        $idle = UsageCounterResource::onPlan(User::query(), filled($plan) ? (string) $plan : null, 'id')
            ->whereIn('id', UsageCounterResource::payingMemberIds())
            ->where('is_admin', false)
            ->whereNotIn('id', UsageInsights::month($this->month())->select('audio_generations.user_id'))
            ->orderBy('email')
            ->limit(200)
            ->get(['id', 'name', 'email']);

        if ($idle->isEmpty()) {
            return new HtmlString('<p style="padding:0.5rem 0">Every paying member made something this month.</p>');
        }

        $plans = Subscription::query()->whereIn('user_id', $idle->pluck('id'))->where('status', 'active')
            ->with('plan:id,name')->latest('id')->get()->unique('user_id')->mapWithKeys(fn (Subscription $s): array => [$s->user_id => $s->plan?->name]);
        $last = AudioGeneration::query()->whereIn('user_id', $idle->pluck('id'))
            ->selectRaw('user_id, max(created_at) as last_made')->groupBy('user_id')->pluck('last_made', 'user_id');

        $rows = $idle->map(fn (User $user): string => '<tr style="border-top:1px solid rgba(var(--gray-400),0.2)">'
            .'<td style="padding:0.45rem 0.5rem"><a href="'.e(UserResource::getUrl('edit', ['record' => $user])).'" style="text-decoration:underline">'.e($user->email).'</a>'
            .($user->name ? '<br><span style="opacity:.65">'.e($user->name).'</span>' : '').'</td>'
            .'<td style="padding:0.45rem 0.5rem">'.e($plans[$user->id] ?? '—').'</td>'
            .'<td style="padding:0.45rem 0.5rem;white-space:nowrap">'.e(isset($last[$user->id]) ? Carbon::parse($last[$user->id])->format('j M Y') : 'Never').'</td>'
            .'</tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto"><table style="width:100%;font-size:0.875rem;text-align:start">'
            .'<thead><tr style="opacity:.65"><th style="padding:0.45rem 0.5rem;text-align:start;font-weight:500">Member</th>'
            .'<th style="padding:0.45rem 0.5rem;text-align:start;font-weight:500">Plan</th>'
            .'<th style="padding:0.45rem 0.5rem;text-align:start;font-weight:500">Last generation</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div>'
            .($idle->count() === 200 ? '<p style="margin-top:0.5rem;opacity:.65">Showing the first 200.</p>' : ''));
    }
}
