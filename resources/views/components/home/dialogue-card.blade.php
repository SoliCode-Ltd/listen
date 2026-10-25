{{-- A two-voice dialogue as a script: each line with its speaker's avatar and name, lit in
     time with the voice. Used by the homepage and the voice samples page.
     $dialogue: a showcase card with speakers and turns. $cardClass: extra classes. $cardHidden: hidden at first. --}}
@php
  $vsUntagged = fn (string $text): string => trim((string) preg_replace('/\s*<[^<>\n]{1,24}>\s*/u', ' ', $text));
@endphp
@php $vsPair = $dialogue['speakers']; @endphp
<article class="vs-dialogue{{ $cardClass ?? '' }}" data-vp-track data-vp-dialogue data-src="{{ $dialogue['audio_url'] }}" data-language="{{ $dialogue['language'] }}" @if(!empty($cardHidden)) hidden @endif>
  <header class="vs-dialogue-head">
    <span class="vs-avatar-pair" aria-hidden="true">
      @foreach(array_slice($vsPair, 0, 2) as $si => $speaker)
        <x-home.speaker-avatar :avatar="$speaker['avatar'] ?? null" :name="$speaker['name']" :flag="false" class="vs-avatar-{{ $si }}" />
      @endforeach
    </span>
    <span class="vs-dialogue-id">
      <span class="vs-dialogue-title">{{ $dialogue['title'] }}</span>
      <span class="vs-dialogue-meta">
        <x-home.flag :url="$dialogue['flag']" size="xs" />
        {{-- Each name isolated, so English names keep their order on an Arabic page. --}}
        {!! __('ui.landing.dialogue_speakers', ['a' => '<bdi>'.e($vsPair[0]['name'] ?? '').'</bdi>', 'b' => '<bdi>'.e($vsPair[1]['name'] ?? '').'</bdi>']) !!} · {{ $dialogue['dialect_label'] }}
      </span>
    </span>
  </header>
  <ol class="vs-turns" dir="{{ $dialogue['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $dialogue['language'] }}">
    @foreach($dialogue['turns'] as $turn)
      @php $vsLine = $vsUntagged($turn['text']); @endphp
      @php $vsWho = $vsPair[$turn['index']] ?? null; @endphp
      <li class="vs-turn vs-turn-{{ $turn['index'] % 2 }}" data-vp-turn data-weight="{{ mb_strlen($vsLine) }}"
        @if($turn['start'] !== null) data-start="{{ $turn['start'] }}" data-end="{{ $turn['end'] }}" @endif>
        <x-home.speaker-avatar :avatar="$vsWho['avatar'] ?? null" :name="$turn['speaker']" :flag="false" class="vs-turn-avatar" />
        <span class="vs-turn-body">
          <span class="vs-turn-who">{{ $turn['speaker'] }}</span>
          <span class="vs-turn-text">{{ $vsLine }}</span>
        </span>
        <span class="vs-turn-bar" aria-hidden="true"></span>
      </li>
    @endforeach
  </ol>
  <footer class="vs-card-foot">
    <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $dialogue['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
      <svg class="vs-icon-play" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
      <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
    </button>
    <x-home.mini-wave :seed="$dialogue['id']" :count="60" />
  </footer>
</article>
