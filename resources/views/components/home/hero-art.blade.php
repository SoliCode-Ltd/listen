{{-- Hero artwork: two people in conversation, voice waves flowing from whoever is speaking to the other,
     and more people around them. Drawn illustrations, decorative only, no audio.
     The two speakers take turns on an 8 second loop (see .vs-turn-a / .vs-turn-b in voice-showcase.css). --}}
@php
  // Seamless sine waves: each path is two wavelengths wider than the view and slides by one wavelength.
  $vsWavePath = function (float $amplitude, float $wavelength, float $phase): string {
      $d = '';
      for ($x = -$wavelength; $x <= 400 + $wavelength; $x += 4) {
          $y = 60 + $amplitude * sin(2 * M_PI * ($x / $wavelength) + $phase);
          $d .= ($d === '' ? 'M' : 'L').round($x, 1).' '.round($y, 1).' ';
      }
      return trim($d);
  };
  $vsWaves = [
      ['d' => $vsWavePath(26, 100, 0), 'w' => 100, 'class' => 'vs-flow-1'],
      ['d' => $vsWavePath(16, 80, 1.2), 'w' => 80, 'class' => 'vs-flow-2'],
      ['d' => $vsWavePath(9, 50, 2.4), 'w' => 50, 'class' => 'vs-flow-3'],
  ];
@endphp
<div class="vs-art" role="img" aria-label="{{ __('ui.landing.hero_art_label') }}">
  <div class="vs-art-glow"></div>
  <div class="vs-art-orbit"></div>

  {{-- Voice waves between the two speakers: one set flows each way, shown on that speaker's turn. --}}
  <div class="vs-flow">
    @foreach(['ab' => 'vs-turn-a', 'ba' => 'vs-turn-b'] as $direction => $turn)
      <svg class="vs-flow-set vs-flow-{{ $direction }} {{ $turn }}" viewBox="0 0 400 120" preserveAspectRatio="none" aria-hidden="true">
        <defs>
          <linearGradient id="vsFlowGrad{{ $direction }}" x1="0" x2="1">
            <stop offset="0" stop-color="#7659FA"/><stop offset=".5" stop-color="#C46BFF"/><stop offset="1" stop-color="#7659FA"/>
          </linearGradient>
        </defs>
        @foreach($vsWaves as $wave)
          <path class="{{ $wave['class'] }}" d="{{ $wave['d'] }}" style="--vs-wl: {{ $wave['w'] }}px" stroke="url(#vsFlowGrad{{ $direction }})" fill="none" vector-effect="non-scaling-stroke"/>
        @endforeach
      </svg>
    @endforeach
    <span class="vs-flow-pulse vs-flow-pulse-ab vs-turn-a"></span>
    <span class="vs-flow-pulse vs-flow-pulse-ba vs-turn-b"></span>
    <span class="vs-flow-mark">
      <svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true">
        <rect x="6" y="19" width="4" height="10" rx="2"/><rect x="14" y="13" width="4" height="22" rx="2"/>
        <rect x="22" y="7" width="4" height="34" rx="2"/><rect x="30" y="13" width="4" height="22" rx="2"/>
        <rect x="38" y="19" width="4" height="10" rx="2"/>
      </svg>
    </span>
  </div>

  {{-- The two speakers --}}
  <div class="vs-person vs-person-a">
    <span class="vs-person-rings vs-turn-a"><i></i><i></i></span>
    <span class="vs-person-face vs-speaks-a"><x-home.person who="hala" /></span>
    <span class="vs-bubble vs-bubble-a vs-turn-a"><i></i><i></i><i></i><i></i><i></i></span>
  </div>
  <div class="vs-person vs-person-b">
    <span class="vs-person-rings vs-turn-b"><i></i><i></i></span>
    <span class="vs-person-face vs-speaks-b"><x-home.person who="omar" /></span>
    <span class="vs-bubble vs-bubble-b vs-turn-b"><i></i><i></i><i></i><i></i><i></i></span>
  </div>

  {{-- People around them, each with a small voice badge --}}
  <div class="vs-person vs-person-sm vs-person-c">
    <span class="vs-person-face"><x-home.person who="nadia" /></span>
    <span class="vs-person-badge"><i></i><i></i><i></i></span>
  </div>
  <div class="vs-person vs-person-sm vs-person-d">
    <span class="vs-person-face"><x-home.person who="karim" /></span>
    <span class="vs-person-badge"><i></i><i></i><i></i></span>
  </div>
  <div class="vs-person vs-person-sm vs-person-e">
    <span class="vs-person-face"><x-home.person who="saleh" /></span>
    <span class="vs-person-badge"><i></i><i></i><i></i></span>
  </div>

  <span class="vs-art-spark vs-art-spark-1"></span>
  <span class="vs-art-spark vs-art-spark-2"></span>
  <span class="vs-art-spark vs-art-spark-3"></span>
</div>
