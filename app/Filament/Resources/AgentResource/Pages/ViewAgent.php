<?php

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AgentWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One agent, read only, with the team's one switch on it: suspend it (it takes no calls, and its
 * owner sees it was suspended and why) and lift the suspension. The links go to its owner and its
 * link page.
 */
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
            Actions\Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Suspend this agent?')
                ->modalDescription('It stops taking calls at once (callers hear it is not available) and its owner cannot put it live again. They see that Listen suspended it, with the reason below. Nothing is deleted; lift the suspension at any time.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason the owner sees')
                        ->placeholder('For example: it asks callers for their card numbers.')
                        ->maxLength(300)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $agent = $this->agent();
                    $settings = (array) ($agent->settings ?? []);
                    $settings['suspended'] = ['reason' => trim((string) $data['reason']), 'at' => now()->toIso8601String(), 'by' => auth()->id()];
                    $agent->forceFill(['settings' => $settings, 'status' => $agent->isLive() ? Agent::PAUSED : $agent->status])->save();
                    \Filament\Notifications\Notification::make()->title('Agent suspended')->success()->send();
                })
                ->visible(fn (): bool => ! $this->agent()->setting('suspended') && ! $this->agent()->setting('house')),
            Actions\Action::make('unsuspend')
                ->label('Lift the suspension')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Its owner can put it live again (it stays paused until they do).')
                ->action(function (): void {
                    $agent = $this->agent();
                    $settings = (array) ($agent->settings ?? []);
                    unset($settings['suspended']);
                    $agent->forceFill(['settings' => $settings])->save();
                    \Filament\Notifications\Notification::make()->title('Suspension lifted')->success()->send();
                })
                ->visible(fn (): bool => (bool) $this->agent()->setting('suspended')),
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
