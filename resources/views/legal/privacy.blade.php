@include('components.page-head', ['pageTitle' => __('ui.seo.privacy_title'), 'page' => 'privacy'])
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .2;
      animation: float 10s ease-in-out infinite;
    }
    .bg-orb.a {
      width: 14rem;
      height: 14rem;
      background: color-mix(in oklab, var(--a) 72%, white 28%);
      left: -5rem;
      top: 12vh;
    }
    .bg-orb.b {
      width: 18rem;
      height: 18rem;
      background: color-mix(in oklab, var(--b) 72%, white 28%);
      right: -5rem;
      bottom: 6vh;
      animation-delay: -3s;
    }
    .legal-page {
      padding: 2.5rem 0 3rem;
    }
    .legal-shell {
      width: min(860px, 100%);
      margin: 0 auto;
      padding: 1.5rem;
      border-radius: var(--radius-lg);
      border: 1px solid var(--line);
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      box-shadow: var(--shadow);
    }
    .legal-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      margin-bottom: 1rem;
      padding: .38rem .74rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .78rem;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .legal-shell h1 {
      margin: 0 0 .5rem;
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      letter-spacing: -.02em;
    }
    .legal-updated {
      margin: 0 0 1.2rem;
      color: var(--muted);
      font-size: .92rem;
    }
    .legal-intro {
      margin: 0 0 1.5rem;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section + .legal-section {
      margin-top: 1.3rem;
    }
    .legal-section h2 {
      margin: 0 0 .5rem;
      font-size: 1.15rem;
    }
    .legal-section p {
      margin: 0;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section ul {
      margin: .55rem 0 0 0;
      padding-left: 1.15rem;
      color: var(--muted);
      list-style: disc;
    }
    .legal-section li {
      margin: .35rem 0;
      line-height: 1.6;
    }
    .legal-section a {
      color: var(--text);
      text-underline-offset: 3px;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-12px); }
    }
    @media (max-width: 640px) {
      .legal-page {
        padding: 1.5rem 0 2rem;
      }
      .legal-shell {
        padding: 1.1rem;
      }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container legal-page">
    @php
      $effectiveDate = app()->getLocale() === 'ar' ? '22 فبراير 2026' : 'February 22, 2026';
      $supportEmailLink = '<a href="mailto:support@listenai.studio">support@listenai.studio</a>';
    @endphp
    <article class="legal-shell">
      <span class="legal-kicker">{{ __('ui.legal.kicker') }}</span>
      <h1>{{ __('ui.legal.privacy.title') }}</h1>
      <p class="legal-updated">{{ __('ui.legal.effective_date', ['date' => $effectiveDate]) }}</p>
      <p class="legal-intro">{{ __('ui.legal.privacy.intro') }}</p>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s1_title') }}</h2>
        <ul>
          <li>{{ __('ui.legal.privacy.s1_1') }}</li>
          <li>{{ __('ui.legal.privacy.s1_2') }}</li>
          <li>{{ __('ui.legal.privacy.s1_3') }}</li>
          <li>{{ __('ui.legal.privacy.s1_4') }}</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s2_title') }}</h2>
        <ul>
          <li>{{ __('ui.legal.privacy.s2_1') }}</li>
          <li>{{ __('ui.legal.privacy.s2_2') }}</li>
          <li>{{ __('ui.legal.privacy.s2_3') }}</li>
          <li>{{ __('ui.legal.privacy.s2_4') }}</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s3_title') }}</h2>
        <p>{{ __('ui.legal.privacy.s3_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s4_title') }}</h2>
        <p>{{ __('ui.legal.privacy.s4_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s5_title') }}</h2>
        <p>{{ __('ui.legal.privacy.s5_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s6_title') }}</h2>
        <p>{{ __('ui.legal.privacy.s6_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s7_title') }}</h2>
        <p>{!! __('ui.legal.privacy.s7_body', ['email' => $supportEmailLink]) !!}</p>
      </section>
    </article>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>

