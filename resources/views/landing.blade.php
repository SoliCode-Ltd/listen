@include('components.page-head', ['pageTitle' => __('ui.seo.home_title'), 'page' => 'home'])
  @php($voiceShowcaseCss = public_path('css/voice-showcase.css'))
  <link rel="stylesheet" href="{{ asset('css/voice-showcase.css') }}?v={{ is_file($voiceShowcaseCss) ? filemtime($voiceShowcaseCss) : '1' }}">
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .25;
      animation: float 10s ease-in-out infinite;
      will-change: transform;
    }
    .bg-orb.a {
      display: none;
    }
    .bg-orb.b {
      display: none;
    }

    :root {
      --landing-section-space: 3rem;
      --landing-section-space-mobile: 2rem;
    }

    .hero {
      padding: var(--landing-section-space) 0;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 1.75rem;
      align-items: center;
    }
    .eyebrow {
      display: inline-block;
      padding: .4rem .85rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .78rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
      margin-bottom: 1rem;
    }
    .hero h1 {
      margin: 0 0 1rem;
      font-size: clamp(2.4rem, 6vw, 4.5rem);
      line-height: 1.05;
      letter-spacing: -.03em;
    }
    html[dir="rtl"] .hero h1 {
      font-size: clamp(2.4rem, 6vw, 4.5rem);
      line-height: 1.32;
      letter-spacing: 0;
      text-wrap: balance;
    }
    .lead {
      margin: 0 0 1.5rem;
      color: var(--muted);
      font-size: 1.1rem;
      line-height: 1.6;
      max-width: 52ch;
    }
    .hero-cta {
      display: flex;
      gap: .75rem;
      flex-wrap: wrap;
    }
    .btn-trial {
      border-color: var(--brand-accent);
      color: var(--brand-accent);
      font-weight: 700;
    }

    .hero-art {
      position: relative;
      display: grid;
      place-items: center;
    }
    .hero-art::before {
      display: none;
    }
    .hero-art > img {
      width: min(520px, 100%);
      height: auto;
      filter: drop-shadow(0 16px 28px rgba(0,0,0,.24));
      animation: float 7s ease-in-out infinite;
      position: relative;
      z-index: 1;
    }

    .section {
      padding: var(--landing-section-space) 0;
    }
    .section h2 {
      margin: 0 0 .5rem;
      font-size: clamp(1.6rem, 3.5vw, 2.4rem);
      text-align: center;
    }
    .section .section-lead {
      margin: 0 0 2rem;
      color: var(--muted);
      font-size: 1.05rem;
      text-align: center;
      margin-left: auto;
      margin-right: auto;
    }

    /* ─── Listen Anywhere Section ─── */
    .apps-shell {
      position: relative;
      overflow: hidden;
      padding: clamp(2rem, 3vw, 3rem);

    }
    .apps-shell::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='1' cy='1' r='.85' fill='white' fill-opacity='.025'/%3E%3C/svg%3E");
      pointer-events: none;
    }
    .apps-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: color-mix(in oklab, var(--a) 75%, var(--text));
      border: 1px solid var(--line);
      background: var(--surface2);
      border-radius: var(--radius-full);
      padding: .32rem .75rem;
      margin-bottom: .85rem;
      position: relative;
      z-index: 1;
    }
    .apps-kicker::before {
      content: "";
      width: .42rem;
      height: .42rem;
      border-radius: 50%;
      background: var(--brand-accent);
      box-shadow: none;
    }

    .apps-copy {
      margin: 0 auto;
      text-align: center;
    }
    .apps-copy h2 { margin-bottom: .6rem; }
    .apps-points {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      justify-content: flex-end;
      align-self: start;
      padding-top: .5rem;
    }
    .apps-point {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .48rem .8rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface2);
      color: var(--muted);
      font-size: .8rem;
      white-space: nowrap;
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
    }
    .apps-point::before {
      content: "";
      width: .42rem;
      height: .42rem;
      border-radius: 50%;
      background: var(--brand-accent);
      flex-shrink: 0;
    }
    /* Two app cards */
    .apps-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1.1rem;
      position: relative;
      z-index: 1;
    }
    .app-card {
      position: relative;
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.6rem 1.5rem;
      background: var(--surface2);
      display: flex;
      flex-direction: column;
      gap: 1rem;
      transition: transform .28s cubic-bezier(.22,1,.36,1), border-color .28s ease, box-shadow .28s ease;
    }
    .app-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      display: none;
    }
    .app-card:hover {
      transform: translateY(-5px) scale(1.005);
      border-color: var(--line);
      box-shadow: var(--shadow);
    }
    .app-card-head {
      display: flex;
      align-items: center;
      gap: .85rem;
    }
    .app-icon {
      width: 2.6rem;
      height: 2.6rem;
      border-radius: .9rem;
      border: 1px solid var(--line);
      background: var(--surface);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      box-shadow: 0 4px 10px rgba(0,0,0,.08);
    }
    .app-icon svg {
      width: 1.15rem;
      height: 1.15rem;
      stroke: var(--text);
      stroke-width: 1.8;
      fill: none;
    }
    .app-icon img {
      width: 1.45rem;
      height: 1.45rem;
      object-fit: contain;
      display: block;
    }
    .app-card h3 {
      margin: 0;
      font-size: 1.15rem;
      font-weight: 700;
    }
    .app-card p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.6;
      flex: 1;
    }
    .app-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .8rem;
      padding-top: .85rem;
      border-top: 1px solid var(--line);
    }
    .app-tag {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .38rem .65rem;
      border-radius: var(--radius-full);
      background: var(--surface2);
      border: 1px solid var(--line);
      color: var(--muted);
      font-size: .74rem;
      font-weight: 600;
      white-space: nowrap;
    }
    .app-link {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .42rem .95rem;
      border-radius: var(--radius-full);
      background: var(--brand-accent);
      color: #fff;
      font-size: .85rem;
      font-weight: 700;
      text-decoration: none;
      transition: opacity .2s ease, transform .2s ease;
      white-space: nowrap;
    }
    .app-link:hover {
      opacity: .88;
      transform: scale(1.03);
    }

    .premium-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
    }
    .premium-card {
      background: var(--surface2);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .premium-card:hover {
      transform: translateY(-4px);
      border-color: var(--brand-accent);
    }
    .premium-card h3 {
      margin: 0 0 .55rem;
      font-size: 1.05rem;
    }
    .premium-card p {
      margin: 0 0 .75rem;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.55;
    }
    .premium-metric {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .9rem;
      color: var(--muted);
    }

    .cta-section {
      text-align: center;
      padding: 3rem;
      background: var(--surface2);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      margin: 0;
    }
    .cta-section h2 {
      margin: 0 0 .65rem;
    }
    .cta-section p {
      color: var(--muted);
      margin: 0 0 1.25rem;
    }

    .reveal {
      opacity: 0;
      transform: translateY(16px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }

    @media (max-width: 960px) {
      .hero-grid,
      .apps-intro,
      .apps-grid,
      .premium-grid {
        grid-template-columns: 1fr;
      }
      .hero {
        padding: var(--landing-section-space-mobile) 0;
        text-align: center;
      }
      .hero-grid {
        gap: 1.1rem;
      }
      .hero h1 {
        font-size: clamp(2rem, 10vw, 2.85rem);
        margin-bottom: .85rem;
      }
      .lead {
        margin-bottom: 1.2rem;
        font-size: 1rem;
        margin-left: auto;
        margin-right: auto;
      }
      .hero-cta {
        justify-content: center;
      }
      .section {
        padding: var(--landing-section-space-mobile) 0;
      }
      .apps-points {
        justify-content: flex-start;
      }
      .hero-art {
        margin-top: .35rem;
      }
      .hero-art::before {
        width: min(360px, 88%);
        opacity: .38;
      }
      .hero-art > img {
        max-width: min(370px, 92vw);
        animation: none;
      }
    }

    @media (max-width: 640px) {
      .hero {
        padding: var(--landing-section-space-mobile) 0;
      }
      .hero-cta {
        flex-direction: column;
        align-items: center;
        gap: .85rem;
      }
      .hero-cta .btn {
        width: min(100%, 260px);
      }
    }

    .hero:has(+ .clone-announce) {
      padding-bottom: 1.75rem;
    }
    .clone-announce {
      padding: 0;
    }
    .clone-announce + .section {
      padding-top: 4.25rem;
    }
    .clone-announce-card {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      padding: 1.15rem 1.35rem;
      border-radius: 1.25rem;
      border: 1px solid color-mix(in oklab, var(--a) 34%, var(--line));
      background:
        radial-gradient(90% 140% at 0% 0%, color-mix(in oklab, var(--a) 16%, transparent), transparent 58%),
        var(--surface);
      box-shadow: 0 18px 40px color-mix(in oklab, var(--a) 12%, transparent);
    }
    html[dir="rtl"] .clone-announce-card {
      background:
        radial-gradient(90% 140% at 100% 0%, color-mix(in oklab, var(--a) 16%, transparent), transparent 58%),
        var(--surface);
    }
    .clone-announce-mark {
      width: 3.5rem;
      height: 3.5rem;
      flex: none;
      border-radius: 1rem;
      display: grid;
      place-items: center;
      color: var(--a);
      background: color-mix(in oklab, var(--a) 14%, var(--surface2));
      border: 1px solid color-mix(in oklab, var(--a) 28%, transparent);
    }
    .clone-announce-wave {
      width: 2.05rem;
      height: 2.05rem;
      display: block;
    }
    .clone-announce-copy {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: .2rem;
    }
    .clone-announce-copy h2 {
      margin: 0;
      text-align: start;
      font-size: clamp(1.2rem, 2vw, 1.55rem);
      line-height: 1.35;
      letter-spacing: -.02em;
    }
    html[dir="rtl"] .clone-announce-copy h2 {
      letter-spacing: 0;
      line-height: 1.5;
      font-size: clamp(1.25rem, 2vw, 1.6rem);
    }
    .clone-announce-copy p {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.6;
      max-width: 46ch;
    }
    html[dir="rtl"] .clone-announce-copy p {
      font-size: 1.02rem;
      line-height: 1.75;
    }
    .clone-announce-card .btn {
      flex: none;
      min-height: 48px;
      margin-inline-start: auto;
      white-space: nowrap;
      padding-inline: 1.45rem;
    }
    .clone-announce-card .btn:focus-visible {
      outline: 2px solid var(--text);
      outline-offset: 3px;
    }
    @media (max-width: 760px) {
      .hero:has(+ .clone-announce) {
        padding-bottom: 1.25rem;
      }
      .clone-announce + .section {
        padding-top: 2.75rem;
      }
      .clone-announce-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: .85rem 1rem;
        padding: 1.05rem 1rem 1rem;
      }
      .clone-announce-card .btn {
        grid-column: 1 / -1;
        width: 100%;
        margin-inline-start: 0;
        margin-top: .15rem;
      }
    }

    .active-caption {
      opacity: 0;
      transform: translateY(-8px);
    }

    .idle-caption {
      opacity: 1;
      transform: translateY(0);
    }

    .idle-caption::after {
      content: '';
      display: inline-block;
      width: 4px;
      height: 1.1em;
      background: var(--brand-accent);
      margin-left: 6px;
      vertical-align: text-bottom;
      animation: blinkCaret 0.9s step-end infinite;
      box-shadow: 0 0 8px color-mix(in oklab, var(--a) 60%, var(--b) 40%);
      border-radius: 1px;
      opacity: 1;
      transition: opacity 0.18s ease;
    }

    @keyframes blinkCaret {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }

    @media (max-width: 980px) {
      .hero-grid {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .hero-cta {
        justify-content: center;
      }
      .hero-art {
        justify-content: center;
      }
      .apps-grid,
      .premium-grid {
        grid-template-columns: 1fr;
      }
      .apps-intro {
        grid-template-columns: 1fr;
        text-align: center;
      }
      /* Phones: the app cards line up with every other section. */
      .apps-shell {
        padding-inline: 0;
      }
      .apps-points {
        justify-content: center;
      }
    }

    @media (max-width: 640px) {
      .hero-art {
        padding-inline: .5rem;
      }
      .bg-orb {
        animation: none;
      }
      .active-caption {
        transform: translate(-50%, -8px);
      }
      .idle-caption {
        transform: translate(-50%, 0);
      }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <div class="reveal">
          <!-- <span class="eyebrow">Enterprise Voice Platform</span> -->
          <h1>{{ __('ui.landing.hero_title_before') }} <span class="brand-text">{{ __('ui.landing.hero_title_highlight') }}</span> {{ __('ui.landing.hero_title_after') }}</h1>
          <p class="lead">{{ __('ui.landing.hero_subtitle') }}</p>
          <div class="hero-cta">
            @guest
              <a class="btn btn-primary" href="{{ route('demo.page') }}">{{ __('ui.landing.try_demo') }}</a>
              @if(!empty($trialPromo['show']))
                <a class="btn btn-trial" href="{{ route('trial.start') }}">{{ __('ui.landing.trial_button', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</a>
              @else
                <a class="btn" href="{{ route('register') }}">{{ __('ui.landing.create_free_account') }}</a>
              @endif
            @else
              <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
              @if(!empty($trialPromo['show']))
                <a class="btn btn-trial" href="{{ route('trial.start') }}">{{ __('ui.landing.trial_button', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</a>
              @else
                <a class="btn" href="{{ route('browsers-extension.page') }}">{{ __('ui.landing.browser_extension') }}</a>
              @endif
            @endguest
          </div>
        </div>

        <aside class="hero-art reveal">
          @include('components.home.hero-art')
        </aside>
      </div>
    </section>

    <section class="clone-announce" aria-labelledby="cloneAnnounceTitle">
      <div class="clone-announce-card">
        <div class="clone-announce-mark" aria-hidden="true">
          <svg class="clone-announce-wave" viewBox="0 0 128 128" fill="currentColor">
            <rect x="8" y="59" width="10" height="10" rx="5"/>
            <rect x="26" y="42" width="10" height="44" rx="5"/>
            <rect x="44" y="28" width="10" height="72" rx="5"/>
            <rect x="62" y="14" width="10" height="100" rx="5"/>
            <rect x="80" y="28" width="10" height="72" rx="5"/>
            <rect x="98" y="42" width="10" height="44" rx="5"/>
            <rect x="116" y="59" width="10" height="10" rx="5"/>
          </svg>
        </div>
        <div class="clone-announce-copy">
          <h2 id="cloneAnnounceTitle">{{ __('ui.landing.clone_announce_title') }}</h2>
          <p>{{ __('ui.landing.clone_announce_body') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('pricing') }}">{{ __('ui.landing.clone_announce_cta') }}</a>
      </div>
    </section>

    {{-- Voice gallery and dialogues --}}
    @include('components.home.voice-gallery')
    @include('components.home.dialogues')

    <section class="section reveal">
      <div class="apps-shell">
        <div class="apps-intro">
          <div class="apps-copy">
            <!-- <span class="apps-kicker">{{ __('ui.landing.section1_eyebrow') }}</span> -->
            <h2>{{ __('ui.landing.section1_title') }}</h2>
            <p class="section-lead">{{ __('ui.landing.section1_subtitle') }}</p>
          </div>
          <!-- <div class="apps-points" aria-hidden="true">
            <span class="apps-point">{{ __('ui.landing.section1_point1') }}</span>
            <span class="apps-point">{{ __('ui.landing.section1_point2') }}</span>
            <span class="apps-point">{{ __('ui.landing.section1_point3') }}</span>
          </div> -->
        </div>
        <div class="apps-grid">
          <article class="app-card reveal">
            <div class="app-card-head">
              <div class="app-icon" aria-hidden="true" style="filter: grayscale(100%) contrast(1.2) brightness(1.2);">
                <svg viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="8.5"></circle>
                  <path d="M3.5 12h17"></path>
                  <path d="M12 3.5a14 14 0 0 1 0 17"></path>
                  <path d="M12 3.5a14 14 0 0 0 0 17"></path>
                </svg>
              </div>
              <h3>{{ __('ui.landing.section1_card1_title') }}</h3>
            </div>
            <p>{{ __('ui.landing.section1_card1_body') }}</p>
            <div class="app-card-footer">
              @auth
                <a class="app-link" href="{{ route('dashboard') }}">{{ __('ui.landing.section1_card1_cta') }}</a>
              @else
                <a class="app-link" href="{{ route('register') }}">{{ __('ui.landing.section1_card1_cta') }}</a>
              @endauth
            </div>
          </article>
          <article class="app-card reveal">
            <div class="app-card-head">
              <div class="app-icon" aria-hidden="true" style="filter: grayscale(100%) contrast(1.2) brightness(1.2);">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <circle cx="12" cy="12" r="4"></circle>
                  <line x1="21.17" y1="8" x2="12" y2="8"></line>
                  <line x1="3.95" y1="6.06" x2="8.54" y2="14"></line>
                  <line x1="10.88" y1="21.94" x2="15.46" y2="14"></line>
                </svg>
              </div>
              <h3>{{ __('ui.landing.section1_card2_title') }}</h3>
            </div>
            <p>{{ __('ui.landing.section1_card2_body') }}</p>
            <div class="app-card-footer">
              <a class="app-link" href="{{ route('browsers-extension.page') }}">{{ __('ui.landing.section1_card2_cta') }}</a>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section class="section reveal">
      <h2>{{ __('ui.landing.section2_title') }}</h2>
      <p class="section-lead">{{ __('ui.landing.section2_subtitle') }}</p>
      <div class="premium-grid">
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card1_title') }}</h3>
          <p>{{ __('ui.landing.section2_card1_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card1_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card2_title') }}</h3>
          <p>{{ __('ui.landing.section2_card2_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card2_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card3_title') }}</h3>
          <p>{{ __('ui.landing.section2_card3_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card3_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card4_title') }}</h3>
          <p>{{ __('ui.landing.section2_card4_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card4_metric') }}</span>
        </article>
      </div>
    </section>

    <section class="section reveal">
      <div class="cta-section">
        <h2>{{ __('ui.landing.cta_title') }}</h2>
        <p>{{ __('ui.landing.cta_subtitle') }}</p>
        @auth
          <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
        @else
          <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.landing.get_started_free') }}</a>
        @endauth
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.voice-player-script')
</body>
</html>
