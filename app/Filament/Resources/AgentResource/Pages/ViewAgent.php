<?php

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AgentWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** One agent, read only: the links go to its owner and its link page, nothing changes it. */
class ViewAgent extends ViewRecord
{
    protected static string $resource = AgentResource::class;

    public function getTitle(): string|Htmlable
    {
        return (string) $this->agent()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $agent = $this->agent();

        return $agent->business_name.' · '.($agent->user?->email ?? 'owner account deleted');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('owner')
                ->label('Owner\'s account')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->url(fn (): ?string => AgentResource::ownerUrl($this->agent()->user_id))
                ->visible(fn (): bool => $this->agent()->user !== null),
            Actions\Action::make('linkPage')
                ->label('Open its link page')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => ($widget = $this->widget()) ? AgentResource::talkUrl($widget) : null, shouldOpenInNewTab: true)
                ->visible(fn (): bool => ($widget = $this->widget()) !== null && $widget->link_enabled && AgentResource::talkUrl($widget) !== null),
        ];
    }

    private function agent(): Agent
    {
        /** @var Agent */
        return $this->getRecord();
    }

    private function widget(): ?AgentWidget
    {
        return $this->agent()->widgets->sortBy('id')->first();
    }
}
