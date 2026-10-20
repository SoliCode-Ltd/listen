{{-- One long recording on the voice samples page: how long it took to make against how long it
     plays, a player that can move through it, and the whole text. $read: LongFormSamples::forPage(). --}}
@php
  $dir = $read['language'] === 'ar' ? 'rtl' : 'ltr';
  $paragraphs = preg_split('/\n\s*\n/u', trim($read['text'])) ?: [];
@endphp
<article class="vs-long" data-long data-src="{{ $read['audio_url'] }}" data-seconds="{{ round($read['audio_seconds'], 2) }}" style="--share: {{ round($read['share'], 4) }}">
  <header class="vs-long-head">
    <span class="vs-long-kind">{{ $read['kind'] }}</span>
    <h3 class="vs-long-title" lang="{{ $read['language'] }}" dir="{{ $dir }}">{{ $read['title'] }}</h3>
    <p class="vs-long-by">
      @if($read['avatar'])<img src="{{ $read['avatar'] }}" alt="" width="28" height="28" loading="lazy">@endif
      <span>
        @if($read['speaker'] !== ''){{ __('ui.samples.long_voice', ['name' => $read['speaker']]) }} · @endif{{ __('ui.samples.long_details', ['chars' => number_format($read['chars']), 'words' => number_format($read['words'])]) }}
      </span>
    </p>
  </header>

  {{-- The race: making it is the short bright bar, listening to it the long one. --}}
  <div class="vs-long-race" role="img" aria-label="{{ __('ui.samples.long_bar_label') }}: {{ $read['made_in'] }} / {{ $read['listen_time'] }}">
    <div class="vs-long-lane is-made">
      <span class="vs-long-label">{{ __('ui.samples.long_made_label') }}</span>
      <strong class="vs-long-figure">{{ $read['made_in'] }}</strong>
      <span class="vs-long-track"><span class="vs-long-fill"></span></span>
    </div>
    <div class="vs-long-lane is-listen">
      <span class="vs-long-label">{{ __('ui.samples.long_listen_label') }}</span>
      <strong class="vs-long-figure">{{ $read['listen_time'] }}</strong>
      <span class="vs-long-track"><span class="vs-long-fill"></span></span>
    </div>
  </div>
  @if($read['times'] >= 2)
    <p class="vs-long-times">{{ __('ui.samples.long_faster', ['times' => $read['times']]) }}</p>
  @endif

  <div class="vs-long-player">
    <button type="button" class="vs-play" data-long-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $read['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}: {{ $read['title'] }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}: {{ $read['title'] }}">
      <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
      <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
    </button>
    <input type="range" class="vs-long-seek" data-long-seek min="0" max="1000" step="1" value="0" aria-label="{{ __('ui.samples.long_seek', ['title' => $read['title']]) }}" aria-valuetext="0:00 / {{ $read['listen_time'] }}">
    <span class="vs-long-clock"><span data-long-now>0:00</span> / {{ $read['listen_time'] }}</span>
  </div>

  <details class="vs-long-text">
    <summary>{{ __('ui.samples.long_read_text') }}</summary>
    <div lang="{{ $read['language'] }}" dir="{{ $dir }}">
      @foreach($paragraphs as $paragraph)
        <p>{{ $paragraph }}</p>
      @endforeach
    </div>
  </details>
</article>
