{{-- Homepage voices in the page language: avatar, dialect or accent, and a waveform player.
     Region chips filter the cards; without a filter the first nine show until "Show all voices". --}}
@php
  $vsVoices = $showcaseVoices ?? [];
  $vsCount = count(array_unique(array_column($vsVoices, 'dialect')));
  $vsRegions = array_values(array_unique(array_column($vsVoices, 'region')));
  $vsOrder = ['fusha', 'gulf', 'levant', 'nile', 'iraq_yemen', 'maghreb', 'americas', 'uk_ie', 'africa', 'asia_pacific', 'other'];
  usort($vsRegions, fn ($a, $b) => array_search($a, $vsOrder, true) <=> array_search($b, $vsOrder, true));
@endphp
@if(!empty($vsVoices))
<section class="section vs-gallery" id="voice-samples">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.showcase_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.showcase_body', ['count' => $vsCount]) }}</p>
    </div>
  </div>
  @if(count($vsRegions) > 1)
    <div class="vs-chips" role="tablist" aria-label="{{ __('ui.landing.region_label') }}">
      <button type="button" class="vs-chip" role="tab" aria-selected="true" data-vp-region="all">{{ __('ui.landing.region_all') }}</button>
      @foreach($vsRegions as $region)
        <button type="button" class="vs-chip" role="tab" aria-selected="false" data-vp-region="{{ $region }}">{{ __('ui.landing.region_'.$region) }}</button>
      @endforeach
    </div>
  @endif
  <div class="vs-grid">
    @foreach($vsVoices as $i => $sample)
      <article class="vs-card{{ $i >= 9 ? ' vs-more' : '' }}" data-vp-track data-src="{{ $sample['audio_url'] }}" data-region="{{ $sample['region'] }}">
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
</section>
@endif
