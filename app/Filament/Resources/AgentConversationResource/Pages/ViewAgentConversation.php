<?php

namespace App\Filament\Resources\AgentConversationResource\Pages;

use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentResource;
use App\Models\AgentConversation;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** One call, read only: who it was with, the whole conversation, and what it took. */
class ViewAgentConversation extends ViewRecord
{
    protected static string $resource = AgentConversationResource::class;

    public function getTitle(): string|Htmlable
    {
        $call = $this->call();

        return 'Call with '.($call->agent?->name ?? 'a removed agent');
    }

    public function getSubheading(): string|Htmlable|null
    {
        $call = $this->call();

        return implode(' · ', array_filter([
            $call->agent?->business_name,
            AgentConversationResource::CHANNELS[$call->channel] ?? $call->channel,
            $call->started_at?->format('j M Y, H:i'),
            AgentResource::clock((int) $call->duration_seconds),
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('agent')
                ->label('The agent')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url(fn (): ?string => $this->call()->agent ? AgentResource::getUrl('view', ['record' => $this->call()->agent]) : null)
                ->visible(fn (): bool => $this->call()->agent !== null),
            Actions\Action::make('owner')
                ->label('Owner\'s account')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->url(fn (): ?string => AgentResource::ownerUrl($this->call()->user_id))
                ->visible(fn (): bool => $this->call()->user !== null),
        ];
    }

    private function call(): AgentConversation
    {
        /** @var AgentConversation */
        return $this->getRecord();
    }
}
