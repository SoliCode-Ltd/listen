{{-- One voice sample as a card: avatar, dialect or accent, the line, and a waveform player.
     Used by the homepage gallery and the voice samples page.
     $sample: a showcase card (HomeController::showcaseCard). $cardClass: extra classes.
     $filterKey: what the section's chips filter on (the region, or the mood). --}}
<article class="vs-card{{ $cardClass ?? '' }}" data-vp-track data-src="{{ $sample['audio_url'] }}" data-region="{{ $filterKey ?? $sample['region'] }}" data-language="{{ $sample['language'] }}" @if(!empty($cardHidden)) hidden @endif>
  <header class="vs-card-head">
    <x-home.speaker-avatar :avatar="$sample['avatar']" :name="$sample['speaker']" :flag="$sample['flag']" size="lg" />
    <span class="vs-card-id">
      <span class="vs-card-dialect">{{ $sample['dialect_label'] }}</span>
      <span class="vs-card-speaker">{{ $sample['speaker'] }} · {{ $sample['title'] }}</span>
    </span>
  </header>
  <p class="vs-card-text" dir="{{ $sample['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $sample['language'] }}">{{ $sample['text'] }}</p>
  <footer class="vs-card-foot">
    <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $sample['dialect_label'] }}, {{ $sample['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
      <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
      <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
    </button>
    <x-home.mini-wave :seed="$sample['id']" />
  </footer>
</article>
