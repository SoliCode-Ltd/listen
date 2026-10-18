@php
  $voiceShowcaseCss = public_path('css/voice-showcase.css');
  $isArabicUi = app()->getLocale() === 'ar';
  $regionOrder = ['fusha', 'gulf', 'levant', 'nile', 'iraq_yemen', 'maghreb', 'americas', 'uk_ie', 'africa', 'asia_pacific', 'other'];
  // The regions present in a list of cards, in the order the homepage uses.
  $regionsOf = static function (array $cards) use ($regionOrder): array {
    $regions = array_values(array_unique(array_column($cards, 'region')));
    usort($regions, fn ($a, $b) => array_search($a, $regionOrder, true) <=> array_search($b, $regionOrder, true));
    return $regions;
  };
  // Moods with the most samples first; the first is open when the page loads.
  $moodCounts = array_count_values(array_column($moodVoices, 'emotion'));
  arsort($moodCounts);
  $moods = array_keys($moodCounts);
  $firstMood = $moods[0] ?? '';
  $moodLabel = static function (string $mood): string {
    $key = 'ui.samples.moods.'.$mood;
    return __($key) !== $key ? __($key) : ucfirst($mood);
  };
  // Each page holds its own language: Arabic dialects on the Arabic page, English accents on the English one.
  $voiceSection = $isArabicUi ? ['id' => 'arabic-dialects', 'copy' => 'arabic'] : ['id' => 'english-accents', 'copy' => 'english'];
  $sections = array_values(array_filter([
    $voices !== [] ? ['id' => $voiceSection['id'], 'label' => __('ui.samples.'.$voiceSection['copy'].'_title'), 'count' => count($voices)] : null,
    $moods !== [] ? ['id' => 'moods', 'label' => __('ui.samples.moods_title'), 'count' => count($moods)] : null,
    $dialogues !== [] ? ['id' => 'conversations', 'label' => __('ui.samples.conversations_title'), 'count' => count($dialogues)] : null,
    $environments !== [] ? ['id' => 'sound-environments', 'label' => __('ui.samples.environments_title'), 'count' => count($environments)] : null,
  ]));
  $playIcon = '<svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.02 4.29A1 1 0 0 0 7.5 5.14z"/></svg>';
  $pauseIcon = '<svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.3"/><rect x="14" y="5" width="4" height="14" rx="1.3"/></svg>';
@endphp
@include('components.page-head', ['pageTitle' => __('ui.seo.samples_title'), 'page' => 'samples'])
  <link rel="stylesheet" href="{{ asset('css/voice-showcase.css') }}?v={{ is_file($voiceShowcaseCss) ? filemtime($voiceShowcaseCss) : '1' }}">
  <style>
    /* ── Voice samples ──────────────────────────────────────────────
       The voice is the hero: a dark stage where the spoken line lights up word
       by word, then every sample, sorted five ways. */
    .samples-page { --stage-ink: #120D2E; --stage-lilac: #C9BEFF; }

    /* The stage */
    .stage {
      /* Dark theme: an ink stage with light words. */
      --st-text: #FFFFFF;
      --st-soft: rgba(255, 255, 255, .78);
      --st-faint: rgba(255, 255, 255, .62);
      --st-title: var(--stage-lilac);
      --st-lit: #FFFFFF;
      --st-now: var(--stage-lilac);
      --st-dim: rgba(255, 255, 255, .3);
      --st-btn-line: rgba(255, 255, 255, .22);
      --st-btn-bg: rgba(255, 255, 255, .06);
      --st-btn-hover: rgba(255, 255, 255, .14);
      --st-main-bg: #FFFFFF;
      --st-main-fg: #3F27C4;
      --st-main-hover: #F1EEFF;
      --st-track: rgba(255, 255, 255, .12);
      --st-bar-from: var(--stage-lilac);
      --st-bar-to: #FFFFFF;
      --st-focus: #FFFFFF;
      --st-avatar-ring: rgba(255, 255, 255, .14);
      --st-pick-gap: var(--stage-ink);
      --st-bars: rgba(255, 255, 255, .055);
      /* The ring's colour, as r, g, b for the canvas. */
      --st-ring: 201, 190, 255;
      position: relative;
      isolation: isolate;
      overflow: hidden;
      color: var(--st-text);
      background:
        radial-gradient(60% 80% at 85% 10%, rgba(118, 89, 250, .42), transparent 60%),
        radial-gradient(50% 70% at 5% 100%, rgba(61, 139, 255, .22), transparent 60%),
        var(--stage-ink);
    }
    /* Light theme: the launch video's look, dark words on warm light with soft colour glows. */
    html[data-theme="light"] .stage {
      --st-text: #15151C;
      --st-soft: #4B4F5C;
      --st-faint: #5F6673;
      --st-title: #6247EE;
      --st-lit: #15151C;
      --st-now: #6247EE;
      --st-dim: rgba(40, 32, 90, .28);
      --st-btn-line: rgba(21, 21, 28, .14);
      --st-btn-bg: rgba(255, 255, 255, .7);
      --st-btn-hover: #FFFFFF;
      --st-main-bg: #7659FA;
      --st-main-fg: #FFFFFF;
      --st-main-hover: #6247EE;
      --st-track: rgba(21, 21, 28, .1);
      --st-bar-from: #B4A3FF;
      --st-bar-to: #6247EE;
      --st-focus: #6247EE;
      --st-avatar-ring: #FFFFFF;
      --st-pick-gap: #F7F5F1;
      --st-bars: rgba(118, 89, 250, .035);
      --st-ring: 118, 89, 250;
      background:
        radial-gradient(40% 75% at 6% 8%, rgba(255, 214, 194, .8), transparent 70%),
        radial-gradient(42% 80% at 96% 18%, rgba(206, 196, 255, .9), transparent 70%),
        radial-gradient(45% 70% at 22% 100%, rgba(196, 228, 255, .7), transparent 70%);
    }
    /* A faint field of sound bars behind the words. */
    .stage::before {
      content: "";
      position: absolute;
      inset: auto 0 0 0;
      height: 55%;
      z-index: -1;
      background: repeating-linear-gradient(90deg, var(--st-bars) 0 3px, transparent 3px 14px);
      -webkit-mask-image: linear-gradient(to top, #000, transparent);
      mask-image: linear-gradient(to top, #000, transparent);
    }
    .stage-wrap {
      width: min(1200px, 92%);
      margin: 0 auto;
      padding: clamp(2.5rem, 6vw, 4.5rem) 0 clamp(1.75rem, 4vw, 2.5rem);
      display: grid;
      gap: clamp(1.75rem, 4vw, 2.75rem);
    }
    .stage-head { text-align: center; }
    .stage-head h1 {
      margin: 0 0 .5rem;
      color: var(--st-title);
      font-size: clamp(1.15rem, 2vw, 1.35rem);
      font-weight: 700;
      letter-spacing: 0;
    }
    .stage-head p { margin: 0 auto; max-width: 56ch; color: var(--st-soft); font-size: 1rem; line-height: 1.6; text-wrap: balance; }
    .stage-line { margin: 0; min-height: clamp(9rem, 16vw, 14.5rem); display: grid; align-items: center; font-size: clamp(1.75rem, 3.6vw, 3.1rem); }
    .stage-line[data-size="m"] { font-size: clamp(1.45rem, 2.7vw, 2.35rem); }
    .stage-line[data-size="l"] { font-size: clamp(1.3rem, 2.3vw, 2rem); }
    .stage-line[data-size="m"] .stage-text, .stage-line[data-size="l"] .stage-text { max-width: 36ch; }
    .stage-text {
      margin: 0 auto;
      max-width: 28ch;
      text-align: center;
      font-weight: 700;
      line-height: 1.45;
      letter-spacing: -.01em;
      text-wrap: balance;
    }
    /* Before the first play the line reads in full. Once a voice plays, the word being said fills in
       from the side it is read from, in the accent colour; words already said settle to full colour. */
    .stage.is-live .stage-text .w {
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      background-image: linear-gradient(var(--sweep, to right), var(--st-now) calc(var(--f, 0) * 118% - 18%), var(--st-dim) calc(var(--f, 0) * 118%));
    }
    .stage.is-live .stage-text .w.is-said { color: var(--st-lit); background-image: none; }
    .stage-text[dir="rtl"] .w { --sweep: to left; }
    /* Room inside each word's box for dots and strokes that reach past the line, so the fill never cuts them. */
    .stage-text .w { padding: .3em .08em; margin: 0 -.08em; }
    /* The line and the speaker change together, with a short fade. */
    .stage-line, .stage-who { transition: opacity .22s ease, transform .22s ease; }
    .stage.is-swapping .stage-line, .stage.is-swapping .stage-who { opacity: 0; transform: translateY(6px); }
    @media (prefers-reduced-motion: reduce) { .stage-line, .stage-who { transition: none; } }

    .stage-console {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
      align-items: center;
      gap: 1.25rem 2rem;
    }
    .stage-who { display: flex; align-items: center; gap: 1.9rem; min-width: 0; }
    /* The ring round the avatar is drawn on a canvas reaching --ring past its edge. */
    .stage-avatar { --ring: 26px; position: relative; flex: none; width: 76px; height: 76px; }
    .stage-avatar canvas { position: absolute; inset: calc(var(--ring) * -1); width: calc(100% + var(--ring) * 2); max-width: none; height: calc(100% + var(--ring) * 2); pointer-events: none; }
    .stage-avatar img { position: relative; width: 76px; height: 76px; border-radius: 50%; object-fit: cover; background: #2A2160; box-shadow: 0 0 0 3px var(--st-avatar-ring); }
    .stage-id { display: flex; flex-direction: column; min-width: 0; gap: .2rem; }
    .stage-id strong { font-size: 1.15rem; font-weight: 700; }
    .stage-id span { display: inline-flex; align-items: center; gap: .45rem; color: var(--st-faint); font-size: .9rem; }
    .stage-id img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }
    .stage-controls { display: flex; align-items: center; gap: .75rem; }
    .stage-btn {
      display: grid;
      place-items: center;
      width: 48px;
      height: 48px;
      border: 1px solid var(--st-btn-line);
      border-radius: 50%;
      background: var(--st-btn-bg);
      color: var(--st-text);
      cursor: pointer;
      transition: background-color .2s ease, border-color .2s ease;
    }
    .stage-btn:hover { background: var(--st-btn-hover); }
    .stage-btn svg { width: 18px; height: 18px; fill: currentColor; }
    .stage-btn.is-main { width: 68px; height: 68px; border: 0; background: var(--st-main-bg); color: var(--st-main-fg); box-shadow: 0 14px 30px -12px rgba(118, 89, 250, .9); }
    .stage-btn.is-main:hover { background: var(--st-main-hover); }
    .stage-btn.is-main svg { width: 24px; height: 24px; }
    .stage-btn .i-pause { display: none; }
    .stage.is-playing .stage-btn.is-main .i-play { display: none; }
    .stage.is-playing .stage-btn.is-main .i-pause { display: block; }
    .stage-btn:focus-visible, .stage-pick:focus-visible { outline: 2px solid var(--st-focus); outline-offset: 3px; }
    /* Arrows for previous and next follow the reading direction; the play arrow never turns. */
    html[dir="rtl"] .stage-btn .i-step { transform: scaleX(-1); }

    .stage-queue { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: .4rem; margin: 0; padding: 0; list-style: none; }
    .stage-pick {
      position: relative;
      width: 40px;
      height: 40px;
      padding: 0;
      border: 0;
      border-radius: 50%;
      background: transparent;
      cursor: pointer;
      opacity: .55;
      transition: opacity .2s ease, transform .2s ease;
    }
    .stage-pick img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
    .stage-pick:hover { opacity: .9; }
    .stage-pick[aria-current="true"] { opacity: 1; box-shadow: 0 0 0 2px var(--st-pick-gap), 0 0 0 4px var(--st-now); border-radius: 50%; }
    .stage-progress { height: 3px; border-radius: 3px; background: var(--st-track); overflow: hidden; }
    .stage-progress span { display: block; height: 100%; transform: scaleX(0); transform-origin: left center; background: linear-gradient(90deg, var(--st-bar-from), var(--st-bar-to)); will-change: transform; }
    html[dir="rtl"] .stage-progress span { transform-origin: right center; background: linear-gradient(270deg, var(--st-bar-from), var(--st-bar-to)); }
    .stage-hint { margin: -.75rem 0 0; color: var(--st-faint); font-size: .86rem; text-align: center; }

    /* The section bar: jump to a section */
    .samples-bar {
      position: sticky;
      top: 64px;
      z-index: 20;
      background: color-mix(in oklab, var(--bg) 88%, transparent);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--line);
    }
    .samples-bar-inner {
      width: min(1200px, 92%);
      margin: 0 auto;
      display: flex;
      align-items: center;
      min-height: 58px;
    }
    /* Centred while it fits; scrolls from the start when it does not. */
    .samples-jump { display: flex; gap: .25rem; max-width: 100%; margin: 0 auto; padding: 0; list-style: none; overflow-x: auto; scrollbar-width: none; }
    .samples-jump::-webkit-scrollbar { display: none; }
    .samples-jump a {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      min-height: 40px;
      padding: 0 .85rem;
      border-radius: var(--radius-full);
      color: var(--muted);
      font-size: .9rem;
      font-weight: 600;
      text-decoration: none;
      white-space: nowrap;
    }
    .samples-jump a:hover { color: var(--text); background: var(--surface2); }
    .samples-jump a[aria-current="true"] { color: var(--accent-text); background: color-mix(in oklab, var(--a) 10%, transparent); }
    .samples-jump b { font-size: .78rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .samples-jump a:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }

    /* The sections */
    .samples-section { width: min(1200px, 92%); margin: 0 auto; padding: clamp(3rem, 7vw, 5rem) 0 0; scroll-margin-top: 130px; }
    .samples-section .vs-head { margin-bottom: 1.25rem; }
    .samples-moods { margin-bottom: 1.25rem; }

    /* The way out */
    .samples-cta {
      width: min(1200px, 92%);
      margin: clamp(3.5rem, 8vw, 5.5rem) auto clamp(3rem, 6vw, 4rem);
      padding: clamp(2.25rem, 5vw, 3.25rem) clamp(1.25rem, 4vw, 3rem);
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: center;
      gap: 1.5rem 2.5rem;
      border-radius: 1.5rem;
      color: #FFFFFF;
      background:
        radial-gradient(70% 120% at 100% 0%, rgba(255, 255, 255, .12), transparent 60%),
        linear-gradient(135deg, #4A30D9 0%, #6145F2 55%, #7254F7 100%);
    }
    .samples-cta h2 { margin: 0 0 .4rem; color: #FFFFFF; font-size: clamp(1.5rem, 3vw, 2.1rem); }
    .samples-cta p { margin: 0; color: rgba(255, 255, 255, .9); max-width: 52ch; }
    .samples-cta-actions { display: flex; flex-wrap: wrap; gap: .75rem; }
    .samples-cta a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 1.5rem;
      border-radius: var(--btn-radius);
      font-weight: 700;
      text-decoration: none;
    }
    .samples-cta .is-solid { background: #FFFFFF; color: #3F27C4; }
    .samples-cta .is-solid:hover { background: #F1EEFF; }
    .samples-cta .is-ghost { border: 1.5px solid rgba(255, 255, 255, .6); color: #FFFFFF; }
    .samples-cta .is-ghost:hover { border-color: #FFFFFF; background: rgba(255, 255, 255, .12); }
    .samples-cta a:focus-visible { outline: 2px solid #FFFFFF; outline-offset: 3px; }

    @media (max-width: 900px) {
      .stage-console { grid-template-columns: minmax(0, 1fr) auto; }
      .stage-queue { grid-column: 1 / -1; justify-content: flex-start; }
      .samples-cta { grid-template-columns: 1fr; }
      /* The two buttons side by side, the same size. */
      .samples-cta-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
      .samples-cta a { padding: .5rem .6rem; text-align: center; line-height: 1.3; }
    }
    @media (max-width: 640px) {
      /* Long chip rows scroll sideways on phones instead of stacking five deep. */
      .samples-moods {
        flex-wrap: nowrap;
        justify-content: flex-start;
        overflow-x: auto;
        scrollbar-width: none;
        margin-inline: -4vw;
        padding-inline: 4vw;
      }
      .samples-moods::-webkit-scrollbar { display: none; }
      .samples-moods .vs-chip { flex: none; }
    }
    @media (max-width: 560px) {
      .stage-console { grid-template-columns: 1fr; justify-items: start; }
      .stage-controls { justify-self: center; }
      /* Every voice in one row. */
      .stage-queue { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 46px); justify-content: center; gap: .5rem; width: 100%; }
      .stage-pick { width: 100%; height: auto; aspect-ratio: 1; }
      .stage-who { gap: 1.6rem; padding-inline-start: 10px; }
      .stage-avatar, .stage-avatar img { width: 60px; height: 60px; }
      .stage-avatar { --ring: 22px; }
      .samples-bar { top: 64px; }
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="samples-page">
    @if($stageCast !== [])
      {{-- The stage: the hero voices, one after another, each line lit word by word as it is spoken. --}}
      <section class="stage" data-stage aria-labelledby="samplesTitle">
        <div class="stage-wrap">
          <header class="stage-head">
            <h1 id="samplesTitle">{{ __('ui.samples.title') }}</h1>
            <p>{{ __('ui.samples.lead') }}</p>
          </header>
          @php $stageLength = mb_strlen($stageCast[0]['line']); @endphp
          <figure class="stage-line" data-size="{{ $stageLength > 120 ? 'l' : ($stageLength > 70 ? 'm' : 's') }}">
            <blockquote class="stage-text" data-stage-text dir="{{ $stageCast[0]['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $stageCast[0]['language'] }}">{{ $stageCast[0]['line'] }}</blockquote>
          </figure>
          <div class="stage-console">
            <div class="stage-who">
              <span class="stage-avatar">
                <canvas data-stage-meter width="216" height="216" aria-hidden="true"></canvas>
                <img data-stage-avatar src="{{ $stageCast[0]['avatar'] }}" alt="" width="76" height="76">
              </span>
              <span class="stage-id">
                <strong data-stage-name>{{ $stageCast[0]['name'] }}</strong>
                <span><img data-stage-flag src="{{ $stageCast[0]['flag'] }}" alt="" width="18" height="18"><span data-stage-dialect>{{ $stageCast[0]['dialect_label'] }}</span></span>
              </span>
            </div>
            <div class="stage-controls">
              <button type="button" class="stage-btn" data-stage-prev aria-label="{{ __('ui.samples.stage_prev') }}">
                <svg class="i-step" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h2v14H6zM9.5 12 19 5.5v13z"/></svg>
              </button>
              <button type="button" class="stage-btn is-main" data-stage-play aria-label="{{ __('ui.samples.stage_play') }}" data-label-play="{{ __('ui.samples.stage_play') }}" data-label-pause="{{ __('ui.samples.stage_pause') }}">
                {!! $playIcon !!}{!! $pauseIcon !!}
              </button>
              <button type="button" class="stage-btn" data-stage-next aria-label="{{ __('ui.samples.stage_next') }}">
                <svg class="i-step" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 5h2v14h-2zM14.5 12 5 18.5v-13z"/></svg>
              </button>
            </div>
            <ol class="stage-queue" aria-label="{{ __('ui.samples.stage_queue') }}">
              @foreach($stageCast as $i => $person)
                <li><button type="button" class="stage-pick" data-stage-pick="{{ $i }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}" aria-label="{{ $person['name'] }}, {{ $person['dialect_label'] }}"><img src="{{ $person['avatar'] }}" alt="" width="40" height="40" loading="lazy"></button></li>
              @endforeach
            </ol>
          </div>
          <div class="stage-progress" aria-hidden="true"><span data-stage-progress></span></div>
          <p class="stage-hint">{{ __('ui.samples.stage_hint') }}</p>
        </div>
        <script type="application/json" data-stage-cast>@json($stageCast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
      </section>
    @endif

    <nav class="samples-bar" aria-label="{{ __('ui.samples.nav_label') }}">
      <div class="samples-bar-inner">
        <ul class="samples-jump">
          @foreach($sections as $section)
            <li><a href="#{{ $section['id'] }}" data-jump="{{ $section['id'] }}">{{ $section['label'] }} <b>{{ $section['count'] }}</b></a></li>
          @endforeach
        </ul>
      </div>
    </nav>

    @if($voices !== [])
      @php
        ['id' => $sectionId, 'copy' => $copyKey] = $voiceSection;
        $regions = $regionsOf($voices);
      @endphp
      <section class="samples-section" id="{{ $sectionId }}" aria-labelledby="{{ $sectionId }}-title">
        <div class="vs-head">
          <div>
            <h2 class="vs-title" id="{{ $sectionId }}-title">{{ __('ui.samples.'.$copyKey.'_title') }}</h2>
            <p class="vs-lead">{{ __('ui.samples.'.$copyKey.'_lead') }}</p>
          </div>
        </div>
        @if(count($regions) > 1)
          <div class="vs-chips" role="tablist" aria-label="{{ __('ui.landing.region_label') }}">
            <button type="button" class="vs-chip" role="tab" aria-selected="true" data-vp-region="all">{{ __('ui.landing.region_all') }}</button>
            @foreach($regions as $region)
              <button type="button" class="vs-chip" role="tab" aria-selected="false" data-vp-region="{{ $region }}">{{ __('ui.landing.region_'.$region) }}</button>
            @endforeach
          </div>
        @endif
        <div class="vs-grid">
          @foreach($voices as $sample)
            @include('components.home.voice-card', ['sample' => $sample])
          @endforeach
        </div>
      </section>
    @endif

    @if($moods !== [])
      {{-- The same voices sorted by how they speak: one mood at a time. --}}
      <section class="samples-section" id="moods" aria-labelledby="moods-title">
        <div class="vs-head">
          <div>
            <h2 class="vs-title" id="moods-title">{{ __('ui.samples.moods_title') }}</h2>
            <p class="vs-lead">{{ __('ui.samples.moods_lead') }}</p>
          </div>
        </div>
        <div class="vs-chips samples-moods" role="tablist" aria-label="{{ __('ui.samples.moods_label') }}">
          @foreach($moods as $mood)
            <button type="button" class="vs-chip" role="tab" aria-selected="{{ $mood === $firstMood ? 'true' : 'false' }}" data-vp-region="{{ $mood }}">{{ $moodLabel($mood) }}</button>
          @endforeach
        </div>
        <div class="vs-grid is-filtered">
          @foreach($moodVoices as $sample)
            @include('components.home.voice-card', ['sample' => $sample, 'filterKey' => $sample['emotion'], 'cardHidden' => $sample['emotion'] !== $firstMood])
          @endforeach
        </div>
      </section>
    @endif

    @if($dialogues !== [])
      <section class="samples-section" id="conversations" aria-labelledby="conversations-title">
        <div class="vs-head">
          <div>
            <h2 class="vs-title" id="conversations-title">{{ __('ui.samples.conversations_title') }}</h2>
            <p class="vs-lead">{{ __('ui.samples.conversations_lead') }}</p>
          </div>
        </div>
        <div class="vs-dialogue-grid">
          @foreach($dialogues as $dialogue)
            @include('components.home.dialogue-card', ['dialogue' => $dialogue])
          @endforeach
        </div>
      </section>
    @endif

    @if($environments !== [])
      <section class="samples-section" id="sound-environments" aria-labelledby="sound-environments-title">
        <div class="vs-head">
          <div>
            <h2 class="vs-title" id="sound-environments-title">{{ __('ui.samples.environments_title') }}</h2>
            <p class="vs-lead">{{ __('ui.samples.environments_lead') }}</p>
          </div>
        </div>
        <div class="vs-env-grid">
          @foreach($environments as $env)
            @include('components.home.env-card', ['env' => $env])
          @endforeach
        </div>
      </section>
    @endif

    <section class="samples-cta" aria-labelledby="samplesCtaTitle">
      <div>
        <h2 id="samplesCtaTitle">{{ __('ui.samples.cta_title') }}</h2>
        <p>{{ __('ui.samples.cta_body') }}</p>
      </div>
      <div class="samples-cta-actions">
        @auth
          <a class="is-solid" href="{{ route('dashboard') }}">{{ __('ui.pricing.go_to_dashboard') }}</a>
          <a class="is-ghost" href="{{ route('pricing') }}">{{ __('ui.nav.pricing') }}</a>
        @else
          <a class="is-solid" href="{{ route('demo.page') }}">{{ __('ui.samples.cta_try') }}</a>
          <a class="is-ghost" href="{{ route('register') }}">{{ __('ui.samples.cta_signup') }}</a>
        @endauth
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.voice-player-script')
  <script>
  /* ── The stage: one voice after another, each word filling in as it is spoken ── */
  (function () {
    var stage = document.querySelector('[data-stage]');
    if (!stage) return;
    var cast = [];
    try { cast = JSON.parse(stage.querySelector('[data-stage-cast]').textContent) || []; } catch (e) { return; }
    if (!cast.length) return;
    var textEl = stage.querySelector('[data-stage-text]');
    var nameEl = stage.querySelector('[data-stage-name]');
    var dialectEl = stage.querySelector('[data-stage-dialect]');
    var flagEl = stage.querySelector('[data-stage-flag]');
    var avatarEl = stage.querySelector('[data-stage-avatar]');
    var playBtn = stage.querySelector('[data-stage-play]');
    var progressEl = stage.querySelector('[data-stage-progress]');
    var canvas = stage.querySelector('[data-stage-meter]');
    var ctx2d = canvas && canvas.getContext ? canvas.getContext('2d') : null;
    var picks = stage.querySelectorAll('[data-stage-pick]');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var audio = new Audio();
    audio.preload = 'none';
    var index = 0, tokens = [], frame = 0, swapTimer = 0, smooth = 0, lastTs = 0, bar = null;
    var clips = {};

    // The clip's loudness, 10 ms at a time, so the ring moves with the voice.
    function analyse(src) {
      if (!src || clips[src] || !window.fetch || !(window.OfflineAudioContext || window.webkitOfflineAudioContext)) return;
      clips[src] = { pending: true };
      fetch(src, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
        var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        return new Promise(function (ok, fail) { new Ctx(1, 2, 44100).decodeAudioData(buf, ok, fail); });
      }).then(function (decoded) {
        var data = decoded.getChannelData(0), hop = Math.round(decoded.sampleRate * .01), rms = [], peak = 0;
        for (var i = 0; i < data.length; i += hop) {
          var sum = 0, n = Math.min(hop, data.length - i);
          for (var j = 0; j < n; j++) sum += data[i + j] * data[i + j];
          var v = Math.sqrt(sum / Math.max(1, n)); rms.push(v); if (v > peak) peak = v;
        }
        clips[src] = { rms: rms, peak: peak || 1 };
      }).catch(function () { clips[src] = null; });
    }

    // The line as words, each with when it is said in the clip (measured by scripts/tts/word_timing.py).
    function parse(line, times) {
      var out = [], k = 0;
      String(line).split(/(\s+)/).forEach(function (piece) {
        if (!piece) return;
        if (/^\s+$/.test(piece)) { out.push({ space: piece }); return; }
        var at = times[k++] || [0, 0];
        out.push({ word: piece, start: at[0], end: at[1] });
      });
      return out;
    }

    // The player's clock, run on the screen's frames and eased towards the audio's own time,
    // so it never steps or slips backwards between the browser's coarse time updates.
    function clock(ts) {
      var ct = audio.currentTime || 0;
      if (audio.paused || !lastTs || Math.abs(ct - smooth) > .3) {
        smooth = ct;
      } else {
        var next = smooth + (ts - lastTs) / 1000 * (audio.playbackRate || 1);
        next += (ct - next) * .1;
        smooth = Math.max(smooth, next);
      }
      lastTs = audio.paused ? 0 : ts;
      return smooth;
    }

    // Longer lines get a smaller size so every line fits the stage.
    function sizeFor(line) {
      var n = String(line).length;
      return n > 120 ? 'l' : (n > 70 ? 'm' : 's');
    }
    function setLine(person) {
      tokens = parse(person.line, person.times || []);
      textEl.parentNode.setAttribute('data-size', sizeFor(person.line));
      textEl.textContent = '';
      textEl.setAttribute('dir', person.language === 'ar' ? 'rtl' : 'ltr');
      textEl.setAttribute('lang', person.language);
      tokens.forEach(function (t) {
        if (t.space) { textEl.appendChild(document.createTextNode(t.space)); return; }
        if (!t.word) return;
        t.el = document.createElement('span');
        t.el.className = 'w';
        t.el.textContent = t.word;
        t.f = -1;
        textEl.appendChild(t.el);
      });
    }
    function paintWords(v) {
      tokens.forEach(function (t) {
        if (!t.el) return;
        var f = t.end > t.start ? (v - t.start) / (t.end - t.start) : (v >= t.end ? 1 : 0);
        f = f < 0 ? 0 : (f > 1 ? 1 : f);
        if (f !== t.f && (Math.abs(f - t.f) > .003 || f === 0 || f === 1)) {
          t.f = f;
          t.el.style.setProperty('--f', f.toFixed(3));
          t.el.classList.toggle('is-said', f === 1);
        }
      });
    }
    function show(i) {
      index = (i + cast.length) % cast.length;
      var person = cast[index];
      setLine(person);
      nameEl.textContent = person.name;
      dialectEl.textContent = person.dialect_label;
      if (person.flag) flagEl.src = person.flag;
      if (person.avatar) avatarEl.src = person.avatar;
      picks.forEach(function (p, k) { p.setAttribute('aria-current', k === index ? 'true' : 'false'); });
      if (bar) { bar.cancel(); bar = null; }
      progressEl.style.transform = 'scaleX(0)';
      smooth = 0;
      lastTs = 0;
      paintWords(0);
    }
    // The line and the speaker fade out and back in together when the voice changes.
    function change(i, then) {
      clearTimeout(swapTimer);
      if (reduced) { show(i); if (then) then(); return; }
      stage.classList.add('is-swapping');
      swapTimer = setTimeout(function () {
        show(i);
        stage.classList.remove('is-swapping');
        if (then) then();
      }, 220);
    }

    // The ring: bars all the way round the avatar, rising with the voice.
    var BARS = 72, levels = new Float32Array(BARS), level = 0, ringSize = 0, ringRgb = '201, 190, 255';
    // The ring takes the theme's colour, and changes with it.
    function readRingColour() {
      ringRgb = (getComputedStyle(stage).getPropertyValue('--st-ring') || '').trim() || ringRgb;
    }
    readRingColour();
    if (window.MutationObserver) {
      new MutationObserver(function () { readRingColour(); drawRing(0, !audio.paused); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }
    function sizeRing() {
      if (!ctx2d) return;
      var box = canvas.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
      ringSize = box.width;
      canvas.width = Math.round(box.width * dpr);
      canvas.height = Math.round(box.height * dpr);
      ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function drawRing(t, live) {
      if (!ctx2d || !ringSize) return;
      var info = clips[cast[index].audio], target = 0;
      if (live && info && info.rms) {
        var f = Math.max(0, Math.min(info.rms.length - 1, Math.round(t * 100)));
        target = Math.min(1, Math.sqrt(info.rms[f] / info.peak));
      }
      level += (target - level) * .2;
      var c = ringSize / 2, inner = avatarEl.offsetWidth / 2 + 6, room = c - inner - 2;
      ctx2d.clearRect(0, 0, ringSize, ringSize);
      if (level > .02) {
        var glow = ctx2d.createRadialGradient(c, c, inner - 6, c, c, c);
        glow.addColorStop(0, 'rgba(' + ringRgb + ',' + (.38 * level).toFixed(3) + ')');
        glow.addColorStop(1, 'rgba(' + ringRgb + ', 0)');
        ctx2d.fillStyle = glow;
        ctx2d.beginPath();
        ctx2d.arc(c, c, c, 0, Math.PI * 2);
        ctx2d.fill();
      }
      ctx2d.lineCap = 'round';
      ctx2d.lineWidth = 2.2;
      ctx2d.strokeStyle = 'rgba(' + ringRgb + ',' + (.45 + .5 * level).toFixed(3) + ')';
      // Every bar in one path, drawn in one stroke.
      ctx2d.beginPath();
      for (var b = 0; b < BARS; b++) {
        var a = b / BARS * Math.PI * 2 - Math.PI / 2;
        // A slow, even ripple round the circle, so no bar jumps on its own.
        var shape = .5 + .5 * Math.sin(b * .52 + t * 2.4) * Math.cos(b * .21 - t * 1.3);
        levels[b] += (level * (.3 + .7 * shape) - levels[b]) * .25;
        var len = 2 + levels[b] * (room - 2), cos = Math.cos(a), sin = Math.sin(a);
        ctx2d.moveTo(c + cos * inner, c + sin * inner);
        ctx2d.lineTo(c + cos * (inner + len), c + sin * (inner + len));
      }
      ctx2d.stroke();
    }

    // The progress line runs as one animation from where the clip is to its end, drawn by the
    // browser off the main thread; it is set again only if it drifts from the audio.
    function runBar() {
      var d = audio.duration || 0, t = audio.currentTime || 0;
      if (!(d > 0 && isFinite(d)) || !progressEl.animate) return;
      if (bar) bar.cancel();
      bar = progressEl.animate([{ transform: 'scaleX(' + Math.min(1, t / d).toFixed(4) + ')' }, { transform: 'scaleX(1)' }],
        { duration: Math.max(1, (d - t) * 1000), easing: 'linear', fill: 'forwards' });
      bar.startFrom = t / d;
      if (audio.paused) bar.pause();
    }
    function barAt() {
      if (!bar || !bar.effect) return -1;
      var timing = bar.effect.getComputedTiming();
      return bar.startFrom + (1 - bar.startFrom) * (timing.progress || 0);
    }
    function tick(ts) {
      var t = clock(ts), d = audio.duration || 0;
      if (!progressEl.animate) progressEl.style.transform = 'scaleX(' + (d > 0 ? Math.min(1, t / d) : 0).toFixed(4) + ')';
      else if (!audio.paused && d > 0 && Math.abs(barAt() - t / d) > .02) runBar();
      paintWords(t);
      var live = !audio.paused && !reduced;
      drawRing(t, live);
      // Keeps drawing while the voice plays and while the ring settles after it stops.
      frame = (live || level > .01) ? requestAnimationFrame(tick) : 0;
    }
    function play() {
      var person = cast[index];
      if (!person.audio) return;
      if (audio.getAttribute('data-src') !== person.audio) {
        audio.src = person.audio;
        audio.setAttribute('data-src', person.audio);
      }
      analyse(person.audio);
      var p = audio.play();
      if (p && p.catch) p.catch(function () {});
    }
    audio.addEventListener('play', function () {
      stage.classList.add('is-playing', 'is-live');
      playBtn.setAttribute('aria-label', playBtn.getAttribute('data-label-pause'));
      // One clip at a time on the page: the cards below stop when the stage plays.
      document.querySelectorAll('audio').forEach(function (el) { if (el !== audio && !el.paused) el.pause(); });
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: audio } }));
      // The next voice's timing is ready before it starts.
      analyse((cast[index + 1] || {}).audio);
      if (!frame) frame = requestAnimationFrame(tick);
    });
    audio.addEventListener('playing', runBar);
    audio.addEventListener('waiting', function () { if (bar) bar.pause(); });
    audio.addEventListener('seeked', runBar);
    audio.addEventListener('pause', function () {
      if (bar) bar.pause();
      stage.classList.remove('is-playing');
      playBtn.setAttribute('aria-label', playBtn.getAttribute('data-label-play'));
      if (!frame) frame = requestAnimationFrame(tick);
    });
    // Played in order, the voices carry on to the next one.
    audio.addEventListener('ended', function () {
      paintWords(Infinity);
      if (bar) { bar.finish(); }
      progressEl.style.transform = 'scaleX(1)';
      if (index < cast.length - 1) change(index + 1, play);
    });
    document.addEventListener('listen:audio-play', function (e) {
      if (e.detail && e.detail.source !== audio && !audio.paused) audio.pause();
    });
    playBtn.addEventListener('click', function () { if (audio.paused) play(); else audio.pause(); });
    stage.querySelector('[data-stage-prev]').addEventListener('click', function () { var was = !audio.paused; audio.pause(); change(index - 1, was ? play : null); });
    stage.querySelector('[data-stage-next]').addEventListener('click', function () { var was = !audio.paused; audio.pause(); change(index + 1, was ? play : null); });
    picks.forEach(function (p) {
      p.addEventListener('click', function () { audio.pause(); change(parseInt(p.getAttribute('data-stage-pick'), 10), play); });
    });
    window.addEventListener('resize', function () { sizeRing(); drawRing(0, false); });
    show(0);
    sizeRing();
    drawRing(0, false);
  })();

  /* ── The section bar: the section in view is marked ── */
  (function () {
    var links = document.querySelectorAll('.samples-jump a');
    if (window.IntersectionObserver && links.length) {
      var byId = {};
      links.forEach(function (a) { byId[a.getAttribute('data-jump')] = a; });
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          links.forEach(function (a) { a.setAttribute('aria-current', a === byId[entry.target.id] ? 'true' : 'false'); });
        });
      }, { rootMargin: '-40% 0px -55% 0px' });
      document.querySelectorAll('.samples-section').forEach(function (s) { observer.observe(s); });
    }
  })();
  </script>
</body>
</html>
