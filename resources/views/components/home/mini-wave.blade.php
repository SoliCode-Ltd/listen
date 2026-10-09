{{-- Waveform for a card player: the bars fill in brand colour as the clip plays (--vp-progress on the track). --}}
@props(['seed' => 1, 'count' => 44])
@php
  $bars = [];
  for ($i = 0; $i < $count; $i++) {
    $envelope = 0.4 + 0.6 * abs(sin(($i / $count) * M_PI * 1.7 + $seed * 0.9));
    $detail = 0.45 + 0.55 * abs(sin($i * 1.31 + $seed * 2.3) * cos($i * 0.47 + $seed));
    $bars[] = (int) round(18 + 82 * $envelope * $detail);
  }
@endphp
<span class="vs-wave" aria-hidden="true">
  <span class="vs-wave-bars">@foreach($bars as $h)<i style="height: {{ $h }}%"></i>@endforeach</span>
  <span class="vs-wave-bars vs-wave-fill">@foreach($bars as $h)<i style="height: {{ $h }}%"></i>@endforeach</span>
</span>
