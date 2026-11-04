{{-- A paragraph the owner or the agent wrote (who it is, what it says first, a summary), in its own direction, in a box under its label. --}}
@php($text = trim((string) (is_scalar($getState()) ? $getState() : '')))

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament.agents.partials.styles')

    @if ($text === '')
        <p class="lsa-muted">{{ $empty ?? '—' }}</p>
    @else
        <div class="lsa-pre lsa-box lsa-para" dir="auto">{{ $text }}</div>
    @endif
</x-dynamic-component>
