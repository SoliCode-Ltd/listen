<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use App\Support\Acquisition\Channels;
use Filament\Widgets\Widget;

/**
 * Dashboard → Where members come from: the period's new members by how they found the site, what they
 * paid, the period's payments by the payer's source, and the campaigns, first pages and websites
 * (BusinessStatsService 'sources', the same numbers as the investor PDF and the Excel file).
 */
class BusinessSources extends Widget
{
    use UsesBusinessStats;

    protected static ?int $sort = -15;

    protected string $view = 'filament.widgets.business-sources';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $s = $this->stats();

        return [
            'sources' => $s['sources'] ?? [],
            'since' => $s['meta']['since'] ?? null,
            'key' => collect(Channels::HELP)->mapWithKeys(fn (string $help, string $channel) => [Channels::label($channel) => $help])->all(),
        ];
    }
}
