{{-- A call's or a written chat's conversation, turn by turn. A visit of several conversations (a chat, a call made from it, the chat again) is read whole in the order things happened: each stretch under a line saying what it was (AgentVisits). --}}
@php
    $record = $getRecord();
    $parts = \App\Filament\Resources\AgentConversationResource::visit($record);
    $R = \App\Filament\Resources\AgentConversationResource::class;
    // Every turn of the visit at the moment it happened (its conversation's start, plus its own
    // time into it), then cut into stretches of one conversation: a chat that went on after a
    // call comes back after it.
    $blocks = [];
    if ($parts->count() > 1) {
        $flat = [];
        foreach ($parts->values() as $pi => $part) {
            $turns = array_values(array_filter((array) ($part->transcript ?? []), 'is_array'));
            $from = $part->started_at?->getTimestamp() ?? 0;
            if ($turns === []) {
                $flat[] = ['p' => $pi, 't' => -1, 'at' => $from];
            }
            foreach ($turns as $ti => $turn) {
                $flat[] = ['p' => $pi, 't' => $ti, 'at' => $from + (is_numeric($turn['at'] ?? null) ? (int) $turn['at'] : 0), 'turn' => $turn];
            }
        }
        usort($flat, fn (array $a, array $b): int => [$a['at'], $a['p'], $a['t']] <=> [$b['at'], $b['p'], $b['t']]);
        $seen = [];
        foreach ($flat as $item) {
            $last = array_key_last($blocks);
            if ($last === null || $blocks[$last]['p'] !== $item['p']) {
                $blocks[] = ['p' => $item['p'], 'part' => $parts->values()[$item['p']], 'turns' => [], 'again' => isset($seen[$item['p']]), 'at' => $item['at']];
                $seen[$item['p']] = true;
                $last = array_key_last($blocks);
            }
            if (isset($item['turn'])) {
                $blocks[$last]['turns'][] = $item['turn'];
            }
        }
    }
@endphp

@include('filament.agents.partials.styles')

@if ($blocks !== [])
    @foreach ($blocks as $block)
        @php($part = $block['part'])
        <div class="lsa-part">
            <div class="lsa-part-head">
                <span class="lsa-part-name">{{ $R::partName($part) }}{{ $block['again'] ? ', continued' : '' }}</span>
                <span>{{ \Illuminate\Support\Carbon::createFromTimestamp($block['at'], config('app.timezone'))->format('j M, H:i') }}</span>
                @if (! $block['again'])
                    <span>{{ $R::isChat($part) ? \App\Filament\Resources\AgentResource::plural($R::replies($part), 'reply', 'replies') : \App\Filament\Resources\AgentResource::clock((int) $part->duration_seconds) }}</span>
                @endif
                @if ($part->is($record))
                    <span class="lsa-tag">this page</span>
                @else
                    <a class="lsa-link" href="{{ $R::getUrl('view', ['record' => $part]) }}">Open its page</a>
                @endif
            </div>
            @include('filament.agents.partials.turns', ['conversation' => $part, 'only' => $block['turns']])
        </div>
    @endforeach
@else
    @include('filament.agents.partials.turns', ['conversation' => $record, 'only' => null])
@endif
