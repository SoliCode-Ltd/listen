@include('components.page-head', ['pageTitle' => '', 'page' => $page, 'faqItems' => $faqItems ?? []])
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
    .seo-crumbs {
      width: min(1100px, 100%);
      margin: 0 auto 1rem;
      font-size: .85rem;
      color: var(--muted);
    }
    .seo-crumbs ol {
      list-style: none;
      display: flex;
      flex-wrap: wrap;
      gap: .4rem;
      margin: 0;
      padding: 0;
    }
    .seo-crumbs li + li::before {
      content: "/";
      margin-inline-end: .4rem;
      opacity: .6;
    }
    .seo-crumbs a {
      color: inherit;
      text-decoration: none;
    }
    .seo-crumbs a:hover {
      text-decoration: underline;
    }
    .seo-links-title {
      margin: 0 0 1rem;
      font-size: 1.05rem;
      font-weight: 700;
    }
    .seo-sample {
      margin-top: 1.4rem;
      padding: 1rem 1.1rem;
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      background: var(--surface);
    }
    .seo-sample-label {
      display: block;
      margin-bottom: .4rem;
      font-size: .8rem;
      color: var(--muted);
    }
    .seo-sample q {
      display: block;
      margin-bottom: .7rem;
      quotes: none;
      color: var(--text);
      line-height: 1.7;
    }
    .seo-sample audio {
      width: 100%;
    }
    .seo-chips {
      display: flex;
      flex-wrap: wrap;
      gap: .55rem;
      margin: 0;
      padding: 0;
      list-style: none;
    }
    .seo-chips li {
      padding: .45rem .85rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface);
      font-size: 1rem;
    }
    .seo-chips a {
      color: inherit;
      text-decoration: none;
    }
    .seo-chips a:hover {
      color: var(--brand-accent);
    }
    .seo-region + .seo-region {
      margin-top: 1.25rem;
    }
    .seo-region h3 {
      margin: 0 0 .7rem;
      font-size: 1rem;
      color: var(--muted);
    }
    .seo-copy .seo-cta-actions {
      justify-content: flex-start;
    }
    .seo-accent-note {
      font-size: .92rem;
    }
  </style>
</head>
<body>
  @php
    $isArabic = app()->getLocale() === 'ar' || str_starts_with((string) app()->getLocale(), 'ar');
    $relatedLinks = [
      ['label' => __('ui.nav.text_to_speech'), 'url' => route('seo.text-to-speech'), 'page' => 'text_to_speech'],
      ['label' => __('ui.nav.ai_text_to_speech'), 'url' => route('seo.ai-text-to-speech'), 'page' => 'ai_text_to_speech'],
      ['label' => __('ui.nav.text_to_speech_arabic'), 'url' => route('seo.text-to-speech-arabic'), 'page' => 'text_to_speech_arabic'],
      ['label' => __('ui.nav.arabic_dialects'), 'url' => route('seo.arabic-dialects'), 'page' => 'arabic_dialects'],
      ['label' => __('ui.nav.ai_voice_generator'), 'url' => route('seo.ai-voice-generator'), 'page' => 'ai_voice_generator'],
      ['label' => __('ui.nav.pricing'), 'url' => route('pricing'), 'page' => 'pricing'],
      ['label' => __('ui.nav.extension'), 'url' => route('browsers-extension.page'), 'page' => 'extension'],
    ];
    $relatedLinks = array_values(array_filter($relatedLinks, fn ($link) => $link['page'] !== $page));
    $breadcrumbs = \App\Support\SeoHelper::breadcrumbs($page);
    $dialectLinks = \App\Support\SeoDialectPages::popular($isArabic ? 'ar' : 'en', 13);
    $dialectLinks = array_values(array_filter($dialectLinks, fn ($link) => $link['slug'] !== ($currentDialect ?? null)));
  @endphp
  @include('components.header')

  <main class="seo-page container">
    @if(count($breadcrumbs) > 1)
      <nav class="seo-crumbs" aria-label="{{ $isArabic ? 'مسار التنقل' : 'Breadcrumb' }}">
        <ol>
          @foreach($breadcrumbs as $crumb)
            <li>
              @if($loop->last)
                <span aria-current="page">{{ $crumb['name'] }}</span>
              @else
                <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
              @endif
            </li>
          @endforeach
        </ol>
      </nav>
    @endif

    <section class="seo-hero">
      <article class="seo-card seo-copy">
        <span class="seo-eyebrow">{{ $pageContent['eyebrow'] ?? '' }}</span>
        <h1>{{ $pageContent['h1'] ?? '' }}</h1>
        <p>{{ $pageContent['intro'] ?? '' }}</p>
        @if(! empty($sample['audio_url']))
          <figure class="seo-sample">
            <span class="seo-sample-label">{{ $isArabic ? 'استمع إلى عينة حقيقية' : 'Listen to a real sample' }}</span>
            @if(($sample['text'] ?? '') !== '')
              <q dir="rtl" lang="ar">{{ $sample['text'] }}</q>
            @endif
            <audio controls preload="none" src="{{ $sample['audio_url'] }}"></audio>
          </figure>
        @endif
        <div class="seo-cta-actions">
          <a class="btn btn-primary" href="{{ $ctaPrimaryUrl }}">{{ $pageContent['cta_primary'] ?? __('ui.nav.get_started_free') }}</a>
        </div>
      </article>

      <nav class="seo-card seo-links" aria-label="{{ __('ui.footer.solutions') }}">
        <p class="seo-links-title">{{ __('ui.footer.solutions') }}</p>
        <div class="seo-link-list">
          @foreach($relatedLinks as $link)
            <a class="seo-link-item" href="{{ $link['url'] }}">
              <span>{{ $link['label'] }}</span>
              <span aria-hidden="true">{{ $isArabic ? '←' : '→' }}</span>
            </a>
          @endforeach
        </div>
      </nav>
    </section>

    @if(! empty($dialectGroups))
      <section class="seo-section">
        <h2>{{ $isArabic ? 'اختر اللهجة' : 'Choose a dialect' }}</h2>
        @foreach($dialectGroups as $group)
          <div class="seo-region">
            <h3>{{ $group['region'] }}</h3>
            <ul class="seo-chips">
              @foreach($group['items'] as $item)
                <li><a href="{{ route('seo.dialect', ['dialect' => $item['slug']]) }}">{{ $item['label'] }}@if($item['accents'] > 1) <small>({{ $item['accents'] }})</small>@endif</a></li>
              @endforeach
            </ul>
          </div>
        @endforeach
      </section>
    @endif

    @if(! empty($pageContent['accents']))
      <section class="seo-section">
        <h2>{{ $pageContent['accents_title'] ?? '' }}</h2>
        <p class="seo-lead">{{ $pageContent['accents_intro'] ?? '' }}</p>
        <div class="seo-grid">
          @foreach($pageContent['accents'] as $accent)
            <article class="seo-card seo-feature">
              <h3>{{ $accent['title'] ?? '' }}</h3>
              @if(($accent['body'] ?? '') !== '')
                <p class="seo-accent-note">{{ $accent['body'] }}</p>
              @endif
            </article>
          @endforeach
        </div>
      </section>
    @endif

    @if(! empty($pageContent['words_title']))
      <section class="seo-section">
        <h2>{{ $pageContent['words_title'] }}</h2>
        <p class="seo-lead">{{ $pageContent['words_intro'] ?? '' }}</p>
        @if(! empty($pageContent['words']))
          <ul class="seo-chips" lang="ar" dir="rtl">
            @foreach($pageContent['words'] as $word)
              <li>{{ $word }}</li>
            @endforeach
          </ul>
        @endif
      </section>
    @endif

    @if(! empty($pageContent['highlights']))
      <section class="seo-section">
        <h2>{{ $pageContent['highlights_title'] ?? __('ui.pricing.included_title') }}</h2>
        <p class="seo-lead">{{ $pageContent['highlights_intro'] ?? ($pageContent['intro'] ?? '') }}</p>
        <div class="seo-grid">
          @foreach($pageContent['highlights'] as $item)
            <article class="seo-card seo-feature">
              <h3>{{ $item['title'] ?? '' }}</h3>
              <p>{{ $item['body'] ?? '' }}</p>
            </article>
          @endforeach
        </div>
      </section>
    @endif

    @if(! empty($pageContent['use_cases']))
      <section class="seo-section">
        <h2>{{ $pageContent['use_cases_title'] ?? __('ui.pricing.feature3_title') }}</h2>
        <p class="seo-lead">{{ $pageContent['use_cases_intro'] ?? __('ui.footer.tagline') }}</p>
        <div class="seo-grid">
          @foreach($pageContent['use_cases'] as $useCase)
            @php
              $useCaseTitle = is_array($useCase) ? (string) ($useCase['title'] ?? '') : (string) $useCase;
              $useCaseBody = is_array($useCase)
                ? (string) ($useCase['body'] ?? '')
                : (string) ($pageContent['intro'] ?? '');
            @endphp
            <article class="seo-card seo-use-case">
              <h3>{{ $useCaseTitle }}</h3>
              <p>{{ $useCaseBody }}</p>
            </article>
          @endforeach
        </div>
      </section>
    @endif

    @if(empty($dialectGroups) && $dialectLinks !== [])
      <section class="seo-section">
        <h2>{{ isset($currentDialect) ? ($isArabic ? 'لهجات عربية أخرى' : 'Other Arabic dialects') : ($isArabic ? 'تحويل النص إلى صوت باللهجات العربية' : 'Text to speech in Arabic dialects') }}</h2>
        <ul class="seo-chips">
          @foreach($dialectLinks as $link)
            <li><a href="{{ route('seo.dialect', ['dialect' => $link['slug']]) }}">{{ $link['label'] }}</a></li>
          @endforeach
          <li><a href="{{ route('seo.arabic-dialects') }}">{{ $isArabic ? 'كل اللهجات' : 'All dialects' }}</a></li>
        </ul>
      </section>
    @endif

    @if(! empty($faqItems))
      <section class="seo-section">
        <h2>{{ $pageContent['faq_title'] ?? __('ui.pricing.faq_title') }}</h2>
        <div class="seo-grid">
          @foreach($faqItems as $faq)
            <article class="seo-card seo-faq-item">
              <h3>{{ $faq['question'] ?? '' }}</h3>
              <p>{{ $faq['answer'] ?? '' }}</p>
            </article>
          @endforeach
        </div>
      </section>
    @endif

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
