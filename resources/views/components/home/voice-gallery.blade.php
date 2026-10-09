{{-- Every homepage voice as a card with its flag, grouped by language. --}}
@php
  $vsVoices = $showcaseVoices ?? ['ar' => [], 'en' => []];
  $vsArabicUi = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $vsLangs = array_values(array_filter($vsArabicUi ? ['ar', 'en'] : ['en', 'ar'], fn ($l) => !empty($vsVoices[$l])));
  $vsStart = $vsLangs[0] ?? null;
  $vsCounts = [
    'ar' => count(array_unique(array_column($vsVoices['ar'], 'dialect'))),
    'en' => count(array_unique(array_column($vsVoices['en'], 'dialect'))),
  ];
  $vsDuration = fn (int $s): string => $s > 0 ? intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) : '';
@endphp
@if($vsStart)
<section class="section vs-gallery" id="voice-samples">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.showcase_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.showcase_body', ['ar' => $vsCounts['ar'], 'en' => $vsCounts['en']]) }}</p>
    </div>
    @if(count($vsLangs) > 1)
    <div class="vs-seg" role="tablist" aria-label="{{ __('ui.landing.showcase_languages') }}">
      @foreach($vsLangs as $lang)
        <button type="button" class="vs-seg-btn" role="tab" aria-selected="{{ $lang === $vsStart ? 'true' : 'false' }}" data-vp-gallery="{{ $lang }}">
          {{ __('ui.landing.showcase_tab_'.$lang.'_long') }} <span class="vs-seg-count">{{ $vsCounts[$lang] }}</span>
        </button>
      @endforeach
    </div>
    @endif
  </div>
  <div class="vs-grid">
    @foreach($vsLangs as $lang)
      @foreach($vsVoices[$lang] as $i => $sample)
        <article class="vs-card{{ $i >= 9 ? ' vs-more' : '' }}" data-vp-track data-src="{{ $sample['audio_url'] }}" data-lang="{{ $lang }}" @if($lang !== $vsStart) hidden @endif>
          <header class="vs-card-head">
            <x-home.flag :url="$sample['flag']" size="lg" />
            <span class="vs-card-id">
              <span class="vs-card-dialect">{{ $sample['dialect_label'] }}</span>
              <span class="vs-card-speaker">{{ $sample['speaker'] }}</span>
            </span>
          </header>
          <h3 class="vs-card-title">{{ $sample['title'] }}</h3>
          <p class="vs-card-text" dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $lang }}">{{ $sample['text'] }}</p>
          <footer class="vs-card-foot">
            <button type="button" class="vs-play vs-play-soft" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $sample['dialect_label'] }}, {{ $sample['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
              <svg class="vs-icon-play vs-flip" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
              <svg class="vs-icon-pause" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
            </button>
            <span class="vs-progress" aria-hidden="true"><span></span></span>
            <span class="vs-time" dir="ltr" data-vp-time>{{ $vsDuration((int) $sample['duration']) }}</span>
          </footer>
        </article>
      @endforeach
    @endforeach
  </div>
  @if(max(count($vsVoices['ar']), count($vsVoices['en'])) > 9)
    <div class="vs-more-wrap"><button type="button" class="vs-more-btn" data-vp-more>{{ __('ui.landing.showcase_more') }}</button></div>
  @endif
  <p class="vs-signature">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    {{ __('ui.landing.showcase_signature') }}
  </p>
</section>
@endif
