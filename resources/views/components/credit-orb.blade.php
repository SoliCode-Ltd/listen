{{-- The credits badge on pricing cards: a glowing brand orb with the sound wave and two sparkles. Sized by font-size (1em). --}}
@php($gid = 'orb-'.\Illuminate\Support\Str::random(6))
<svg {{ $attributes->merge(['class' => 'credit-orb']) }} viewBox="0 0 48 48" width="1em" height="1em" aria-hidden="true" focusable="false" style="display:inline-block;flex:none;overflow:visible">
  <defs>
    <linearGradient id="{{ $gid }}-fill" x1="8" y1="6" x2="40" y2="42" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#8B6CFF"/>
      <stop offset=".55" stop-color="#6A45F2"/>
      <stop offset="1" stop-color="#3D8BFF"/>
    </linearGradient>
    <radialGradient id="{{ $gid }}-shine" cx="17" cy="13" r="18" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#fff" stop-opacity=".55"/>
      <stop offset="1" stop-color="#fff" stop-opacity="0"/>
    </radialGradient>
    <filter id="{{ $gid }}-glow" x="-40%" y="-40%" width="180%" height="180%">
      <feGaussianBlur stdDeviation="2.4"/>
    </filter>
  </defs>
  {{-- soft glow under the orb --}}
  <circle cx="24" cy="25" r="17" fill="#6A45F2" opacity=".28" filter="url(#{{ $gid }}-glow)"/>
  <circle cx="24" cy="24" r="17" fill="url(#{{ $gid }}-fill)"/>
  <circle cx="24" cy="24" r="17" fill="url(#{{ $gid }}-shine)"/>
  <circle cx="24" cy="24" r="16.4" fill="none" stroke="#fff" stroke-opacity=".28" stroke-width="1"/>
  {{-- the sound wave --}}
  <g stroke="#fff" stroke-width="2.6" stroke-linecap="round">
    <path d="M15.5 21.5v5"/>
    <path d="M20 17.5v13"/>
    <path d="M24.5 14.5v19"/>
    <path d="M29 18.5v11"/>
    <path d="M33.5 22v4"/>
  </g>
  {{-- sparkles --}}
  <path d="M40.5 5.5l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9z" fill="#A78BFA"/>
  <path d="M6.5 37l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z" fill="#7DB4FF"/>
</svg>
