{{-- Homepage voices in the page language, each card with its flag, dialect or accent, and a waveform player. --}}
@php
  $vsVoices = $showcaseVoices ?? [];
  $vsCount = count(array_unique(array_column($vsVoices, 'dialect')));
@endphp
@if(!empty($vsVoices))
<section class="section vs-gallery" id="voice-samples">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.showcase_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.showcase_body', ['count' => $vsCount]) }}</p>
    </div>
  </div>
  <div class="vs-grid">
    @foreach($vsVoices as $i => $sample)
      <article class="vs-card{{ $i >= 9 ? ' vs-more' : '' }}" data-vp-track data-src="{{ $sample['audio_url'] }}">
        <header class="vs-card-head">
          <x-home.flag :url="$sample['flag']" size="lg" />
          <span class="vs-card-id">
            <span class="vs-card-dialect">{{ $sample['dialect_label'] }}</span>
            <span class="vs-card-speaker">{{ $sample['speaker'] }} · {{ $sample['title'] }}</span>
          </span>
        </header>
        <p class="vs-card-text" dir="{{ $sample['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $sample['language'] }}">{{ $sample['text'] }}</p>
        <footer class="vs-card-foot">
          <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $sample['dialect_label'] }}, {{ $sample['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
            <svg class="vs-icon-play vs-flip" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
            <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
          </button>
          <x-home.mini-wave :seed="$sample['id']" />
        </footer>
      </article>
    @endforeach
  </div>
  @if(count($vsVoices) > 9)
    <div class="vs-more-wrap"><button type="button" class="vs-more-btn" data-vp-more>{{ __('ui.landing.showcase_more') }}</button></div>
  @endif
  <p class="vs-signature">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    {{ __('ui.landing.showcase_signature') }}
  </p>
</section>
@endif
