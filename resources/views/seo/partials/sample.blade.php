{{-- One recording on a search landing page, drawn like the homepage's samples and played by the
     same card player. $card: a row of HomeController::seoSamples() (a voice, a two-voice
     dialogue, or a designed or cloned voice). $cardClass: extra classes. --}}
@php $spPlay = __('ui.landing.showcase_play'); @endphp
@if(($card['kind'] ?? '') === 'dialogue')
  @include('components.home.dialogue-card', ['dialogue' => $card, 'cardClass' => $cardClass ?? ''])
@elseif(($card['kind'] ?? '') === 'designed')
  <article class="vs-card vs-designed{{ $cardClass ?? '' }}" data-vp-track data-src="{{ $card['clip'] }}" data-language="{{ $card['language'] }}">
    <header class="vs-card-head">
      <x-orb :name="$card['orb']" class="vs-designed-orb" />
      <span class="vs-card-id">
        <span class="vs-card-dialect">{{ $card['name'] }}</span>
        <span class="vs-card-speaker">{{ $card['caption'] }}</span>
      </span>
    </header>
    @if(($card['description'] ?? '') !== '')
      <p class="vs-designed-desc" dir="auto">{{ $card['description'] }}</p>
    @endif
    <p class="vs-card-text" dir="{{ $card['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $card['language'] }}">{{ $card['line'] }}</p>
    <footer class="vs-card-foot">
      <button type="button" class="vs-play" data-vp-play aria-label="{{ $spPlay }}: {{ $card['name'] }}" data-label-play="{{ $spPlay }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
        <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
        <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
      </button>
      <x-home.mini-wave :seed="crc32($card['key']) % 997" />
    </footer>
  </article>
@else
  <article class="vs-card{{ $cardClass ?? '' }}" data-vp-track data-src="{{ $card['audio_url'] }}" data-language="{{ $card['language'] }}">
    <header class="vs-card-head">
      <x-home.speaker-avatar :avatar="$card['avatar']" :name="$card['speaker']" :flag="$card['flag']" size="lg" />
      <span class="vs-card-id">
        <span class="vs-card-dialect">{{ $card['dialect_label'] }}</span>
        <span class="vs-card-speaker">{{ $card['speaker'] }} · {{ $card['title'] }}</span>
      </span>
      @if(($card['badge'] ?? '') !== '')
        <span class="sp-mood">{{ $card['badge'] }}</span>
      @endif
    </header>
    <p class="vs-card-text" dir="{{ $card['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $card['language'] }}">{{ $card['text'] }}</p>
    <footer class="vs-card-foot">
      <button type="button" class="vs-play" data-vp-play aria-label="{{ $spPlay }}: {{ $card['dialect_label'] }}, {{ $card['title'] }}" data-label-play="{{ $spPlay }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
        <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
        <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
      </button>
      <x-home.mini-wave :seed="$card['id']" />
    </footer>
  </article>
@endif
