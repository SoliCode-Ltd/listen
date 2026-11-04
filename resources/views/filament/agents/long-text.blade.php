{{-- A long text as it was saved (what an agent knows), in its own direction, scrolling inside its box. --}}
@php($text = trim((string) $getState()))

@include('filament.agents.partials.styles')

@if ($text === '')
    <p class="lsa-muted">{{ $empty ?? '—' }}</p>
@else
    <div class="lsa-pre lsa-box" dir="auto">{{ $text }}</div>
@endif
