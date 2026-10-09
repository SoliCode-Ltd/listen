{{-- Two-voice dialogues in the page language, shown as a script: each line has its speaker's
     avatar and name, and carries when it is spoken, so it lights up in time with the voice. --}}
@php
  $vsDialogues = $showcaseDialogues ?? [];
  $vsUntagged = fn (string $text): string => trim((string) preg_replace('/\s*<[^<>\n]{1,24}>\s*/u', ' ', $text));
@endphp
@if(!empty($vsDialogues))
<section class="section vs-dialogues" id="dialogues">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.dialogue_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.dialogue_body') }}</p>
    </div>
  </div>
  <div class="vs-dialogue-grid">
    @foreach($vsDialogues as $i => $dialogue)
      @php $vsPair = $dialogue['speakers']; @endphp
      <article class="vs-dialogue{{ $i >= 4 ? ' vs-more' : '' }}" data-vp-track data-vp-dialogue data-src="{{ $dialogue['audio_url'] }}">
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
              {{ __('ui.landing.dialogue_speakers', ['a' => $vsPair[0]['name'] ?? '', 'b' => $vsPair[1]['name'] ?? '']) }} · {{ $dialogue['dialect_label'] }}
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
            <svg class="vs-icon-play vs-flip" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
            <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
          </button>
          <x-home.mini-wave :seed="$dialogue['id']" :count="60" />
        </footer>
      </article>
    @endforeach
  </div>
  @if(count($vsDialogues) > 4)
    <div class="vs-more-wrap"><button type="button" class="vs-more-btn" data-vp-more>{{ __('ui.landing.dialogue_more') }}</button></div>
  @endif
</section>
@endif
