{{--
  A small drawing above each figure in the homepage facts strip, showing what the
  number means: real dialect greetings, the countries' accents, the places a voice
  can be heard in, and the moods a line can be delivered in. Decorative only.
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
      {{-- One voice, four ways to deliver a line: calm, excited, surprised, serious. --}}
      <span class="fa-face" style="--i: 0"><svg viewBox="0 0 32 32"><path d="M8.5 14q2.5 2.5 5 0M18.5 14q2.5 2.5 5 0"/><path d="M11 20.5q5 3.5 10 0"/></svg></span>
      <span class="fa-face is-on" style="--i: 1"><svg viewBox="0 0 32 32"><path d="M8.5 14.5q2.5-3.5 5 0M18.5 14.5q2.5-3.5 5 0"/><path class="fa-fill" d="M9.5 18.5h13a6.5 6.5 0 0 1-13 0z"/></svg></span>
      <span class="fa-face" style="--i: 2"><svg viewBox="0 0 32 32"><path d="M8.5 9.5q2.5-1.6 5 0M18.5 9.5q2.5-1.6 5 0"/><circle class="fa-fill" cx="11" cy="14" r="1.7"/><circle class="fa-fill" cx="21" cy="14" r="1.7"/><ellipse cx="16" cy="22" rx="2.6" ry="3.1"/></svg></span>
      <span class="fa-face" style="--i: 3"><svg viewBox="0 0 32 32"><path d="M8.5 10.5h5M18.5 10.5h5"/><circle class="fa-fill" cx="11" cy="14.5" r="1.7"/><circle class="fa-fill" cx="21" cy="14.5" r="1.7"/><path d="M12 21.5h8"/></svg></span>
      @break
  @endswitch
</div>
