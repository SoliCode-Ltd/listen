@include('components.page-head', ['pageTitle' => '', 'page' => $page, 'faqItems' => $faqItems ?? []])
  <link rel="stylesheet" href="{{ asset('css/voice-showcase.css') }}?v={{ filemtime(public_path('css/voice-showcase.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/seo-page.css') }}?v={{ filemtime(public_path('css/seo-page.css')) }}">
</head>
<body>
  @php
    $isArabic = app()->getLocale() === 'ar' || str_starts_with((string) app()->getLocale(), 'ar');
    $relatedLinks = [
      ['label' => __('ui.nav.text_to_speech'), 'url' => route('seo.text-to-speech'), 'page' => 'text_to_speech'],
      ['label' => __('ui.nav.ai_text_to_speech'), 'url' => route('seo.ai-text-to-speech'), 'page' => 'ai_text_to_speech'],
      ['label' => __('ui.nav.text_to_speech_arabic'), 'url' => route('seo.text-to-speech-arabic'), 'page' => 'text_to_speech_arabic'],
      ['label' => __('ui.nav.arabic_dialects'), 'url' => route('seo.arabic-dialects'), 'page' => 'arabic_dialects'],
      ['label' => __('ui.nav.english_accents'), 'url' => route('seo.english-accents'), 'page' => 'english_accents'],
      ['label' => __('ui.nav.ai_voice_generator'), 'url' => route('seo.ai-voice-generator'), 'page' => 'ai_voice_generator'],
      ['label' => __('ui.nav.pricing'), 'url' => route('pricing'), 'page' => 'pricing'],
      ['label' => __('ui.nav.extension'), 'url' => route('browsers-extension.page'), 'page' => 'extension'],
    ];
    $relatedLinks = array_values(array_filter($relatedLinks, fn ($link) => $link['page'] !== $page));
    $breadcrumbs = \App\Support\SeoHelper::breadcrumbs($page);
    $voiceLocale = $isArabic ? 'ar' : 'en';
    $dialectLinks = array_values(array_filter(
      \App\Support\SeoDialectPages::popular($voiceLocale, 13),
      fn ($link) => $link['slug'] !== ($currentDialect ?? null)
    ));
    $accentLinks = array_values(array_filter(
      \App\Support\SeoEnglishPages::popular($voiceLocale, 7),
      fn ($link) => $link['slug'] !== ($currentAccent ?? null)
    ));
    $regionLinks = array_map(
      fn ($slug) => ['url' => route('seo.dialect-region', ['region' => $slug]), 'label' => \App\Support\SeoDialectPages::regionTitle($slug, $voiceLocale)],
      array_values(array_filter(\App\Support\SeoDialectPages::regionSlugs(), fn ($slug) => $slug !== ($currentRegion ?? null)))
    );
    $featureLinks = array_map(
      fn ($l) => ['url' => route('seo.feature', ['feature' => $l['slug']]), 'label' => $l['label']],
      array_values(array_filter(\App\Support\SeoFeaturePages::links($voiceLocale), fn ($l) => $l['slug'] !== ($currentFeature ?? null)))
    );
    $voiceSections = [
      [
        'title' => isset($currentDialect) ? ($isArabic ? 'لهجات عربية أخرى' : 'Other Arabic dialects') : ($isArabic ? 'تحويل النص إلى صوت باللهجات العربية' : 'Text to speech in Arabic dialects'),
        'links' => array_merge($regionLinks, array_map(fn ($l) => ['url' => route('seo.dialect', ['dialect' => $l['slug']]), 'label' => $l['label']], $dialectLinks)),
        'all' => ['url' => route('seo.arabic-dialects'), 'label' => $isArabic ? 'كل اللهجات العربية' : 'All Arabic dialects'],
      ],
      [
        'title' => isset($currentAccent) ? ($isArabic ? 'لكنات إنجليزية أخرى' : 'Other English accents') : ($isArabic ? 'تحويل النص إلى صوت باللكنات الإنجليزية' : 'Text to speech in English accents'),
        'links' => array_map(fn ($l) => ['url' => route('seo.accent', ['accent' => $l['slug']]), 'label' => $l['label']], $accentLinks),
        'all' => ['url' => route('seo.english-accents'), 'label' => $isArabic ? 'كل اللكنات الإنجليزية' : 'All English accents'],
      ],
    ];
    if (isset($currentAccent) || $page === 'english_accents') {
      $voiceSections = array_reverse($voiceSections);
    }
    if ($featureLinks !== []) {
      $voiceSections[] = [
        'title' => isset($currentFeature) ? ($isArabic ? 'ميزات أخرى في الاستوديو' : 'More studio features') : ($isArabic ? 'ميزات الاستوديو' : 'Studio features'),
        'links' => $featureLinks,
        'all' => ['url' => route('developers'), 'label' => $isArabic ? 'واجهة برمجية لتحويل النص إلى صوت' : 'Text to speech API'],
      ];
    }
      $voiceSections[] = [
      'title' => __('ui.footer.solutions'),
      'links' => array_map(fn ($l) => ['url' => $l['url'], 'label' => $l['label']], $relatedLinks),
      'all' => null,
    ];
    if (! empty($dialectGroups)) {
      array_shift($voiceSections);
    }
    $samples = $samples ?? ['lead' => null, 'more' => [], 'title' => '', 'note' => ''];
    $lead = $samples['lead'];
    $facts = $isArabic
      ? [[\App\Support\StudioFacts::arabicDialects(), 'لهجة عربية'], [\App\Support\StudioFacts::englishAccents(), 'لكنة إنجليزية'], [\App\Support\StudioFacts::voices(), 'صوتاً'], [\App\Support\StudioFacts::emotions(), 'شعوراً']]
      : [[\App\Support\StudioFacts::arabicDialects(), 'Arabic dialects'], [\App\Support\StudioFacts::englishAccents(), 'English accents'], [\App\Support\StudioFacts::voices(), 'voices'], [\App\Support\StudioFacts::emotions(), 'emotions']];
  @endphp
  @include('components.header')

  <main class="sp container">
    <header class="sp-hero{{ $lead ? ' has-stage' : '' }}">
      <div class="sp-hero-copy">
        @if(count($breadcrumbs) > 1)
          <nav class="sp-crumbs" aria-label="{{ $isArabic ? 'مسار التنقل' : 'Breadcrumb' }}">
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
        <h1>{{ $pageContent['h1'] ?? '' }}</h1>
        <p class="sp-intro">{{ $pageContent['intro'] ?? '' }}</p>
        <div class="sp-actions">
          <a class="btn btn-primary" href="{{ $ctaPrimaryUrl }}">{{ $pageContent['cta_primary'] ?? __('ui.nav.get_started_free') }}</a>
          @if(! empty($samples['more']))
            <a class="btn btn-ghost" href="#samples">{{ $isArabic ? 'استمع إلى العينات' : 'Hear the samples' }}</a>
          @else
            <a class="btn btn-ghost" href="{{ route('pricing') }}">{{ __('ui.nav.pricing') }}</a>
          @endif
        </div>
        <dl class="sp-facts">
          @foreach($facts as [$number, $label])
            <div><dt>{{ $number }}</dt><dd>{{ $label }}</dd></div>
          @endforeach
        </dl>
      </div>
      @if($lead)
        <div class="sp-stage">
          <p class="sp-stage-label"><span class="sp-live" aria-hidden="true"></span>{{ $isArabic ? 'عينة حقيقية من الاستوديو' : 'A real sample from the studio' }}</p>
          @include('seo.partials.sample', ['card' => $lead, 'cardClass' => ' sp-lead'])
        </div>
      @endif
    </header>

    @if(! empty($samples['more']))
      <section class="sp-samples" id="samples">
        <div class="sp-head">
          <h2>{{ $samples['title'] }}</h2>
          @if($samples['note'] !== '')
            <p>{{ $samples['note'] }}</p>
          @endif
        </div>
        <div class="sp-sample-grid" data-count="{{ count($samples['more']) }}">
          @foreach($samples['more'] as $card)
            @include('seo.partials.sample', ['card' => $card, 'cardClass' => ''])
          @endforeach
        </div>
      </section>
    @endif

    @if(! empty($dialectGroups))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ match ($page) { 'english_accents' => $isArabic ? 'اختر اللكنة' : 'Choose an accent', default => $isArabic ? 'اختر اللهجة' : 'Choose a dialect' } }}</h2>
        </div>
        <div class="sp-body">
          @foreach($dialectGroups as $group)
            <div class="sp-region">
              <h3>{{ $group['region'] }}</h3>
              <ul class="sp-chips">
                @foreach($group['items'] as $item)
                  <li><a href="{{ $item['url'] }}">{{ $item['label'] }}@if($item['accents'] > 1) <small>({{ $item['accents'] }})</small>@endif</a></li>
                @endforeach
              </ul>
            </div>
          @endforeach
        </div>
      </section>
    @endif

    @if(! empty($pageContent['accents']))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ $pageContent['accents_title'] ?? '' }}</h2>
          <p>{{ $pageContent['accents_intro'] ?? '' }}</p>
        </div>
        <ul class="sp-body sp-list sp-list-tight">
          @foreach($pageContent['accents'] as $accent)
            <li>
              <h3>{{ $accent['title'] ?? '' }}</h3>
              @if(($accent['body'] ?? '') !== '')
                <p>{{ $accent['body'] }}</p>
              @endif
            </li>
          @endforeach
        </ul>
      </section>
    @endif

    @if(! empty($pageContent['words_title']))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ $pageContent['words_title'] }}</h2>
          <p>{{ $pageContent['words_intro'] ?? '' }}</p>
        </div>
        @if(! empty($pageContent['words']))
          <ul class="sp-body sp-chips sp-words" lang="ar" dir="rtl">
            @foreach($pageContent['words'] as $word)
              <li>{{ $word }}</li>
            @endforeach
          </ul>
        @endif
      </section>
    @endif

    @if(! empty($pageContent['highlights']))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ $pageContent['highlights_title'] ?? __('ui.pricing.included_title') }}</h2>
          <p>{{ $pageContent['highlights_intro'] ?? ($pageContent['intro'] ?? '') }}</p>
        </div>
        <ul class="sp-body sp-list">
          @foreach($pageContent['highlights'] as $item)
            <li>
              <h3>{{ $item['title'] ?? '' }}</h3>
              <p>{{ $item['body'] ?? '' }}</p>
            </li>
          @endforeach
        </ul>
      </section>
    @endif

    @if(! empty($pageContent['use_cases']))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ $pageContent['use_cases_title'] ?? __('ui.pricing.feature3_title') }}</h2>
          <p>{{ $pageContent['use_cases_intro'] ?? __('ui.footer.tagline') }}</p>
        </div>
        <ul class="sp-body sp-list">
          @foreach($pageContent['use_cases'] as $useCase)
            @php
              $useCaseTitle = is_array($useCase) ? (string) ($useCase['title'] ?? '') : (string) $useCase;
              $useCaseBody = is_array($useCase)
                ? (string) ($useCase['body'] ?? '')
                : (string) ($pageContent['intro'] ?? '');
            @endphp
            <li>
              <h3>{{ $useCaseTitle }}</h3>
              <p>{{ $useCaseBody }}</p>
            </li>
          @endforeach
        </ul>
      </section>
    @endif

    @if(! empty($faqItems))
      <section class="sp-row">
        <div class="sp-head">
          <h2>{{ $pageContent['faq_title'] ?? __('ui.pricing.faq_title') }}</h2>
        </div>
        <div class="sp-body sp-faq">
          @foreach($faqItems as $faq)
            <details @if($loop->first) open @endif>
              <summary><h3>{{ $faq['question'] ?? '' }}</h3></summary>
              <p>{{ $faq['answer'] ?? '' }}</p>
            </details>
          @endforeach
        </div>
      </section>
    @endif

    <section class="sp-cta">
      <div>
        <h2>{{ $pageContent['cta_title'] ?? '' }}</h2>
        <p>{{ $pageContent['cta_body'] ?? '' }}</p>
      </div>
      <div class="sp-actions">
        <a class="btn btn-primary" href="{{ $ctaPrimaryUrl }}">{{ $pageContent['cta_primary'] ?? __('ui.nav.get_started_free') }}</a>
        <a class="btn btn-ghost" href="{{ route('pricing') }}">{{ __('ui.nav.pricing') }}</a>
      </div>
    </section>

    <nav class="sp-explore" aria-label="{{ $isArabic ? 'صفحات ذات صلة' : 'Related pages' }}">
      @foreach($voiceSections as $voiceSection)
        @if($voiceSection['links'] !== [])
          <details class="sp-explore-group{{ count($voiceSection['links']) > 10 ? ' is-long' : '' }}" open>
            <summary><h2>{{ $voiceSection['title'] }}</h2></summary>
            <ul>
              @foreach($voiceSection['links'] as $link)
                <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
              @endforeach
              @if($voiceSection['all'])
                <li><a class="sp-all" href="{{ $voiceSection['all']['url'] }}">{{ $voiceSection['all']['label'] }}</a></li>
              @endif
            </ul>
          </details>
        @endif
      @endforeach
    </nav>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.motion')
  @include('components.voice-player-script')
  <script>
    // On a phone the related pages start folded, one group at a time.
    if (window.matchMedia('(max-width: 760px)').matches) {
      document.querySelectorAll('.sp-explore-group').forEach(function (group) { group.removeAttribute('open'); });
    }
  </script>
</body>
</html>
