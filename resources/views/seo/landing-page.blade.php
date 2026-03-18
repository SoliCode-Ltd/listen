@include('components.page-head', ['pageTitle' => $pageContent['h1'] ?? '', 'page' => $page, 'faqItems' => $faqItems ?? []])
  <style>
    .seo-page {
      padding: 2.5rem 0 4rem;
    }
    .seo-hero,
    .seo-section,
    .seo-cta {
      width: min(1100px, 100%);
      margin: 0 auto;
    }
    .seo-hero {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr);
      gap: 1.25rem;
      align-items: stretch;
      margin-bottom: 1.5rem;
    }
    .seo-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
    }
    .seo-copy {
      padding: 2rem;
    }
    .seo-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .4rem .78rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      color: var(--muted);
      font-size: .78rem;
      letter-spacing: .08em;
      text-transform: uppercase;
      margin-bottom: 1rem;
    }
    .seo-eyebrow::before {
      content: "";
      width: .45rem;
      height: .45rem;
      border-radius: 50%;
      background: var(--brand-accent);
    }
    .seo-copy h1 {
      margin: 0 0 1rem;
      font-size: clamp(2.2rem, 4vw, 3.7rem);
      line-height: 1.05;
      letter-spacing: -.03em;
    }
    html[dir="rtl"] .seo-copy h1 {
      line-height: 1.22;
      letter-spacing: 0;
    }
    .seo-copy p {
      margin: 0;
      color: var(--muted);
      font-size: 1.06rem;
      line-height: 1.7;
      max-width: 58ch;
    }
    .seo-links {
      padding: 1.5rem;
      display: grid;
      gap: .75rem;
      align-content: start;
    }
    .seo-links h2 {
      margin: 0;
      font-size: 1rem;
    }
    .seo-link-list {
      display: grid;
      gap: .7rem;
    }
    .seo-link-item {
      display: flex;
      justify-content: space-between;
      gap: .9rem;
      align-items: center;
      text-decoration: none;
      color: var(--text);
      padding: .9rem 1rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 84%, transparent);
      transition: transform .2s ease, border-color .2s ease;
    }
    .seo-link-item:hover {
      transform: translateY(-2px);
      border-color: var(--brand-accent);
    }
    .seo-link-item span:last-child {
      color: var(--muted);
      font-size: .88rem;
    }
    .seo-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .seo-section {
      margin-top: 1rem;
    }
    .seo-section h2 {
      margin: 0 0 .55rem;
      font-size: clamp(1.4rem, 3vw, 2.1rem);
    }
    .seo-lead {
      margin: 0 0 1.2rem;
      color: var(--muted);
      max-width: 65ch;
      line-height: 1.7;
    }
    .seo-feature,
    .seo-use-case,
    .seo-faq-item,
    .seo-cta {
      padding: 1.35rem;
    }
    .seo-feature h3,
    .seo-use-case h3,
    .seo-faq-item h3 {
      margin: 0 0 .5rem;
      font-size: 1.02rem;
    }
    .seo-feature p,
    .seo-use-case p,
    .seo-faq-item p,
    .seo-cta p {
      margin: 0;
      color: var(--muted);
      line-height: 1.65;
    }
    .seo-cta {
      margin-top: 1rem;
      text-align: center;
    }
    .seo-cta h2 {
      margin: 0 0 .65rem;
      font-size: clamp(1.5rem, 3vw, 2.3rem);
    }
    .seo-cta-actions {
      display: flex;
      justify-content: center;
      gap: .75rem;
      margin-top: 1.15rem;
      flex-wrap: wrap;
    }
    @media (max-width: 980px) {
      .seo-hero,
      .seo-grid {
        grid-template-columns: 1fr;
      }
      .seo-copy,
      .seo-links {
        padding: 1.35rem;
      }
    }
  </style>
</head>
<body>
  @php
    $relatedLinks = [
      ['label' => __('ui.nav.home'), 'url' => route('home')],
      ['label' => __('ui.nav.pricing'), 'url' => route('pricing')],
      ['label' => __('ui.nav.demo'), 'url' => route('demo.page')],
      ['label' => __('ui.nav.extension'), 'url' => route('browsers-extension.page')],
      ['label' => __('ui.nav.contact'), 'url' => route('contact')],
    ];
  @endphp
  @include('components.header')

  <main class="seo-page container">
    <section class="seo-hero">
      <article class="seo-card seo-copy">
        <span class="seo-eyebrow">{{ $pageContent['eyebrow'] ?? '' }}</span>
        <h1>{{ $pageContent['h1'] ?? '' }}</h1>
        <p>{{ $pageContent['intro'] ?? '' }}</p>
      </article>

      <aside class="seo-card seo-links">
        <h2>{{ __('ui.footer.product') }}</h2>
        <div class="seo-link-list">
          @foreach($relatedLinks as $link)
            <a class="seo-link-item" href="{{ $link['url'] }}">
              <span>{{ $link['label'] }}</span>
              <span>{{ __('ui.nav.get_started') }}</span>
            </a>
          @endforeach
        </div>
      </aside>
    </section>

    <section class="seo-section">
      <h2>{{ __('ui.pricing.included_title') }}</h2>
      <p class="seo-lead">{{ $pageContent['intro'] ?? '' }}</p>
      <div class="seo-grid">
        @foreach($pageContent['highlights'] ?? [] as $item)
          <article class="seo-card seo-feature">
            <h3>{{ $item['title'] ?? '' }}</h3>
            <p>{{ $item['body'] ?? '' }}</p>
          </article>
        @endforeach
      </div>
    </section>

    <section class="seo-section">
      <h2>{{ __('ui.pricing.feature3_title') }}</h2>
      <p class="seo-lead">{{ __('ui.footer.tagline') }}</p>
      <div class="seo-grid">
        @foreach($pageContent['use_cases'] ?? [] as $useCase)
          <article class="seo-card seo-use-case">
            <h3>{{ $useCase }}</h3>
            <p>{{ $pageContent['intro'] ?? '' }}</p>
          </article>
        @endforeach
      </div>
    </section>

    <section class="seo-section">
      <h2>{{ __('ui.pricing.faq_title') }}</h2>
      <div class="seo-grid">
        @foreach($faqItems ?? [] as $faq)
          <article class="seo-card seo-faq-item">
            <h3>{{ $faq['question'] ?? '' }}</h3>
            <p>{{ $faq['answer'] ?? '' }}</p>
          </article>
        @endforeach
      </div>
    </section>

    <section class="seo-card seo-cta">
      <h2>{{ $pageContent['cta_title'] ?? '' }}</h2>
      <p>{{ $pageContent['cta_body'] ?? '' }}</p>
      <div class="seo-cta-actions">
        <a class="btn btn-primary" href="{{ $ctaPrimaryUrl }}">{{ $pageContent['cta_primary'] ?? __('ui.nav.get_started_free') }}</a>
        <a class="btn btn-ghost" href="{{ route('pricing') }}">{{ __('ui.nav.pricing') }}</a>
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
