{{-- A call's conversation, turn by turn: the agent on one side, the caller on the other. Each line keeps its own direction (Arabic or English). --}}
@php
    $record = $getRecord();
    $turns = array_values(array_filter((array) ($record->transcript ?? []), 'is_array'));
    $agentName = trim((string) ($record->agent?->name ?? '')) ?: 'Agent';
@endphp

@include('filament.agents.partials.styles')

@if ($turns === [])
    <p class="lsa-muted">Nothing was written down for this call: no one spoke, or the caller's browser sent nothing.</p>
@else
    <ol class="lsa-chat">
        @foreach ($turns as $turn)
            @php($isAgent = ($turn['role'] ?? '') === 'agent')
            <li class="lsa-turn {{ $isAgent ? 'is-agent' : 'is-caller' }}">
                <div class="lsa-who">
                    @if ($isAgent)
                        <bdi class="lsa-who-name">{{ $agentName }}</bdi>
                        <span class="lsa-tag">agent</span>
                    @else
                        <span class="lsa-who-name">Caller</span>
                    @endif
                    @if (isset($turn['at']) && is_numeric($turn['at']))
                        <span class="lsa-time">{{ \App\Filament\Resources\AgentResource::clock((int) $turn['at']) }}</span>
                    @endif
                </div>
                <div class="lsa-bubble">
                    <div class="lsa-bubble-text" dir="auto">{{ (string) ($turn['text'] ?? '') }}</div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
