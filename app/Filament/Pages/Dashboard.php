<?php

namespace App\Filament\Pages;

use App\Services\Analytics\BusinessStatsExporter;
use App\Services\Analytics\BusinessStatsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->schema([
                    Select::make('months')
                        ->label('Period for trends and cohorts')
                        ->options(collect(BusinessStatsService::PERIODS)
                            ->mapWithKeys(fn (int $m) => [$m => "Last {$m} months"])
                            ->all())
                        ->default(BusinessStatsService::DEFAULT_MONTHS)
                        ->selectablePlaceholder(false),
                ])
                ->columns(3),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadReport')
                ->label('Download report (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->downloadReport()),
            Action::make('refreshStats')
                ->label('Refresh numbers')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    app(BusinessStatsService::class)->forget();
                    Notification::make()->title('Statistics refreshed')->success()->send();

                    return redirect(static::getUrl());
                }),
        ];
    }

    public function downloadReport(): BinaryFileResponse
    {
        $months = BusinessStatsService::normalizeMonths($this->filters['months'] ?? null);
        $snapshot = app(BusinessStatsService::class)->snapshot($months, fresh: true);

        $path = tempnam(sys_get_temp_dir(), 'business-report-');
        app(BusinessStatsExporter::class)->toXlsx($snapshot, $path);

        $filename = sprintf('%s-business-report-%s.xlsx', str(config('app.name', 'listen'))->slug(), now()->format('Y-m-d-His'));

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
