{{-- The credit mark: a coin with a sound wave, shown beside credit amounts. Sized by font-size (1em). --}}
@php($gid = 'credit-g-'.\Illuminate\Support\Str::random(6))
<svg {{ $attributes->merge(['class' => 'credit-icon']) }} viewBox="0 0 24 24" width="1em" height="1em" role="img" aria-label="{{ __('ui.pricing.credits_icon_label') }}" focusable="false" style="display:inline-block;flex:none;vertical-align:-0.14em">
  <defs>
    <linearGradient id="{{ $gid }}" x1="3" y1="2" x2="21" y2="22" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#A78BFA"/>
      <stop offset="1" stop-color="#6A45F2"/>
    </linearGradient>
  </defs>
  <circle cx="12" cy="12" r="10.5" fill="url(#{{ $gid }})"/>
  <circle cx="12" cy="12" r="8.1" fill="none" stroke="#fff" stroke-opacity=".38" stroke-width="1"/>
  <g stroke="#fff" stroke-width="1.9" stroke-linecap="round">
    <path d="M8.2 10.6v2.8"/>
    <path d="M10.75 8.6v6.8"/>
    <path d="M13.25 7.4v9.2"/>
    <path d="M15.8 9.9v4.2"/>
  </g>
</svg>
