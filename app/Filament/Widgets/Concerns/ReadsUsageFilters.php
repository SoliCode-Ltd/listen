<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Resources\UsageCounterResource;
use App\Filament\Resources\UsageCounterResource\Pages\ListUsageCounters;
use App\Models\UsageCounter;
use App\Services\Analytics\UsageInsights;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Monthly usage widgets follow the table's filters: the month, the plan
 * and whether admin accounts are left out.
 */
trait ReadsUsageFilters
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListUsageCounters::class;
    }

    /** "2026-10", or null when every month is shown. */
    protected function usageMonth(): ?string
    {
        if ($this->tableFilters === null) {
            return now()->format('Y-m');
        }
        $value = $this->tableFilters['period_ym']['value'] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) ? $value : null;
    }

    protected function usageMonthLabel(?string $ym = null): string
    {
        $ym ??= $this->usageMonth();

        return $ym === null ? 'every month' : Carbon::createFromFormat('!Y-m', $ym)->format('F Y');
    }

    protected function usagePlan(): ?string
    {
        $value = $this->tableFilters['plan']['value'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    protected function usageWithoutAdmins(): bool
    {
        return (bool) ($this->tableFilters['members_only']['isActive'] ?? true);
    }

    /** Usage rows of periods that started in the month, for the chosen plan. */
    protected function usageCounters(?string $ym): Builder
    {
        $query = UsageCounter::query();
        if ($ym !== null) {
            $query->where('period_ym', 'like', $ym.'%');
        }
        if ($this->usageWithoutAdmins()) {
            UsageCounterResource::withoutAdmins($query);
        }

        return UsageCounterResource::onPlan($query, $this->usagePlan());
    }

    /** Generations made in the month, for the chosen plan. */
    protected function usageGenerations(?string $ym): Builder
    {
        return UsageCounterResource::onPlan(
            UsageInsights::month($ym, $this->usageWithoutAdmins()),
            $this->usagePlan(),
            'audio_generations.user_id',
        );
    }
}
