{{-- Hero player: pick a language and a dialect or accent, hear it, and read along. --}}
@php
  $vsPicker = $showcasePicker ?? ['ar' => [], 'en' => []];
  $vsArabicUi = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $vsLangs = array_values(array_filter($vsArabicUi ? ['ar', 'en'] : ['en', 'ar'], fn ($l) => !empty($vsPicker[$l])));
  $vsStart = $vsLangs[0] ?? null;
  $vsFirst = $vsStart ? $vsPicker[$vsStart][0] : null;
  $vsInitial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '•', 0, 1));
  $vsDuration = fn (int $s): string => $s > 0 ? intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) : '0:00';
  $vsBars = [];
  for ($i = 0; $i < 52; $i++) {
    $vsBars[] = (int) round(6 + abs(sin($i * 1.37) * cos($i * 0.41)) * 26);
  }
@endphp
@if($vsFirst)
<div class="vs-studio" data-vp-try>
  <div class="vs-studio-head">
    <span class="vs-model-badge"><span class="vs-model-dot" aria-hidden="true"></span>{{ __('ui.landing.showcase_model_badge') }}</span>
    @if(count($vsLangs) > 1)
    <div class="vs-seg" role="tablist" aria-label="{{ __('ui.landing.showcase_languages') }}">
      @foreach($vsLangs as $lang)
        <button type="button" class="vs-seg-btn" role="tab" aria-selected="{{ $lang === $vsStart ? 'true' : 'false' }}" data-vp-lang="{{ $lang }}">{{ __('ui.landing.showcase_tab_'.$lang) }}</button>
      @endforeach
    </div>
    @endif
  </div>

  <p class="vs-studio-text" dir="{{ $vsFirst['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $vsFirst['language'] }}" data-vp-try-text data-vp-words>{{ trim($vsFirst['text'].' '.$vsFirst['sign_off']) }}</p>

  @foreach($vsLangs as $lang)
  <div class="vs-flag-rail" role="radiogroup" aria-label="{{ __('ui.landing.showcase_picker') }}" data-vp-rail="{{ $lang }}" @if($lang !== $vsStart) hidden @endif>
    @foreach($vsPicker[$lang] as $i => $sample)
      <button type="button" class="vs-flag-chip" role="radio" aria-checked="{{ $lang === $vsStart && $i === 0 ? 'true' : 'false' }}"
        data-vp-chip
        data-src="{{ $sample['audio_url'] }}"
        data-text="{{ trim($sample['text'].' '.$sample['sign_off']) }}"
        data-lang="{{ $sample['language'] }}"
        data-voice="{{ $sample['speaker'] }}"
        data-dialect="{{ $sample['dialect_label'] }}"
        data-initial="{{ $vsInitial($sample['speaker']) }}">
        <x-home.flag :url="$sample['flag']" />
        <span>{{ $sample['dialect_label'] }}</span>
      </button>
    @endforeach
  </div>
  @endforeach

  <div class="vs-player" data-vp-track data-src="{{ $vsFirst['audio_url'] }}">
    <button type="button" class="vs-play vs-play-lg" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
      <svg class="vs-icon-play vs-flip" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
      <svg class="vs-icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
    </button>
    <div class="vp-wave auth-wave-bars" aria-hidden="true">
      @foreach($vsBars as $h)<span style="height: {{ $h }}px"></span>@endforeach
    </div>
    <div class="vs-time" dir="ltr" data-vp-time>{{ $vsDuration((int) $vsFirst['duration']) }}</div>
  </div>

  <div class="vs-studio-foot">
    <div class="vs-speaker">
      <span class="vs-avatar" data-vp-try-initial aria-hidden="true">{{ $vsInitial($vsFirst['speaker']) }}</span>
      <span class="vs-speaker-text">
        <span class="vs-speaker-name" data-vp-try-voice>{{ $vsFirst['speaker'] }}</span>
        <span class="vs-speaker-meta"><span data-vp-try-flag><x-home.flag :url="$vsFirst['flag']" size="xs" /></span><span data-vp-try-dialect>{{ $vsFirst['dialect_label'] }}</span></span>
      </span>
    </div>
    <a class="vs-link" href="{{ auth()->check() ? route('dashboard').'#generateVoiceSection' : route('demo.page') }}">{{ __('ui.landing.showcase_write') }}</a>
  </div>
</div>
@endif
