{{-- Hero artwork: five Listen speakers taking turns to talk about Listen, each in their own dialect
     and mood, with a voice wave flowing from whoever is speaking to the Listen mark. Decorative,
     no audio. The cast follows the page language (App\Support\HeroCast). Each speaker's turn is a
     slot in an 18 second loop (see .vs-cast-* in voice-showcase.css). --}}
@php
  $vsCast = \App\Support\HeroCast::for();
  // Where each of the five sits (percent of the stage) and which way their bubble opens.
  $vsSlots = [
      ['x' => 17, 'y' => 20, 'side' => 'end'],
      ['x' => 83, 'y' => 17, 'side' => 'start'],
      ['x' => 90, 'y' => 63, 'side' => 'start'],
      ['x' => 50, 'y' => 88, 'side' => 'up'],
      ['x' => 10, 'y' => 66, 'side' => 'end'],
  ];
  $vsCenter = ['x' => 50, 'y' => 46];
  $vsInitial = fn (string $name): string => mb_strtoupper(mb_substr($name, 0, 1));
@endphp
<div class="vs-art vs-cast" role="img" aria-label="{{ __('ui.landing.hero_art_label') }}">
  <div class="vs-art-glow"></div>
  <div class="vs-art-orbit"></div>

  {{-- Voice paths from each speaker to the Listen mark; the speaker's path flows on their turn. --}}
  <svg class="vs-cast-links" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
    <defs>
      <linearGradient id="vsCastFlow" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#C46BFF"/><stop offset="1" stop-color="#7659FA"/>
      </linearGradient>
    </defs>
    @foreach($vsSlots as $i => $slot)
      @php
        $mx = ($slot['x'] + $vsCenter['x']) / 2 + ($slot['y'] < 50 ? -6 : 6);
        $my = ($slot['y'] + $vsCenter['y']) / 2 + ($slot['x'] < 50 ? 6 : -6);
        $d = "M{$slot['x']} {$slot['y']} Q{$mx} {$my} {$vsCenter['x']} {$vsCenter['y']}";
      @endphp
      <path class="vs-cast-rest" d="{{ $d }}" vector-effect="non-scaling-stroke"/>
      <path class="vs-cast-flow vs-cast-{{ $i }}" d="{{ $d }}" vector-effect="non-scaling-stroke"/>
      <path class="vs-cast-flow vs-cast-flow-2 vs-cast-{{ $i }}" d="{{ $d }}" vector-effect="non-scaling-stroke"/>
    @endforeach
  </svg>

  <span class="vs-cast-mark" style="left: {{ $vsCenter['x'] }}%; top: {{ $vsCenter['y'] }}%">
    <span class="vs-cast-mark-ring"></span><span class="vs-cast-mark-ring"></span>
    <svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true">
      <rect x="6" y="19" width="4" height="10" rx="2"/><rect x="14" y="13" width="4" height="22" rx="2"/>
      <rect x="22" y="7" width="4" height="34" rx="2"/><rect x="30" y="13" width="4" height="22" rx="2"/>
      <rect x="38" y="19" width="4" height="10" rx="2"/>
    </svg>
  </span>

  @foreach($vsCast as $i => $person)
    @php $slot = $vsSlots[$i] ?? $vsSlots[0]; @endphp
    <div class="vs-speaker vs-speaker-{{ $slot['side'] }}" style="left: {{ $slot['x'] }}%; top: {{ $slot['y'] }}%">
      <span class="vs-speaker-ring vs-cast-{{ $i }}"></span>
      <span class="vs-speaker-photo vs-cast-grow-{{ $i }}">
        @if($person['avatar'])
          <img src="{{ $person['avatar'] }}" alt="" width="96" height="96" decoding="async">
        @else
          <span class="vs-speaker-initial">{{ $vsInitial($person['name']) }}</span>
        @endif
      </span>
      <span class="vs-speaker-meter vs-cast-{{ $i }}"><i></i><i></i><i></i><i></i></span>
      <span class="vs-speech vs-cast-{{ $i }}">
        <span class="vs-speech-line" dir="{{ $person['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $person['language'] }}">{{ $person['line'] }}</span>
        <span class="vs-speech-meta">
          <x-home.flag :url="$person['flag']" size="xs" />
          <span>{{ $person['name'] }} · {{ $person['dialect_label'] }}</span>
          <span class="vs-speech-mood">{{ $person['mood'] }}</span>
        </span>
      </span>
    </div>
  @endforeach
</div>
