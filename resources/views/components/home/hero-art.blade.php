{{-- Hero: five Listen speakers sitting on a circle around the Listen mark, taking turns to say a
     line about Listen in their own dialect, with a voice wave flowing from whoever is speaking.
     Press a speaker to hear their line; press the mark to hear the whole conversation. The cast
     follows the page language (App\Support\HeroCast).
     Wide stages show each line in a bubble hanging from the speaker's outer corner; narrow ones
     show it in a caption card under the circle. --}}
@php
  $vsCast = \App\Support\HeroCast::for();
  // Five seats evenly spaced on a circle (radius 38% of the ring), starting at the top.
  $vsSeats = [];
  foreach ($vsCast as $i => $person) {
      $angle = deg2rad(-90 + 72 * $i);
      $vsSeats[] = ['x' => round(50 + 38 * cos($angle), 2), 'y' => round(50 + 38 * sin($angle), 2)];
  }
  $vsInitial = fn (string $name): string => mb_strtoupper(mb_substr($name, 0, 1));
@endphp
<div class="vs-art vs-cast" data-vs-cast role="group" aria-label="{{ __('ui.landing.hero_art_label') }}">
  <div class="vs-cast-ring">
    <div class="vs-art-glow"></div>
    <div class="vs-cast-orbit"></div>

    <svg class="vs-cast-links" viewBox="0 0 100 100" aria-hidden="true">
      <defs>
        <linearGradient id="vsCastFlow" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#C46BFF"/><stop offset="1" stop-color="#7659FA"/>
        </linearGradient>
      </defs>
      @foreach($vsSeats as $i => $seat)
        <line class="vs-cast-rest" x1="{{ $seat['x'] }}" y1="{{ $seat['y'] }}" x2="50" y2="50"/>
        <line class="vs-cast-flow" data-vs-link="{{ $i }}" x1="{{ $seat['x'] }}" y1="{{ $seat['y'] }}" x2="50" y2="50"/>
      @endforeach
    </svg>

    <button type="button" class="vs-cast-mark" data-vs-play-all aria-label="{{ __('ui.landing.hero_play_all') }}" title="{{ __('ui.landing.hero_play_all') }}">
      <span class="vs-cast-mark-ring"></span><span class="vs-cast-mark-ring"></span>
      <svg class="vs-cast-mark-logo" viewBox="0 0 48 48" fill="currentColor" aria-hidden="true">
        <rect x="6" y="19" width="4" height="10" rx="2"/><rect x="14" y="13" width="4" height="22" rx="2"/>
        <rect x="22" y="7" width="4" height="34" rx="2"/><rect x="30" y="13" width="4" height="22" rx="2"/>
        <rect x="38" y="19" width="4" height="10" rx="2"/>
      </svg>
      <svg class="vs-cast-mark-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2.5"/></svg>
    </button>

    @foreach($vsCast as $i => $person)
      @php $seat = $vsSeats[$i]; @endphp
      <div class="vs-speaker vs-seat-{{ $i }}{{ $i === 0 ? ' is-speaking' : '' }}" data-vs-speaker="{{ $i }}"
        data-audio="{{ $person['audio'] }}" style="left: {{ $seat['x'] }}%; top: {{ $seat['y'] }}%">
        <button type="button" class="vs-speaker-photo" aria-label="{{ __('ui.landing.hero_play_one', ['name' => $person['name']]) }}" @if(! $person['audio']) disabled @endif>
          @if($person['avatar'])
            <img src="{{ $person['avatar'] }}" alt="" width="96" height="96" decoding="async">
          @else
            <span class="vs-speaker-initial">{{ $vsInitial($person['name']) }}</span>
          @endif
          <span class="vs-speaker-play" aria-hidden="true">
            <svg class="vs-icon-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
            <svg class="vs-icon-pause" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
          </span>
        </button>
        <span class="vs-speech" aria-hidden="true">
          <span class="vs-speech-line" dir="{{ $person['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $person['language'] }}">{{ $person['line'] }}</span>
          <span class="vs-speech-meta">
            <x-home.flag :url="$person['flag']" size="xs" />
            <span>{{ $person['name'] }} · {{ $person['dialect_label'] }}</span>
          </span>
        </span>
      </div>
    @endforeach
  </div>

  <div class="vs-cast-captions" aria-live="polite">
    @foreach($vsCast as $i => $person)
      <p class="vs-caption{{ $i === 0 ? ' is-active' : '' }}" data-vs-caption="{{ $i }}">
        <span class="vs-speech-line" dir="{{ $person['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $person['language'] }}">{{ $person['line'] }}</span>
        <span class="vs-speech-meta">
          <x-home.flag :url="$person['flag']" size="xs" />
          <span>{{ $person['name'] }} · {{ $person['dialect_label'] }}</span>
        </span>
      </p>
    @endforeach
  </div>
</div>

@once
<script>
// Hero conversation: speakers take turns on their own; pressing one plays their
// line, pressing the Listen mark plays all five lines in turn.
(function () {
  var TURN = 3800;
  var stages = document.querySelectorAll('[data-vs-cast]');
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  stages.forEach(function (stage) {
    var speakers = Array.prototype.slice.call(stage.querySelectorAll('[data-vs-speaker]'));
    var captions = stage.querySelectorAll('[data-vs-caption]');
    var links = stage.querySelectorAll('[data-vs-link]');
    var mark = stage.querySelector('[data-vs-play-all]');
    var audio = new Audio();
    audio.preload = 'none';
    var active = 0, timer = null, mode = null, queue = [];

    function show(i) {
      active = i;
      speakers.forEach(function (el, n) { el.classList.toggle('is-speaking', n === i); });
      captions.forEach(function (el, n) { el.classList.toggle('is-active', n === i); });
      links.forEach(function (el, n) { el.classList.toggle('is-flowing', n === i); });
    }
    function autoplay(on) {
      clearInterval(timer);
      timer = null;
      if (on && !still) timer = setInterval(function () { show((active + 1) % speakers.length); }, TURN);
    }
    function setLive(el) {
      speakers.forEach(function (s) { s.classList.toggle('is-live', s === el); });
    }
    function stop() {
      audio.pause();
      mode = null;
      queue = [];
      stage.classList.remove('is-playing-all');
      setLive(null);
      autoplay(true);
    }
    function playSpeaker(i) {
      var el = speakers[i];
      var src = el.getAttribute('data-audio');
      if (!src) { next(); return; }
      show(i);
      setLive(el);
      audio.src = src;
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: stage } }));
      var p = audio.play();
      if (p && p.catch) p.catch(stop);
    }
    function next() {
      if (!queue.length) { stop(); return; }
      playSpeaker(queue.shift());
    }

    audio.addEventListener('ended', function () {
      if (mode === 'all') setTimeout(function () { if (mode === 'all') next(); }, 350); else stop();
    });
    document.addEventListener('listen:audio-play', function (e) { if (e.detail && e.detail.source !== stage && mode) stop(); });

    speakers.forEach(function (el, i) {
      el.querySelector('.vs-speaker-photo').addEventListener('click', function () {
        var same = mode === 'one' && active === i && !audio.paused;
        stop();
        if (same) return;
        autoplay(false);
        mode = 'one';
        playSpeaker(i);
      });
    });
    mark.addEventListener('click', function () {
      if (mode === 'all') { stop(); return; }
      stop();
      autoplay(false);
      mode = 'all';
      stage.classList.add('is-playing-all');
      queue = speakers.map(function (_, i) { return i; });
      next();
    });

    show(0);
    autoplay(true);
  });
})();
</script>
@endonce
