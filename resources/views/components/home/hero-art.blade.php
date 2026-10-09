{{-- Hero artwork: lines of text flowing into a voice wave around the Listen mark. Decorative only, no audio. --}}
@php
  $artArabic = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $artBars = [];
  for ($i = 0; $i < 56; $i++) {
    $d = abs($i - 27.5) / 27.5;
    $artBars[] = (int) round(10 + 90 * (1 - $d * 0.82) * (0.45 + 0.55 * abs(sin($i * 0.83) * cos($i * 0.29))));
  }
@endphp
<div class="vs-art" role="img" aria-label="{{ __('ui.landing.hero_art_label') }}">
  <div class="vs-art-glow"></div>
  <div class="vs-art-grid"></div>

  <div class="vs-art-wave">
    @foreach($artBars as $i => $h)<i style="--h: {{ $h }}%; --i: {{ $i }}"></i>@endforeach
  </div>

  <div class="vs-art-rings"><span></span><span></span><span></span></div>
  <div class="vs-art-core">
    <svg viewBox="0 0 48 48" width="44" height="44" fill="currentColor" aria-hidden="true">
      <rect x="6" y="19" width="4" height="10" rx="2"/><rect x="14" y="13" width="4" height="22" rx="2"/>
      <rect x="22" y="7" width="4" height="34" rx="2"/><rect x="30" y="13" width="4" height="22" rx="2"/>
      <rect x="38" y="19" width="4" height="10" rx="2"/>
    </svg>
  </div>

  <div class="vs-art-note vs-art-note-text">
    <span class="vs-art-glyph" lang="{{ $artArabic ? 'ar' : 'en' }}">{{ $artArabic ? 'ض' : 'Aa' }}</span>
    <span class="vs-art-lines"><i></i><i></i><i></i></span>
  </div>
  <div class="vs-art-note vs-art-note-voice">
    <span class="vs-art-eq"><i></i><i></i><i></i><i></i><i></i></span>
    <span class="vs-art-lines vs-art-lines-short"><i></i><i></i></span>
  </div>
  <div class="vs-art-chip vs-art-chip-a"></div>
  <div class="vs-art-chip vs-art-chip-b"></div>
</div>
