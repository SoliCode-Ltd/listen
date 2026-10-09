{{-- Homepage for the new site design (Listen 2.0, Home board). Rendered instead of the
     main design's <main> when SiteDesign::isNew(). Uses real samples and plans. --}}
@php
  $ntWave = function (int $count, int $max): array {
    $bars = [];
    for ($i = 0; $i < $count; $i++) {
      $bars[] = (int) round(6 + abs(sin($i * 1.37) * cos($i * 0.41)) * ($max - 6));
    }
    return $bars;
  };
  $ntDuration = fn (int $s): string => $s > 0 ? intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) : '';
  $ntInitial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '•', 0, 1));

  $ntVoices = $ntVoices ?? ['ar' => [], 'en' => []];
  $ntPicker = $ntPicker ?? ['ar' => [], 'en' => []];
  $ntDialogues = $ntDialogues ?? [];
  $ntIsArabicUi = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $ntLangs = $ntIsArabicUi ? ['ar', 'en'] : ['en', 'ar'];
  $ntLangs = array_values(array_filter($ntLangs, fn ($l) => !empty($ntPicker[$l])));
  $ntStartLang = $ntLangs[0] ?? null;
  $ntFirst = $ntStartLang ? $ntPicker[$ntStartLang][0] : null;
  $ntCountAr = count(array_unique(array_column($ntVoices['ar'], 'dialect')));
  $ntCountEn = count(array_unique(array_column($ntVoices['en'], 'dialect')));
  $ntFreeChars = (int) (($ntFreePlan ?? [])['monthly_chars'] ?? 0);
  $ntPrimaryHref = auth()->check() ? route('dashboard') : route('register');
  $ntStudioHref = auth()->check() ? route('dashboard').'#generateVoiceSection' : route('demo.page');
@endphp

@php
  // Round flag, or the Arabic letter for voices without one country (Fusha, Arabic-accent English).
  $ntFlag = function (?string $url, string $size = '') {
    $class = 'nt-flag'.($size !== '' ? ' nt-flag-'.$size : '');
    return $url
      ? new \Illuminate\Support\HtmlString('<span class="'.$class.'"><img src="'.e($url).'" alt="" width="24" height="24" loading="lazy" decoding="async"></span>')
      : new \Illuminate\Support\HtmlString('<span class="'.$class.' nt-flag-ar" aria-hidden="true">ع</span>');
  };
@endphp

<main class="nt-home" id="ntHome">

  {{-- Hero --}}
  <section class="nt-home-hero nt-wrap">
    <div class="nt-hero-copy">
      <span class="nt-eyebrow">{{ __('design.home.eyebrow') }}</span>
      <h1 class="nt-h1">{{ __('design.home.h1_line1') }}<br>{{ __('design.home.h1_line2') }}</h1>
      <p class="nt-lead">{{ __('design.home.lead') }}</p>
      <div class="nt-actions">
        <a class="btn btn-primary" href="{{ $ntPrimaryHref }}">{{ auth()->check() ? __('design.home.cta_signed_in') : __('design.home.cta') }}</a>
        <a class="btn" href="#samples">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="nt-flip"><path d="M7 4l13 8-13 8z"/></svg>
          {{ __('design.home.cta2') }}
        </a>
      </div>
      @if($ntFreeChars > 0)
        <p class="nt-small">{{ __('design.home.trust_free', ['chars' => number_format($ntFreeChars)]) }}</p>
      @endif
    </div>

    @if($ntFirst)
    <div class="nt-card nt-try nt-studio" data-nt-try>
      <div class="nt-try-head">
        <span class="nt-model-badge"><span class="nt-model-dot" aria-hidden="true"></span>{{ __('design.home.model_badge') }}</span>
        @if(count($ntLangs) > 1)
        <div class="nt-seg" role="tablist" aria-label="{{ __('design.home.gallery_filter') }}">
          @foreach($ntLangs as $lang)
            <button type="button" class="nt-seg-btn" role="tab" aria-selected="{{ $lang === $ntStartLang ? 'true' : 'false' }}" data-nt-lang="{{ $lang }}">{{ __('design.home.tab_'.$lang) }}</button>
          @endforeach
        </div>
        @endif
      </div>

      <p class="nt-try-text" dir="{{ $ntFirst['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $ntFirst['language'] }}" data-nt-try-text data-nt-words>{{ trim($ntFirst['text'].' '.$ntFirst['sign_off']) }}</p>

      @foreach($ntLangs as $lang)
      <div class="nt-flag-rail" role="radiogroup" aria-label="{{ __('design.home.picker_label') }}" data-nt-rail="{{ $lang }}" @if($lang !== $ntStartLang) hidden @endif>
        @foreach($ntPicker[$lang] as $i => $sample)
          <button type="button" class="nt-flag-chip" role="radio" aria-checked="{{ $lang === $ntStartLang && $i === 0 ? 'true' : 'false' }}"
            data-nt-chip
            data-src="{{ $sample['audio_url'] }}"
            data-text="{{ trim($sample['text'].' '.$sample['sign_off']) }}"
            data-lang="{{ $sample['language'] }}"
            data-voice="{{ $sample['speaker'] }}"
            data-dialect="{{ $sample['dialect_label'] }}"
            data-initial="{{ $ntInitial($sample['speaker']) }}"
            data-flag="{{ $sample['flag'] ?? '' }}">
            {{ $ntFlag($sample['flag']) }}
            <span>{{ $sample['dialect_label'] }}</span>
          </button>
        @endforeach
      </div>
      @endforeach

      <div class="nt-player nt-player-hero" data-nt-track data-src="{{ $ntFirst['audio_url'] }}">
        <button type="button" class="nt-play nt-play-lg" data-nt-play aria-label="{{ __('design.home.play') }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
          <svg class="nt-icon-play nt-flip" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
          <svg class="nt-icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
        </button>
        <div class="nt-wave auth-wave-bars" aria-hidden="true">
          @foreach($ntWave(52, 32) as $h)<span style="height: {{ $h }}px"></span>@endforeach
        </div>
        <div class="nt-mono nt-player-time" dir="ltr" data-nt-time>{{ $ntDuration($ntFirst['duration']) ?: '0:00' }}</div>
      </div>

      <div class="nt-studio-foot">
        <div class="nt-speaker">
          <span class="nt-avatar" data-nt-try-initial aria-hidden="true">{{ $ntInitial($ntFirst['speaker']) }}</span>
          <span class="nt-speaker-text">
            <span class="nt-speaker-name" data-nt-try-voice>{{ $ntFirst['speaker'] }}</span>
            <span class="nt-speaker-meta"><span data-nt-try-flag>{{ $ntFlag($ntFirst['flag'], 'xs') }}</span><span data-nt-try-dialect>{{ $ntFirst['dialect_label'] }}</span></span>
          </span>
        </div>
        <a class="nt-link nt-try-write" href="{{ route('demo.page') }}">{{ __('design.home.try_write') }}</a>
      </div>
    </div>
    @endif
  </section>

  {{-- Voice gallery: every dialect and accent --}}
  @if(!empty($ntVoices['ar']) || !empty($ntVoices['en']))
  <section class="nt-home-gallery" id="samples">
    <div class="nt-wrap">
      <div class="nt-section-head">
        <div>
          <h2 class="nt-h2">{{ __('design.home.gallery_title') }}</h2>
          <p class="nt-lead">{{ __('design.home.gallery_body', ['ar' => $ntCountAr, 'en' => $ntCountEn]) }}</p>
        </div>
        @if(count($ntLangs) > 1)
        <div class="nt-seg" role="tablist" aria-label="{{ __('design.home.gallery_filter') }}">
          @foreach($ntLangs as $lang)
            <button type="button" class="nt-seg-btn" role="tab" aria-selected="{{ $lang === $ntStartLang ? 'true' : 'false' }}" data-nt-gallery="{{ $lang }}">
              {{ __('design.home.gallery_tab_'.$lang) }} <span class="nt-seg-count">{{ $lang === 'ar' ? $ntCountAr : $ntCountEn }}</span>
            </button>
          @endforeach
        </div>
        @endif
      </div>
      <div class="nt-voice-grid">
        @foreach(array_merge($ntVoices[$ntLangs[0] ?? 'ar'] ?? [], $ntVoices[$ntLangs[1] ?? 'en'] ?? []) as $sample)
          <article class="nt-voice-card" data-nt-track data-src="{{ $sample['audio_url'] }}" data-lang="{{ $sample['language'] }}" @if($sample['language'] !== $ntStartLang) hidden @endif>
            <header class="nt-voice-head">
              {{ $ntFlag($sample['flag'], 'lg') }}
              <span class="nt-voice-id">
                <span class="nt-voice-dialect">{{ $sample['dialect_label'] }}</span>
                <span class="nt-voice-speaker">{{ $sample['speaker'] }}</span>
              </span>
            </header>
            <h3 class="nt-voice-title">{{ $sample['title'] }}</h3>
            <p class="nt-voice-text" dir="{{ $sample['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $sample['language'] }}">{{ $sample['text'] }}</p>
            <footer class="nt-voice-foot">
              <button type="button" class="nt-play nt-play-soft" data-nt-play aria-label="{{ __('design.home.play') }}: {{ $sample['dialect_label'] }}, {{ $sample['title'] }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
                <svg class="nt-icon-play nt-flip" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
                <svg class="nt-icon-pause" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
              </button>
              <span class="nt-progress" aria-hidden="true"><span></span></span>
              <span class="nt-mono nt-voice-time" dir="ltr" data-nt-time>{{ $ntDuration($sample['duration']) }}</span>
            </footer>
          </article>
        @endforeach
      </div>
      <p class="nt-small nt-signature">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        {{ __('design.home.signature') }}
      </p>
    </div>
  </section>
  @endif

  {{-- Dialogues: two voices in one take --}}
  @if(!empty($ntDialogues))
  <section class="nt-home-dialogue nt-wrap">
    <div class="nt-section-head">
      <div>
        <span class="nt-pill">{{ __('design.home.dlg_badge') }}</span>
        <h2 class="nt-h2">{{ __('design.home.dlg_title') }}</h2>
        <p class="nt-lead">{{ __('design.home.dlg_body') }}</p>
      </div>
      <a class="nt-link" href="{{ $ntStudioHref }}">{{ __('design.home.dlg_cta') }}</a>
    </div>
    <div class="nt-dialogue-grid">
      @foreach($ntDialogues as $dialogue)
        @php $ntPair = $dialogue['speakers']; @endphp
        <article class="nt-dialogue" data-nt-track data-nt-dialogue data-src="{{ $dialogue['audio_url'] }}">
          <header class="nt-dialogue-head">
            <span class="nt-avatar-pair" aria-hidden="true">
              @foreach(array_slice($ntPair, 0, 2) as $si => $speaker)
                <span class="nt-avatar nt-avatar-{{ $si }}">{{ $ntInitial($speaker['name']) }}</span>
              @endforeach
            </span>
            <span class="nt-dialogue-id">
              <span class="nt-dialogue-title">{{ $dialogue['title'] }}</span>
              <span class="nt-dialogue-meta">
                {{ $ntFlag($dialogue['flag'], 'xs') }}
                {{ __('design.home.dlg_speakers', ['a' => $ntPair[0]['name'] ?? '', 'b' => $ntPair[1]['name'] ?? '']) }} · {{ $dialogue['dialect_label'] }}
              </span>
            </span>
          </header>
          <ol class="nt-turns" dir="{{ $dialogue['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $dialogue['language'] }}">
            @foreach($dialogue['turns'] as $turn)
              @php $ntTurnText = trim((string) preg_replace('/\s*<[^<>\n]{1,24}>\s*/u', ' ', $turn['text'])); @endphp
              <li class="nt-turn nt-turn-{{ $turn['index'] % 2 }}" data-nt-turn data-weight="{{ mb_strlen($ntTurnText) }}">
                <span class="nt-turn-who">{{ $turn['speaker'] }}</span>
                <span class="nt-turn-text">{{ $ntTurnText }}</span>
              </li>
            @endforeach
            @if($dialogue['sign_off'] !== '')
              <li class="nt-turn nt-turn-sign" data-nt-turn data-weight="{{ mb_strlen($dialogue['sign_off']) }}">
                <span class="nt-turn-text">{{ $dialogue['sign_off'] }}</span>
              </li>
            @endif
          </ol>
          <footer class="nt-voice-foot">
            <button type="button" class="nt-play" data-nt-play aria-label="{{ __('design.home.play') }}: {{ $dialogue['title'] }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
              <svg class="nt-icon-play nt-flip" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
              <svg class="nt-icon-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
            </button>
            <span class="nt-progress" aria-hidden="true"><span></span></span>
            <span class="nt-mono nt-voice-time" dir="ltr" data-nt-time>{{ $ntDuration($dialogue['duration']) }}</span>
          </footer>
        </article>
      @endforeach
    </div>
  </section>
  @endif

  {{-- Voice cloning --}}
  <section class="nt-home-clone nt-wrap">
    <div class="nt-clone-copy">
      <span class="nt-pill nt-pill-ok">{{ __('design.home.clone_badge') }}</span>
      <h2 class="nt-h2">{{ __('design.home.clone_title') }}</h2>
      <p class="nt-lead">{{ __('design.home.clone_body') }}</p>
      <ol class="nt-steps">
        <li><span class="nt-step-num">1</span><span>{{ __('design.home.clone_step1') }}</span></li>
        <li><span class="nt-step-num">2</span><span>{{ __('design.home.clone_step2') }}</span></li>
        <li><span class="nt-step-num">3</span><span>{{ __('design.home.clone_step3') }}</span></li>
      </ol>
    </div>
    <div class="nt-card nt-clone-card">
      <div class="nt-clone-card-head">
        <span class="nt-clone-card-name">{{ __('design.home.clone_card_name') }}</span>
        <span class="nt-pill nt-pill-ok">{{ __('design.home.clone_ready') }}</span>
      </div>
      <p class="nt-small">{{ __('design.home.clone_card_meta') }}</p>
      <div class="nt-wave nt-wave-full" aria-hidden="true">
        @foreach($ntWave(64, 38) as $h)<span style="height: {{ $h }}px"></span>@endforeach
      </div>
      <div class="nt-actions">
        <a class="btn btn-primary" href="{{ route('pricing') }}">{{ __('design.home.clone_cta') }}</a>
      </div>
      <p class="nt-small">{{ __('design.home.clone_consent') }}</p>
    </div>
  </section>

  {{-- Pricing teaser --}}
  @if(!empty($ntTeaserPlans))
  <section class="nt-home-pricing">
    <div class="nt-wrap">
      <div class="nt-section-head">
        <h2 class="nt-h2">{{ __('design.home.price_title') }}</h2>
        <a class="nt-link" href="{{ route('pricing') }}">{{ __('design.home.price_all') }}</a>
      </div>
      <div class="nt-plan-grid">
        @foreach($ntTeaserPlans as $plan)
          @php $ntPlanFree = (int) ($plan['price_monthly_cents'] ?? 0) === 0; @endphp
          <div class="nt-plan {{ !empty($plan['is_popular']) ? 'is-featured' : '' }}">
            <div class="nt-plan-head">
              <span class="nt-plan-title">{{ $plan['name'] }}</span>
              @if(!empty($plan['is_popular']))<span class="nt-pill">{{ __('design.home.badge') }}</span>@endif
            </div>
            <div class="nt-plan-price">{{ $plan['display_price_monthly_formatted'] }}<span>{{ __('design.home.per_month') }}</span></div>
            <div class="nt-plan-muted">{{ __('design.home.chars_month', ['chars' => number_format((int) $plan['monthly_chars'])]) }}</div>
            @if(!empty($plan['description']))<div class="nt-plan-muted">{{ $plan['description'] }}</div>@endif
            @if($ntPlanFree)
              <a class="btn" href="{{ $ntPrimaryHref }}">{{ __('design.home.start_free') }}</a>
            @else
              <a class="btn {{ !empty($plan['is_popular']) ? 'btn-primary' : '' }}" href="{{ route('pricing') }}">{{ __('design.home.choose', ['plan' => $plan['name']]) }}</a>
            @endif
          </div>
        @endforeach
      </div>
    </div>
  </section>
  @endif
</main>

@include('components.design-new.player-script')
