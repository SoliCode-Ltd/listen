{{-- Two-voice dialogues: the line being spoken lights up as it plays. --}}
@php
  $vsDialogues = $showcaseDialogues ?? [];
  $vsInitial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '•', 0, 1));
  $vsDuration = fn (int $s): string => $s > 0 ? intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) : '';
  $vsUntagged = fn (string $text): string => trim((string) preg_replace('/\s*<[^<>\n]{1,24}>\s*/u', ' ', $text));
@endphp
@if(!empty($vsDialogues))
<section class="section vs-dialogues" id="dialogues">
  <div class="vs-head">
    <div>
      <span class="vs-badge">{{ __('ui.landing.dialogue_badge') }}</span>
      <h2 class="vs-title">{{ __('ui.landing.dialogue_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.dialogue_body') }}</p>
    </div>
    <a class="vs-link vs-head-link" href="{{ auth()->check() ? route('dashboard').'#generateVoiceSection' : route('demo.page') }}">{{ __('ui.landing.dialogue_cta') }}</a>
  </div>
  <div class="vs-dialogue-grid">
    @foreach($vsDialogues as $dialogue)
      @php $vsPair = $dialogue['speakers']; @endphp
      <article class="vs-dialogue" data-vp-track data-vp-dialogue data-src="{{ $dialogue['audio_url'] }}">
        <header class="vs-dialogue-head">
          <span class="vs-avatar-pair" aria-hidden="true">
            @foreach(array_slice($vsPair, 0, 2) as $si => $speaker)
              <span class="vs-avatar vs-avatar-{{ $si }}">{{ $vsInitial($speaker['name']) }}</span>
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
            <li class="vs-turn vs-turn-{{ $turn['index'] % 2 }}" data-vp-turn data-weight="{{ mb_strlen($vsLine) }}">
              <span class="vs-turn-who">{{ $turn['speaker'] }}</span>
              <span class="vs-turn-text">{{ $vsLine }}</span>
            </li>
          @endforeach
          @if($dialogue['sign_off'] !== '')
            <li class="vs-turn vs-turn-sign" data-vp-turn data-weight="{{ mb_strlen($dialogue['sign_off']) }}">
              <span class="vs-turn-text">{{ $dialogue['sign_off'] }}</span>
            </li>
          @endif
        </ol>
        <footer class="vs-card-foot">
          <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $dialogue['title'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
            <svg class="vs-icon-play vs-flip" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
            <svg class="vs-icon-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
          </button>
          <span class="vs-progress" aria-hidden="true"><span></span></span>
          <span class="vs-time" dir="ltr" data-vp-time>{{ $vsDuration((int) $dialogue['duration']) }}</span>
        </footer>
      </article>
    @endforeach
  </div>
</section>
@endif
