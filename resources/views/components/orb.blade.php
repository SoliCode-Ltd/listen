{{-- A voice orb (public/js/listen-orbs.js draws it; this CSS sphere shows until then or without it). --}}
@props(['name' => 'listen', 'size' => null])
@php
  $orbColors = \App\Support\Orbs::colors($name);
  $orbStyle = '--o0: '.$orbColors[0].'; --o1: '.$orbColors[1].'; --o2: '.$orbColors[2].'; --o3: '.$orbColors[3].';'.($size ? ' --orb-size: '.$size.';' : '');
@endphp
<span {{ $attributes->merge(['class' => 'orb']) }} data-orb="{{ $name }}" data-orb-colors="{{ implode(',', $orbColors) }}" style="{{ $orbStyle }}" aria-hidden="true"><canvas></canvas></span>
