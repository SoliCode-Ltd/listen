{{-- Everything saved on a call, as stored, apart from the conversation (shown above) and the caller's secret key. --}}
@php
    $data = \Illuminate\Support\Arr::except($getRecord()->attributesToArray(), ['transcript', 'call_key_hash']);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

@include('filament.agents.partials.styles')

<pre class="lsa-code" dir="ltr">{{ $json }}</pre>
