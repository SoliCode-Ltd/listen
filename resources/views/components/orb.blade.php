{{-- A voice orb (public/js/listen-orbs.js draws it; this CSS sphere shows until then or without it). --}}
@props(['name' => 'listen', 'size' => null, 'colors' => null])
@php
  // Its own four colours when given (an agent's, from the Design tab), else the named palette.
  $orbColors = is_array($colors) && count($colors) === 4 ? array_values($colors) : \App\Support\Orbs::colors($name);
  $orbStyle = '--o0: '.$orbColors[0].'; --o1: '.$orbColors[1].'; --o2: '.$orbColors[2].'; --o3: '.$orbColors[3].';'.($size ? ' --orb-size: '.$size.';' : '');
@endphp
<span {{ $attributes->merge(['class' => 'orb']) }} data-orb="{{ $name }}" data-orb-colors="{{ implode(',', $orbColors) }}" style="{{ $orbStyle }}" aria-hidden="true"><canvas></canvas></span>
