{{--
  A small drawing above each figure in the homepage facts strip, showing what the
  number means: real dialect greetings, the countries' accents, the places a voice
  can be heard in, and one sentence delivered in different moods. Decorative only.
--}}
@props(['kind'])
<div class="fact-art fact-art-{{ $kind }}" aria-hidden="true">
  @switch($kind)
    @case('dialects')
      {{-- "How are you?" as the Gulf, Egypt, the Levant and the Maghreb say it. --}}
      @foreach(['شلونك؟', 'إزيك؟', 'كيفك؟', 'لاباس؟'] as $i => $greeting)
        <span class="fa-bubble" dir="rtl" lang="ar" style="--i: {{ $i }}">{{ $greeting }}</span>
      @endforeach
      @break
    @case('accents')
      <span class="fa-flags">
        @foreach(['us', 'gb', 'au', 'ie', 'in', 'za'] as $i => $flag)
          <img src="{{ \App\Support\VersionedAsset::url('images/flags/'.$flag.'.svg') }}" alt="" width="34" height="34" loading="lazy" style="--i: {{ $i }}">
        @endforeach
      </span>
      <svg class="fa-wave" viewBox="0 0 120 28" preserveAspectRatio="none">
        @foreach([6, 12, 20, 9, 16, 24, 14, 8, 18, 26, 12, 7, 15, 22, 10, 5] as $i => $h)
          <rect x="{{ $i * 7.6 + 1 }}" y="{{ (28 - $h) / 2 }}" width="3.6" height="{{ $h }}" rx="1.8" style="--i: {{ $i }}"/>
        @endforeach
      </svg>
      @break
    @case('places')
      {{-- A hall, a car, an old radio and a mountain echo. --}}
      <span class="fa-tile" style="--i: 0"><svg viewBox="0 0 32 32"><path d="M5 27V14l11-9 11 9v13"/><path d="M11 27V17a5 5 0 0 1 10 0v10"/><path d="M3 27h26"/></svg></span>
      <span class="fa-tile" style="--i: 1"><svg viewBox="0 0 32 32"><path d="M4 20v-3l3-6h14l5 6h2a2 2 0 0 1 2 2v1"/><path d="M4 20h3m6 0h7m6 0h2"/><circle cx="10" cy="21" r="2.8"/><circle cx="23" cy="21" r="2.8"/><path d="M10 11v6m5-6v6"/></svg></span>
      <span class="fa-tile is-on" style="--i: 2"><svg viewBox="0 0 32 32"><rect x="4" y="11" width="24" height="16" rx="3"/><path d="M9 11 22 4"/><circle cx="21" cy="19" r="4"/><path d="M8 16h5m-5 3h5m-5 3h5"/></svg></span>
      <span class="fa-tile" style="--i: 3"><svg viewBox="0 0 32 32"><path d="M2 27 11 12l5 7 4-5 10 13z"/><path d="M22 6a5 5 0 0 1 0 7m3-10a9 9 0 0 1 0 13"/></svg></span>
      @break
    @case('styles')
      {{-- The same line, calm, then excited, then whispered. --}}
      <svg class="fa-moods" viewBox="0 0 180 56" preserveAspectRatio="none">
        <defs>
          <linearGradient id="factMoodGradient" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#A28FFF"/>
            <stop offset=".5" stop-color="#7659FA"/>
            <stop offset="1" stop-color="#5B3FE6"/>
          </linearGradient>
        </defs>
        @foreach([10, 14, 12, 16, 13, 15, 11, 30, 44, 22, 50, 36, 48, 26, 40, 18, 8, 6, 9, 5, 7, 4, 6, 3] as $i => $h)
          <rect x="{{ $i * 7.5 + 1 }}" y="{{ (56 - $h) / 2 }}" width="4" height="{{ $h }}" rx="2" fill="url(#factMoodGradient)" style="--i: {{ $i }}"/>
        @endforeach
      </svg>
      @break
  @endswitch
</div>
