{{-- One conversation's turns ($conversation; $only: a stretch of them): the agent on one side, the caller on the other. Each line keeps its own direction (Arabic or English). Under each reply of a written chat: what it used. --}}
@php
    $record = $conversation;
    // All of its turns, or the stretch of them a visit shows here ($only).
    $turns = is_array($only ?? null) ? $only : array_values(array_filter((array) ($record->transcript ?? []), 'is_array'));
    $agentName = trim((string) ($record->agent?->name ?? '')) ?: 'Agent';
    $isChat = \App\Services\Agents\AgentChats::isChat($record);
    $toolNames = ['save_contact' => 'saved the customer\'s details', 'show_links' => 'showed the links', 'look_up' => 'looked it up on the website'];
@endphp

@if ($turns === [])
    <p class="lsa-muted">{{ $isChat ? 'Nothing was written in this chat: it was opened and no message was answered.' : 'Nothing was written down for this call: no one spoke, or the caller\'s browser sent nothing.' }}</p>
@else
    <ol class="lsa-chat">
        @foreach ($turns as $turn)
            @php
                $isAgent = ($turn['role'] ?? '') === 'agent';
                $isLinks = ($turn['kind'] ?? '') === 'links';
                $tokens = (array) ($turn['tokens'] ?? []);
                $tools = array_values(array_unique(array_filter((array) ($turn['tools'] ?? []), 'is_string')));
                // What a chat's reply used, as short phrases.
                $used = [];
                if ($isChat && $isAgent && isset($turn['re'])) {
                    $used[] = number_format((int) ($turn['credits'] ?? 0)).' credits';
                    if ($tokens !== []) {
                        $cached = (int) ($tokens['cached'] ?? 0);
                        $thoughts = (int) ($tokens['thoughts'] ?? 0);
                        $used[] = number_format((int) ($tokens['in'] ?? 0)).' tokens read'.($cached > 0 ? ', '.number_format($cached).' of them from the cache' : '');
                        $used[] = number_format((int) ($tokens['out'] ?? 0)).' written'.($thoughts > 0 ? ', '.number_format($thoughts).' of them thinking' : '');
                    }
                    if (isset($turn['cost'])) {
                        $used[] = 'cost us $'.number_format((float) $turn['cost'], 5);
                    }
                    if ((int) ($turn['rounds'] ?? 0) > 1) {
                        $used[] = (int) $turn['rounds'].' model calls';
                    }
                    foreach ($tools as $tool) {
                        $used[] = $toolNames[$tool] ?? $tool;
                    }
                    if ((int) ($turn['ms'] ?? 0) > 0) {
                        $used[] = number_format(((int) $turn['ms']) / 1000, 1).' s';
                    }
                }
            @endphp
            <li class="lsa-turn {{ $isAgent ? 'is-agent' : 'is-caller' }}">
                <div class="lsa-who">
                    @if ($isAgent)
                        <bdi class="lsa-who-name">{{ $agentName }}</bdi>
                        <span class="lsa-tag">{{ $isLinks ? 'links shown' : 'agent' }}</span>
                    @else
                        <span class="lsa-who-name">{{ $isChat ? 'Customer' : 'Caller' }}</span>
                    @endif
                    @if (isset($turn['at']) && is_numeric($turn['at']))
                        <span class="lsa-time">{{ \App\Filament\Resources\AgentResource::clock((int) $turn['at']) }}</span>
                    @endif
                </div>
                <div class="lsa-bubble">
                    <div class="lsa-bubble-text" dir="auto">{{ (string) ($turn['text'] ?? '') }}</div>
                </div>
                @if ($used !== [])
                    <div class="lsa-used">
                        @foreach ($used as $phrase)
                            <span>{{ $phrase }}</span>
                        @endforeach
                        @if (filled($turn['model'] ?? null))
                            <span class="lsa-mono">{{ (string) $turn['model'] }}</span>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
@endif
