{{-- Saved settings as name and value; with $defaults, the values the owner did not change are marked "default". --}}
@php
    $stored = $getState();
    $stored = is_array($stored) ? $stored : [];
    $defaults = is_array($defaults ?? null) ? $defaults : [];
    $rows = [];
    foreach (array_unique(array_merge(array_keys($defaults), array_keys($stored))) as $key) {
        $isSet = array_key_exists($key, $stored) && $stored[$key] !== null;
        $rows[] = [
            'key' => (string) $key,
            'value' => $isSet ? $stored[$key] : ($defaults[$key] ?? null),
            'default' => ! $isSet && array_key_exists($key, $defaults),
        ];
    }
    $label = fn (string $key): string => \Illuminate\Support\Str::ucfirst(str_replace(['_', '.'], ' ', \Illuminate\Support\Str::snake($key)));
    $text = function ($value): string {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) && array_is_list($value) && count(array_filter($value, 'is_scalar')) === count($value) => implode(', ', array_map('strval', $value)) ?: '—',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    };
    $isColour = fn ($value): bool => is_string($value) && preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1;
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @include('filament.agents.partials.styles')

    @if ($rows === [])
        <p class="lsa-muted">{{ $empty ?? '—' }}</p>
    @else
        <dl class="lsa-kv">
            @foreach ($rows as $row)
                <div>
                    <dt>{{ $label($row['key']) }}</dt>
                    <dd>
                        @if ($isColour($row['value']))
                            <span class="lsa-swatch" style="background: {{ $row['value'] }}"></span>
                        @endif
                        <span dir="auto">{{ $text($row['value']) }}</span>
                        @if ($row['default'])
                            <span class="lsa-default">default</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</x-dynamic-component>
