{{-- The credit coin: gold, with the brand's sound wave struck on its face. Sized by font-size (1em). --}}
@php($gid = 'credit-'.\Illuminate\Support\Str::random(6))
<svg {{ $attributes->merge(['class' => 'credit-icon']) }} viewBox="0 0 24 24" width="1em" height="1em" role="img" aria-label="{{ __('ui.pricing.credits_icon_label') }}" focusable="false" style="display:inline-block;flex:none;vertical-align:-0.16em;overflow:visible">
  <defs>
    <radialGradient id="{{ $gid }}-face" cx="9" cy="7.5" r="13" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#FFF6C7"/>
      <stop offset=".45" stop-color="#FAD25A"/>
      <stop offset="1" stop-color="#E0A10F"/>
    </radialGradient>
    <linearGradient id="{{ $gid }}-edge" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#C98A06"/>
      <stop offset="1" stop-color="#9A6400"/>
    </linearGradient>
  </defs>
  {{-- the coin's edge, then its face --}}
  <circle cx="12" cy="13.2" r="10.3" fill="url(#{{ $gid }}-edge)"/>
  <circle cx="12" cy="11.4" r="10.3" fill="url(#{{ $gid }}-face)"/>
  <circle cx="12" cy="11.4" r="10.3" fill="none" stroke="#B9800A" stroke-width=".9"/>
  <circle cx="12" cy="11.4" r="7.7" fill="none" stroke="#C4890C" stroke-opacity=".55" stroke-width=".8"/>
  {{-- the sound wave --}}
  <g stroke="#5B36E8" stroke-width="1.9" stroke-linecap="round">
    <path d="M8.1 10v2.8"/>
    <path d="M10.7 8.1v6.6"/>
    <path d="M13.3 7v8.8"/>
    <path d="M15.9 9.4v4"/>
  </g>
  {{-- shine --}}
  <path d="M5.6 8.2a7.4 7.4 0 0 1 4.6-4.4" fill="none" stroke="#fff" stroke-opacity=".75" stroke-width="1.1" stroke-linecap="round"/>
</svg>
