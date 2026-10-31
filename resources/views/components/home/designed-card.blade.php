{{-- A voice designed from a description in words, as a card on the voice samples page: its orb and
     name, the description it was designed from, the line it says, and the card player.
     $voice: a row of App\Support\VoiceDesign::examples(). --}}
<article class="vs-card vs-designed" data-vp-track data-src="{{ $voice['clip'] }}" data-language="{{ $voice['language'] }}">
  <header class="vs-card-head">
    <x-orb :name="$voice['orb']" class="vs-designed-orb" />
    <span class="vs-card-id">
      <span class="vs-card-dialect">{{ $voice['name'] }}</span>
      <span class="vs-card-speaker">{{ __('ui.samples.designed_from') }}</span>
    </span>
  </header>
  <p class="vs-designed-desc" dir="auto">{{ $voice['description'] }}</p>
  <p class="vs-card-text" dir="{{ $voice['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $voice['language'] }}">{{ $voice['line'] }}</p>
  <footer class="vs-card-foot">
    <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $voice['name'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
      <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
      <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
    </button>
    <x-home.mini-wave :seed="crc32($voice['key']) % 997" />
  </footer>
</article>
