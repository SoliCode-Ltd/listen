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
  // The page language's voices come first.
  $languageSections = [
    ['id' => 'arabic-dialects', 'cards' => $arabicVoices, 'copy' => 'arabic'],
    ['id' => 'english-accents', 'cards' => $englishVoices, 'copy' => 'english'],
  ];
  if (! $isArabicUi) {
    $languageSections = array_reverse($languageSections);
  }
  $sections = array_values(array_filter([
    ...array_map(fn (array $ls): ?array => $ls['cards'] !== [] ? ['id' => $ls['id'], 'label' => __('ui.samples.'.$ls['copy'].'_title'), 'count' => count($ls['cards'])] : null, $languageSections),
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
      position: relative;
      isolation: isolate;
      overflow: hidden;
      color: #FFFFFF;
      background:
        radial-gradient(60% 80% at 85% 10%, rgba(118, 89, 250, .42), transparent 60%),
        radial-gradient(50% 70% at 5% 100%, rgba(61, 139, 255, .22), transparent 60%),
        var(--stage-ink);
    }
    /* A faint field of sound bars behind the words. */
    .stage::before {
      content: "";
      position: absolute;
      inset: auto 0 0 0;
      height: 55%;
      z-index: -1;
      background: repeating-linear-gradient(90deg, rgba(255, 255, 255, .055) 0 3px, transparent 3px 14px);
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
      color: var(--stage-lilac);
      font-size: clamp(1.15rem, 2vw, 1.35rem);
      font-weight: 700;
      letter-spacing: 0;
    }
    .stage-head p { margin: 0 auto; max-width: 56ch; color: rgba(255, 255, 255, .78); font-size: 1rem; line-height: 1.6; text-wrap: balance; }
    .stage-line { margin: 0; min-height: calc(3 * 1.45em); display: grid; align-items: center; font-size: clamp(1.75rem, 3.6vw, 3.1rem); }
    .stage-text {
      margin: 0 auto;
      max-width: 28ch;
      text-align: center;
      font-weight: 700;
      line-height: 1.45;
      letter-spacing: -.01em;
      text-wrap: balance;
    }
    /* Before the first play the line reads in full; once a voice speaks, words light as they are said. */
    .stage.is-live .stage-text .w { color: rgba(255, 255, 255, .3); transition: color .18s ease; }
    .stage.is-live .stage-text .w.is-lit { color: #FFFFFF; }
    .stage.is-live .stage-text .w.is-now { color: var(--stage-lilac); }
    @media (prefers-reduced-motion: reduce) { .stage-text .w { transition: none; } }

    .stage-console {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
      align-items: center;
      gap: 1.25rem 2rem;
    }
    .stage-who { display: flex; align-items: center; gap: .9rem; min-width: 0; }
    .stage-avatar { position: relative; flex: none; width: 76px; height: 76px; }
    .stage-avatar canvas { position: absolute; inset: -16px; width: 108px; height: 108px; }
    .stage-avatar img { position: relative; width: 76px; height: 76px; border-radius: 50%; object-fit: cover; background: #2A2160; box-shadow: 0 0 0 3px rgba(255, 255, 255, .14); }
    .stage-id { display: flex; flex-direction: column; min-width: 0; gap: .2rem; }
    .stage-id strong { font-size: 1.15rem; font-weight: 700; }
    .stage-id span { display: inline-flex; align-items: center; gap: .45rem; color: rgba(255, 255, 255, .72); font-size: .9rem; }
    .stage-id img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }
    .stage-controls { display: flex; align-items: center; gap: .75rem; }
    .stage-btn {
      display: grid;
      place-items: center;
      width: 48px;
      height: 48px;
      border: 1px solid rgba(255, 255, 255, .22);
      border-radius: 50%;
      background: rgba(255, 255, 255, .06);
      color: #FFFFFF;
      cursor: pointer;
      transition: background-color .2s ease, border-color .2s ease;
    }
    .stage-btn:hover { background: rgba(255, 255, 255, .14); border-color: rgba(255, 255, 255, .4); }
    .stage-btn svg { width: 18px; height: 18px; fill: currentColor; }
    .stage-btn.is-main { width: 68px; height: 68px; border: 0; background: #FFFFFF; color: #3F27C4; box-shadow: 0 14px 30px -12px rgba(118, 89, 250, .9); }
    .stage-btn.is-main:hover { background: #F1EEFF; }
    .stage-btn.is-main svg { width: 24px; height: 24px; }
    .stage-btn .i-pause { display: none; }
    .stage.is-playing .stage-btn.is-main .i-play { display: none; }
    .stage.is-playing .stage-btn.is-main .i-pause { display: block; }
    .stage-btn:focus-visible, .stage-pick:focus-visible { outline: 2px solid #FFFFFF; outline-offset: 3px; }
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
    .stage-pick[aria-current="true"] { opacity: 1; box-shadow: 0 0 0 2px var(--stage-ink), 0 0 0 4px var(--stage-lilac); border-radius: 50%; }
    .stage-progress { height: 3px; border-radius: 3px; background: rgba(255, 255, 255, .12); overflow: hidden; }
    .stage-progress span { display: block; height: 100%; width: calc(var(--p, 0) * 100%); background: linear-gradient(90deg, var(--stage-lilac), #FFFFFF); }
    .stage-hint { margin: -.75rem 0 0; color: rgba(255, 255, 255, .6); font-size: .86rem; text-align: center; }

    /* The section bar: jump to a section, or keep one language */
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
      justify-content: space-between;
      gap: 1rem;
      min-height: 58px;
    }
    .samples-jump { display: flex; gap: .25rem; margin: 0; padding: 0; list-style: none; overflow-x: auto; scrollbar-width: none; }
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
    .samples-lang { display: inline-grid; grid-auto-flow: column; padding: .2rem; border: 1px solid var(--line); border-radius: var(--radius-full); background: var(--surface); flex: none; }
    .samples-lang button {
      min-height: 34px;
      padding: 0 .8rem;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--muted);
      font: inherit;
      font-size: .84rem;
      font-weight: 600;
      cursor: pointer;
    }
    .samples-lang button[aria-pressed="true"] { background: var(--brand-accent); color: #FFFFFF; }
    .samples-lang button:focus-visible, .samples-jump a:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }

    /* The sections */
    .samples-section { width: min(1200px, 92%); margin: 0 auto; padding: clamp(3rem, 7vw, 5rem) 0 0; scroll-margin-top: 130px; }
    .samples-section .vs-head { margin-bottom: 1.25rem; }
    .samples-page[data-lang="ar"] [data-language="en"],
    .samples-page[data-lang="en"] [data-language="ar"],
    .samples-page[data-lang="ar"] #english-accents,
    .samples-page[data-lang="en"] #arabic-dialects { display: none !important; }
    .samples-page[data-lang="ar"] [data-jump="english-accents"],
    .samples-page[data-lang="en"] [data-jump="arabic-dialects"] { display: none; }
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
      .samples-lang { display: none; }
      .samples-cta { grid-template-columns: 1fr; }
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
      .stage-queue { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: .3rem; width: 100%; }
      .stage-pick { width: 100%; height: auto; aspect-ratio: 1; }
      .stage-avatar, .stage-avatar img { width: 60px; height: 60px; }
      .stage-avatar canvas { inset: -14px; width: 88px; height: 88px; }
      .samples-bar { top: 64px; }
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="samples-page" data-lang="all">
    @if($stageCast !== [])
      {{-- The stage: the hero voices, one after another, each line lit word by word as it is spoken. --}}
      <section class="stage" data-stage aria-labelledby="samplesTitle">
        <div class="stage-wrap">
          <header class="stage-head">
            <h1 id="samplesTitle">{{ __('ui.samples.title') }}</h1>
            <p>{{ __('ui.samples.lead') }}</p>
          </header>
          <figure class="stage-line">
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
        <div class="samples-lang" role="group" aria-label="{{ __('ui.samples.lang_label') }}">
          <button type="button" data-samples-lang="all" aria-pressed="true">{{ __('ui.samples.lang_all') }}</button>
          <button type="button" data-samples-lang="ar" aria-pressed="false">{{ __('ui.samples.lang_ar') }}</button>
          <button type="button" data-samples-lang="en" aria-pressed="false">{{ __('ui.samples.lang_en') }}</button>
        </div>
      </div>
    </nav>

    @foreach($languageSections as ['id' => $sectionId, 'cards' => $cards, 'copy' => $copyKey])
      @if($cards !== [])
        @php $regions = $regionsOf($cards); @endphp
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
            @foreach($cards as $sample)
              @include('components.home.voice-card', ['sample' => $sample])
            @endforeach
          </div>
        </section>
      @endif
    @endforeach

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
          <a class="is-solid" href="{{ route('demo.page') }}">{{ __('ui.landing.try_demo') }}</a>
          <a class="is-ghost" href="{{ route('register') }}">{{ __('ui.pricing.create_free_account') }}</a>
        @endauth
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.voice-player-script')
  <script>
  /* ── The stage: one voice after another, each word lit as it is spoken ── */
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
    var index = 0, words = [], weights = [], total = 0, frame = 0, level = 0;
    var loudness = {};

    // The clip's loudness, 10 ms at a time, so the halo moves with the voice.
    function analyse(src) {
      if (loudness[src] || !window.fetch || !(window.OfflineAudioContext || window.webkitOfflineAudioContext)) return;
      loudness[src] = { pending: true };
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
        loudness[src] = { rms: rms, peak: peak || 1 };
      }).catch(function () { loudness[src] = null; });
    }

    // The line as words, each weighted by its length, to light in step with the clip.
    function setLine(person) {
      textEl.textContent = '';
      textEl.setAttribute('dir', person.language === 'ar' ? 'rtl' : 'ltr');
      textEl.setAttribute('lang', person.language);
      words = []; weights = []; total = 0;
      String(person.line).split(/(\s+)/).forEach(function (part) {
        if (!part) return;
        if (/^\s+$/.test(part)) { textEl.appendChild(document.createTextNode(part)); return; }
        var span = document.createElement('span');
        span.className = 'w';
        span.textContent = part;
        textEl.appendChild(span);
        words.push(span);
        var w = part.replace(/[^\p{L}\p{N}]/gu, '').length + 1;
        weights.push(w); total += w;
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
      progressEl.style.setProperty('--p', '0');
      paintWords(0);
    }
    function paintWords(ratio) {
      // The voice needs a breath before the first word and leaves a short tail.
      var spoken = Math.max(0, Math.min(1, (ratio - .04) / .9)) * total, acc = 0;
      words.forEach(function (w, k) {
        var start = acc; acc += weights[k];
        w.classList.toggle('is-lit', spoken >= acc);
        w.classList.toggle('is-now', spoken > start && spoken < acc);
      });
    }
    function drawHalo(t) {
      if (!ctx2d) return;
      var info = loudness[cast[index].audio];
      var target = 0;
      if (!audio.paused && info && info.rms) {
        var f = Math.min(info.rms.length - 1, Math.round(t * 100));
        target = Math.min(1, Math.sqrt((info.rms[f] || 0) / info.peak));
      }
      level += (target - level) * .3;
      var w = canvas.width, h = canvas.height, cx = w / 2, cy = h / 2, inner = w * .37;
      ctx2d.clearRect(0, 0, w, h);
      var bars = 56;
      for (var b = 0; b < bars; b++) {
        var a = (b / bars) * Math.PI * 2 - Math.PI / 2;
        var wobble = .55 + .45 * Math.sin(b * 1.7 + t * 6);
        var len = 4 + level * w * .11 * wobble;
        ctx2d.strokeStyle = 'rgba(201, 190, 255,' + (.25 + .6 * level).toFixed(3) + ')';
        ctx2d.lineWidth = 3;
        ctx2d.lineCap = 'round';
        ctx2d.beginPath();
        ctx2d.moveTo(cx + Math.cos(a) * inner, cy + Math.sin(a) * inner);
        ctx2d.lineTo(cx + Math.cos(a) * (inner + len), cy + Math.sin(a) * (inner + len));
        ctx2d.stroke();
      }
    }
    function tick() {
      var t = audio.currentTime || 0, d = audio.duration || 0;
      var ratio = d > 0 ? Math.min(1, t / d) : 0;
      progressEl.style.setProperty('--p', ratio.toFixed(4));
      paintWords(ratio);
      if (!reduced) drawHalo(t);
      frame = audio.paused ? 0 : requestAnimationFrame(tick);
    }
    function play(i) {
      if (typeof i === 'number' && i !== index) show(i);
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
      document.querySelectorAll('audio').forEach(function (el) { if (!el.paused) el.pause(); });
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: audio } }));
      if (!frame) frame = requestAnimationFrame(tick);
    });
    audio.addEventListener('pause', function () {
      stage.classList.remove('is-playing');
      playBtn.setAttribute('aria-label', playBtn.getAttribute('data-label-play'));
      drawHalo(audio.currentTime || 0);
    });
    // Played in order, the voices carry on to the next one.
    audio.addEventListener('ended', function () { paintWords(1); if (index < cast.length - 1) play(index + 1); });
    document.addEventListener('listen:audio-play', function (e) {
      if (e.detail && e.detail.source !== audio && !audio.paused) audio.pause();
    });
    playBtn.addEventListener('click', function () { if (audio.paused) play(); else audio.pause(); });
    stage.querySelector('[data-stage-prev]').addEventListener('click', function () { var was = !audio.paused; audio.pause(); show(index - 1); if (was) play(); });
    stage.querySelector('[data-stage-next]').addEventListener('click', function () { var was = !audio.paused; audio.pause(); show(index + 1); if (was) play(); });
    picks.forEach(function (p) {
      p.addEventListener('click', function () { audio.pause(); play(parseInt(p.getAttribute('data-stage-pick'), 10)); });
    });
    show(0);
    drawHalo(0);
  })();

  /* ── The section bar: the section in view is marked; one language can be kept ── */
  (function () {
    var page = document.querySelector('.samples-page');
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
    document.querySelectorAll('[data-samples-lang]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        page.setAttribute('data-lang', btn.getAttribute('data-samples-lang'));
        document.querySelectorAll('[data-samples-lang]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
      });
    });
  })();
  </script>
</body>
</html>
