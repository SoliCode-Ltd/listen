{{-- Homepage voices in the page language: avatar, dialect or accent, and a waveform player.
     Region chips filter the cards; without a filter the first nine show, and "Show all voices" opens the samples page. --}}
@php
  $vsVoices = $showcaseVoices ?? [];
  $vsRegions = array_values(array_unique(array_column($vsVoices, 'region')));
  $vsOrder = ['fusha', 'gulf', 'levant', 'nile', 'iraq_yemen', 'maghreb', 'americas', 'uk_ie', 'africa', 'asia_pacific', 'other'];
  usort($vsRegions, fn ($a, $b) => array_search($a, $vsOrder, true) <=> array_search($b, $vsOrder, true));
@endphp
@if(!empty($vsVoices))
<section class="section vs-gallery" id="voice-samples">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.showcase_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.showcase_body') }}</p>
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
      @include('components.home.voice-card', ['sample' => $sample, 'cardClass' => $i >= 9 ? ' vs-more' : ''])
    @endforeach
  </div>
  {{-- Every voice, in both languages, is on the voice samples page. --}}
  <div class="vs-more-wrap"><a class="vs-more-btn" href="{{ route('samples') }}#{{ app()->getLocale() === 'ar' ? 'arabic-dialects' : 'english-accents' }}">{{ __('ui.landing.showcase_more') }}</a></div>
</section>
@endif
