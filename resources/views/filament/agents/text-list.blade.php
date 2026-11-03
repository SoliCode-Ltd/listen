{{-- A list from an agent's script (its rules or its steps); each line keeps its own direction. --}}
@php
    $items = array_values(array_filter(
        array_map(fn ($item): string => is_scalar($item) ? trim((string) $item) : '', (array) ($getState() ?? [])),
        fn (string $item): bool => $item !== '',
    ));
    $ordered = (bool) ($ordered ?? false);
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament.agents.partials.styles')

    @if ($items === [])
        <p class="lsa-muted">{{ $empty ?? '—' }}</p>
    @else
        <div class="lsa-items lsa-box lsa-para">
            @foreach ($items as $i => $item)
                <div class="lsa-item" dir="auto">
                    <span class="lsa-marker">{{ $ordered ? ($i + 1).'.' : '•' }}</span>
                    <span class="lsa-item-text">{{ $item }}</span>
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
