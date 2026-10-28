@include('components.page-head', ['pageTitle' => __('ui.seo.privacy_title'), 'page' => 'privacy'])
  <style>
    .legal-page {
      padding: 2.5rem 0 3rem;
    }
    .legal-shell {
      width: min(860px, 100%);
      margin: 0 auto;
      padding: clamp(1.5rem, 3vw, 2.5rem);
      border-radius: var(--card-radius);
      border: 1px solid var(--line);
      background: var(--surface);
      box-shadow: var(--card-shadow);
    }
    .legal-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      margin-bottom: 1rem;
      padding: .38rem .74rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .8rem;
      font-weight: 600;
      color: var(--muted);
    }
    .legal-shell h1 {
      margin: 0 0 .5rem;
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      font-weight: 600;
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

  @include('components.header')

  <main class="container legal-page">
    @php
      $effectiveDate = app()->getLocale() === 'ar' ? '28 أكتوبر 2026' : 'October 28, 2026';
      $contactEmail = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
      $supportEmailLink = '<a href="mailto:'.e($contactEmail).'">'.e($contactEmail).'</a>';
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
          <li>{{ __('ui.legal.privacy.s1_5') }}</li>
          <li>{{ __('ui.legal.privacy.s1_6') }}</li>
          <li>{{ __('ui.legal.privacy.s1_7') }}</li>
          <li>{{ __('ui.legal.privacy.s1_8') }}</li>
          <li>{{ __('ui.legal.privacy.s1_9') }}</li>
          <li>{{ __('ui.legal.privacy.s1_10') }}</li>
          <li>{{ __('ui.legal.privacy.s1_11') }}</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.privacy.s2_title') }}</h2>
        <ul>
          <li>{{ __('ui.legal.privacy.s2_1') }}</li>
          <li>{{ __('ui.legal.privacy.s2_2') }}</li>
          <li>{{ __('ui.legal.privacy.s2_3') }}</li>
          <li>{{ __('ui.legal.privacy.s2_4') }}</li>
          <li>{{ __('ui.legal.privacy.s2_5') }}</li>
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
        <p>{!! __('ui.legal.privacy.s6_body', ['email' => $supportEmailLink]) !!}</p>
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

