{{-- Homepage for the new site design (Listen 2.0, Home board). Rendered instead of the
     main design's <main> when SiteDesign::isNew(). Uses real samples and plans. --}}
@php
  $ntDialectKeys = ['XA' => 'msa', 'SA' => 'saudi', 'EG' => 'egyptian', 'LB' => 'lebanese', 'MA' => 'moroccan', 'AE' => 'emirati',
    'KW' => 'kuwaiti', 'JO' => 'jordanian', 'SY' => 'syrian', 'IQ' => 'iraqi', 'TN' => 'tunisian', 'OM' => 'omani',
    'US' => 'american', 'GB' => 'british', 'AU' => 'australian', 'IN' => 'indian', 'ZA' => 'south_african'];
  $ntDialectLabel = function (array $sample) use ($ntDialectKeys): string {
    $region = strtoupper((string) \Illuminate\Support\Str::afterLast((string) ($sample['dialect'] ?? ''), '-'));
    $key = $ntDialectKeys[$region] ?? null;
    if ($key === 'msa') {
      return __('design.home.fusha');
    }
    if ($key && \Illuminate\Support\Facades\Lang::has('ui.dialects.'.$key)) {
      return __('ui.dialects.'.$key);
    }
    return (string) ($sample['title'] ?? '');
  };
  $ntWave = function (int $count, int $max): array {
    $bars = [];
    for ($i = 0; $i < $count; $i++) {
      $bars[] = (int) round(6 + abs(sin($i * 1.37) * cos($i * 0.41)) * ($max - 6));
    }
    return $bars;
  };

  $ntDialectSamples = $ntDialectSamples ?? [];
  if (empty($ntDialectSamples) && !empty($heroVoiceSample)) {
    $ntDialectSamples = [$heroVoiceSample];
  }
  $ntFirst = $ntDialectSamples[0] ?? null;
  $ntLibrary = $ntLibrary ?? ['ar' => [], 'en' => []];
  $ntLibraryRows = array_merge(
    array_map(fn ($s) => $s + ['nt_lang' => 'ar'], $ntLibrary['ar'] ?? []),
    array_map(fn ($s) => $s + ['nt_lang' => 'en'], $ntLibrary['en'] ?? [])
  );
  $ntIsArabicUi = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  if (!$ntIsArabicUi) {
    $ntLibraryRows = array_merge(
      array_map(fn ($s) => $s + ['nt_lang' => 'en'], $ntLibrary['en'] ?? []),
      array_map(fn ($s) => $s + ['nt_lang' => 'ar'], $ntLibrary['ar'] ?? [])
    );
  }
  $ntFreeChars = (int) (($ntFreePlan ?? [])['monthly_chars'] ?? 0);
  $ntPrimaryHref = auth()->check() ? route('dashboard') : route('register');
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
    <div class="nt-card nt-try" data-nt-try>
      <div class="nt-try-head">
        <span class="nt-try-title">{{ __('design.home.try_title') }}</span>
        @if(count($ntDialectSamples) > 1)
          <span class="nt-small">{{ __('design.home.try_count', ['count' => count($ntDialectSamples)]) }}</span>
        @endif
      </div>
      <p class="nt-try-text" dir="auto" data-nt-try-text>{{ \Illuminate\Support\Str::limit((string) ($ntFirst['text'] ?? ''), 140) }}</p>
      @if(count($ntDialectSamples) > 1)
      <div class="nt-chips" role="radiogroup" aria-label="{{ __('design.home.try_dialect') }}">
        @foreach($ntDialectSamples as $i => $sample)
          <button type="button" class="nt-chip" role="radio" aria-checked="{{ $i === 0 ? 'true' : 'false' }}"
            data-nt-chip
            data-src="{{ $sample['audio_url'] }}"
            data-text="{{ \Illuminate\Support\Str::limit((string) ($sample['text'] ?? ''), 140) }}"
            data-voice="{{ trim(($sample['voice_label'] ?? '').' · '.$ntDialectLabel($sample), ' ·') }}">{{ $ntDialectLabel($sample) }}</button>
        @endforeach
      </div>
      @endif
      <div class="nt-player" data-nt-track data-src="{{ $ntFirst['audio_url'] }}">
        <button type="button" class="nt-play nt-play-lg" data-nt-play aria-label="{{ __('design.home.play') }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
          <svg class="nt-icon-play nt-flip" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
          <svg class="nt-icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
        </button>
        <div class="nt-wave" aria-hidden="true">
          @foreach($ntWave(48, 34) as $h)<span style="height: {{ $h }}px"></span>@endforeach
        </div>
        <div class="nt-player-meta">
          <div class="nt-player-voice" data-nt-try-voice>{{ trim(($ntFirst['voice_label'] ?? '').' · '.$ntDialectLabel($ntFirst), ' ·') }}</div>
          <div class="nt-mono" data-nt-time>0:00</div>
        </div>
      </div>
      <a class="nt-link nt-try-write" href="{{ route('demo.page') }}">{{ __('design.home.try_write') }}</a>
    </div>
    @endif
  </section>

  {{-- One script, every dialect --}}
  @if(count($ntDialectSamples) > 1)
  <section class="nt-home-strip nt-wrap">
    <div class="nt-strip-copy">
      <h2 class="nt-h2">{{ __('design.home.strip_title') }}</h2>
      <p class="nt-lead">{{ __('design.home.strip_body') }}</p>
      <a class="nt-link" href="{{ auth()->check() ? route('dashboard').'#generateVoiceSection' : route('demo.page') }}">{{ __('design.home.strip_link') }}</a>
    </div>
    <div class="nt-card nt-strip-list">
      @foreach($ntDialectSamples as $i => $sample)
        <div class="nt-strip-row" data-nt-track data-src="{{ $sample['audio_url'] }}">
          <span class="nt-strip-tag">{{ $ntDialectLabel($sample) }}</span>
          <span class="nt-strip-line" dir="rtl" lang="ar">{{ \Illuminate\Support\Str::limit((string) ($sample['text'] ?? ''), 64) }}</span>
          <button type="button" class="nt-play nt-play-soft" data-nt-play aria-label="{{ __('design.home.play') }}: {{ $ntDialectLabel($sample) }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
            <svg class="nt-icon-play" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
            <svg class="nt-icon-pause" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
          </button>
        </div>
      @endforeach
    </div>
  </section>
  @endif

  {{-- Sample library --}}
  @if(!empty($ntLibraryRows))
  <section class="nt-home-library" id="samples">
    <div class="nt-wrap">
      <div class="nt-section-head">
        <div>
          <h2 class="nt-h2">{{ __('design.home.lib_title') }}</h2>
          <p class="nt-lead">{{ __('design.home.lib_body') }}</p>
        </div>
        @if(!empty($ntLibrary['ar']) && !empty($ntLibrary['en']))
        <div class="nt-chips" role="tablist" aria-label="{{ __('design.home.lib_filter') }}">
          <button type="button" class="nt-chip" role="tab" aria-selected="true" data-nt-filter="all">{{ __('design.home.filter_all') }}</button>
          <button type="button" class="nt-chip" role="tab" aria-selected="false" data-nt-filter="ar">{{ __('design.home.filter_ar') }}</button>
          <button type="button" class="nt-chip" role="tab" aria-selected="false" data-nt-filter="en">{{ __('design.home.filter_en') }}</button>
        </div>
        @endif
      </div>
      <div class="nt-library">
        @foreach($ntLibraryRows as $sample)
          <div class="nt-library-row" data-nt-track data-src="{{ $sample['audio_url'] }}" data-lang="{{ $sample['nt_lang'] }}">
            <button type="button" class="nt-play nt-play-soft" data-nt-play aria-label="{{ __('design.home.play') }}: {{ $sample['title'] }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
              <svg class="nt-icon-play nt-flip" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
              <svg class="nt-icon-pause" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
            </button>
            <span class="nt-library-text">
              <span class="nt-library-title">{{ $sample['title'] }}</span>
              <span class="nt-library-meta">{{ $sample['description'] ?: ($sample['voice_label'] ?? '') }}</span>
            </span>
            <span class="nt-pill">{{ $sample['nt_lang'] === 'ar' ? __('design.home.filter_ar') : __('design.home.filter_en') }}</span>
          </div>
        @endforeach
      </div>
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

<script>
(function () {
  var root = document.getElementById('ntHome');
  if (!root) return;
  var audio = new Audio();
  audio.preload = 'none';
  var current = null;

  function fmt(t) {
    if (!isFinite(t) || t < 0) t = 0;
    var m = Math.floor(t / 60), s = Math.floor(t % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function setState(track, playing) {
    if (!track) return;
    track.classList.toggle('is-playing', playing);
    var btn = track.querySelector('[data-nt-play]');
    if (btn) {
      btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
      var label = playing ? btn.getAttribute('data-label-pause') : btn.getAttribute('data-label-play');
      if (label) btn.setAttribute('aria-label', label);
    }
  }
  function paintWave(track, ratio) {
    var bars = track ? track.querySelectorAll('.nt-wave span') : [];
    var lit = Math.round(bars.length * ratio);
    for (var i = 0; i < bars.length; i++) bars[i].classList.toggle('is-lit', i < lit);
    var time = track ? track.querySelector('[data-nt-time]') : null;
    if (time) time.textContent = fmt(audio.currentTime) + (isFinite(audio.duration) ? ' / ' + fmt(audio.duration) : '');
  }
  function stop() {
    if (!current) return;
    setState(current, false);
    current = null;
  }
  function play(track) {
    var src = track.getAttribute('data-src');
    if (!src) return;
    if (current === track && !audio.paused) { audio.pause(); return; }
    if (current !== track) {
      stop();
      paintWave(track, 0);
      audio.src = src;
    }
    current = track;
    var p = audio.play();
    if (p && p.catch) p.catch(function () { setState(track, false); });
    setState(track, true);
  }

  // Pause any other player on the page (e.g. the shared audio widgets) while ours plays.
  audio.addEventListener('play', function () {
    document.querySelectorAll('audio').forEach(function (el) { if (el !== audio && !el.paused) el.pause(); });
  });
  audio.addEventListener('pause', function () { if (current) setState(current, false); });
  audio.addEventListener('ended', function () { if (current) { paintWave(current, 1); setState(current, false); } });
  audio.addEventListener('timeupdate', function () {
    if (current && isFinite(audio.duration) && audio.duration > 0) paintWave(current, audio.currentTime / audio.duration);
  });

  root.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-nt-play]');
    if (btn && root.contains(btn)) {
      play(btn.closest('[data-nt-track]'));
      return;
    }
    var chip = event.target.closest('[data-nt-chip]');
    if (chip) {
      var card = chip.closest('[data-nt-try]');
      var track = card.querySelector('[data-nt-track]');
      card.querySelectorAll('[data-nt-chip]').forEach(function (c) { c.setAttribute('aria-checked', c === chip ? 'true' : 'false'); });
      var text = card.querySelector('[data-nt-try-text]');
      var voice = card.querySelector('[data-nt-try-voice]');
      if (text) text.textContent = chip.getAttribute('data-text') || '';
      if (voice) voice.textContent = chip.getAttribute('data-voice') || '';
      var wasPlaying = current === track && !audio.paused;
      if (current === track) { audio.pause(); stop(); }
      track.setAttribute('data-src', chip.getAttribute('data-src') || '');
      paintWave(track, 0);
      if (wasPlaying) play(track);
      return;
    }
    var filter = event.target.closest('[data-nt-filter]');
    if (filter) {
      var value = filter.getAttribute('data-nt-filter');
      filter.parentElement.querySelectorAll('[data-nt-filter]').forEach(function (f) { f.setAttribute('aria-selected', f === filter ? 'true' : 'false'); });
      root.querySelectorAll('.nt-library-row').forEach(function (row) {
        row.hidden = value !== 'all' && row.getAttribute('data-lang') !== value;
      });
    }
  });
})();
</script>
