@include('components.page-head', ['pageTitle' => __('developers.docs.seo_title'), 'page' => 'developers'])
@include('developers._docs-styles')
</head>
<body>
  @include('components.header')

  @php
    $t = fn (string $key, array $html = []) => \App\Support\Api\ApiDocs::rich('developers.docs.'.$key, $html);
    $tx = fn (string $text) => \App\Support\Api\ApiDocs::richText($text);
    $plain = fn (string $key) => __('developers.docs.'.$key);
    $langs = $docs['languages'];
    $langName = fn (string $lang) => __('developers.docs.langs.'.$lang);
    $keysUrl = route('developers.keys');
    $supportEmail = '<a href="mailto:support@listenai.studio" dir="ltr">support@listenai.studio</a>';
    $nav = [
      'start' => [
        ['intro', $plain('intro.title')],
        ['quickstart', $plain('quickstart.title')],
        ['authentication', $plain('auth.title')],
        ['plans', $plain('plans.title')],
        ['credits', $plain('credits.title')],
      ],
      'guides' => [
        ['dialects', $plain('dialects.title')],
        ['styles', $plain('styles.title')],
        ['long-texts', $plain('long.title')],
        ['formats', $plain('formats.title')],
        ['retries', $plain('retries.title')],
        ['best-practices', $plain('practices.title')],
      ],
      'reference' => array_map(fn (array $endpoint) => [$endpoint['id'], $plain('endpoints.'.$endpoint['key'].'.title'), $endpoint['method']], $docs['endpoints']),
      'more' => [
        ['errors', $plain('errors.title')],
        ['rate-limits', $plain('limits.title')],
        ['versioning', $plain('versioning.title')],
        ['support', $plain('support.title')],
      ],
    ];
  @endphp

  <div class="docs-wrap" data-docs>
    <aside class="docs-nav" aria-label="{{ $plain('on_this_page') }}">
      @include('developers._docs-nav', ['nav' => $nav, 'searchId' => 'docsSearch'])
    </aside>

    <main class="docs-main">
      <details class="docs-nav-mobile">
        <summary>{{ $plain('on_this_page') }}</summary>
        <div class="docs-nav">
          @include('developers._docs-nav', ['nav' => $nav, 'searchId' => 'docsSearchMobile'])
        </div>
      </details>

      <header class="docs-hero">
        <span class="docs-kicker">{{ $plain('kicker') }}</span>
        <h1>{{ $plain('title') }}</h1>
        <p>{{ $plain('subtitle') }}</p>
        <div class="docs-hero-bar">
          <a class="btn btn-primary" href="{{ $keysUrl }}">{{ $plain('cta_keys') }}</a>
          <a class="btn" href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{{ $plain('cta_openapi') }}</a>
          <span class="docs-base">{{ $plain('base_url') }} <code dir="ltr">{{ $baseUrl }}</code></span>
          <label class="docs-lang-pick">{{ $plain('language') }}
            <select data-lang-select>
              @foreach($langs as $lang)
                <option value="{{ $lang }}">{{ $langName($lang) }}</option>
              @endforeach
            </select>
          </label>
        </div>
      </header>

      {{-- Getting started --}}
      <section class="docs-section" id="intro" data-section>
        <div class="docs-text">
          <h2>{{ $plain('intro.title') }}<a class="docs-anchor" href="#intro" aria-hidden="true">#</a></h2>
          <p>{!! $t('intro.body') !!}</p>
          <ul>
            @foreach(__('developers.docs.intro.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
          <p class="docs-callout">{{ $plain('intro.code_note') }}</p>
        </div>
      </section>

      <section class="docs-section" id="quickstart" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('quickstart.title') }}<a class="docs-anchor" href="#quickstart" aria-hidden="true">#</a></h2>
            <ol class="docs-steps">
              <li><a href="{{ $keysUrl }}">{!! $t('quickstart.step1') !!}</a></li>
              <li>{!! $t('quickstart.step2') !!}</li>
              <li>{!! $t('quickstart.step3') !!}</li>
            </ol>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $langName('curl'), 'single' => $docs['env']])
            @include('developers._docs-code', ['samples' => $docs['quickstart']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="authentication" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('auth.title') }}<a class="docs-anchor" href="#authentication" aria-hidden="true">#</a></h2>
            <p>{!! $t('auth.body') !!}</p>
            <p class="docs-callout warn">{!! $t('auth.security') !!}</p>
            <h4>{{ $plain('auth.scopes_title') }}</h4>
            <p>{!! $t('auth.scopes_body') !!}</p>
            <div class="docs-table-wrap">
              <table class="docs-table">
                <thead><tr><th>{{ $plain('auth.col_permission') }}</th><th>{{ $plain('auth.col_allows') }}</th></tr></thead>
                <tbody>
                  @foreach(\App\Support\Api\ApiScopes::all() as $scope)
                    <tr><td><code dir="ltr">{{ $scope }}</code></td><td>{{ __('developers.scopes.'.$scope) }}</td></tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('request'), 'single' => $docs['auth']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="plans" data-section>
        <div class="docs-text" style="max-width:none">
          <h2>{{ $plain('plans.title') }}<a class="docs-anchor" href="#plans" aria-hidden="true">#</a></h2>
          <p>{!! $t('plans.body') !!}</p>
          @if($planRows === [])
            <p class="docs-callout">{{ $plain('plans.none') }}</p>
          @else
            <div class="docs-table-wrap">
              <table class="docs-table">
                <thead><tr>
                  <th>{{ $plain('plans.col_plan') }}</th><th>{{ $plain('plans.col_credits') }}</th><th>{{ $plain('plans.col_rpm') }}</th>
                  <th>{{ $plain('plans.col_concurrency') }}</th><th>{{ $plain('plans.col_max_chars') }}</th><th>{{ $plain('plans.col_models') }}</th>
                </tr></thead>
                <tbody>
                  @foreach($planRows as $row)
                    <tr>
                      <td>{{ $row['name'] }}</td>
                      <td>{{ number_format($row['credits']) }}</td>
                      <td>{{ number_format($row['rpm']) }}</td>
                      <td>{{ number_format($row['concurrency']) }}</td>
                      <td>{{ number_format($row['max_chars']) }}</td>
                      <td>@foreach($row['models'] as $model)<code dir="ltr">{{ $model }}</code> @endforeach</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </section>

      <section class="docs-section" id="credits" data-section>
        <div class="docs-text">
          <h2>{{ $plain('credits.title') }}<a class="docs-anchor" href="#credits" aria-hidden="true">#</a></h2>
          <ul>
            @foreach(__('developers.docs.credits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
        </div>
      </section>

      {{-- Guides --}}
      @foreach([['dialects', 'dialects'], ['styles', 'styles'], ['long-texts', 'long'], ['formats', 'formats'], ['retries', 'retries']] as [$id, $key])
        <section class="docs-section" id="{{ $id }}" data-section>
          <div class="docs-text">
            <h2>{{ $plain($key.'.title') }}<a class="docs-anchor" href="#{{ $id }}" aria-hidden="true">#</a></h2>
            <p>{!! $t($key.'.body') !!}</p>
            @if($key === 'styles')
              <p>{!! $t('styles.tags') !!}</p>
            @endif
          </div>
        </section>
      @endforeach

      <section class="docs-section" id="best-practices" data-section>
        <div class="docs-text">
          <h2>{{ $plain('practices.title') }}<a class="docs-anchor" href="#best-practices" aria-hidden="true">#</a></h2>
          <ul>
            @foreach(__('developers.docs.practices.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
        </div>
      </section>

      {{-- API reference --}}
      @foreach($docs['endpoints'] as $endpoint)
        <section class="docs-section" id="{{ $endpoint['id'] }}" data-section>
          <div class="docs-row">
            <div class="docs-text">
              <h3>{{ $plain('endpoints.'.$endpoint['key'].'.title') }}<a class="docs-anchor" href="#{{ $endpoint['id'] }}" aria-hidden="true">#</a></h3>
              <div class="docs-route" data-code>
                <span class="docs-method mono m-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span>
                <code>{{ $endpoint['path'] }}</code>
              </div>
              <p>{!! $t('endpoints.'.$endpoint['key'].'.summary') !!}</p>
              <p class="docs-meta">{{ $plain('permission') }}: <code dir="ltr">{{ $endpoint['scope'] }}</code></p>

              @foreach(['path_params', 'query_params', 'body_params'] as $group)
                @if(!empty($endpoint[$group]))
                  <h4>{{ $plain($group) }}</h4>
                  <div class="docs-table-wrap">
                    <table class="docs-table">
                      <thead><tr><th>{{ $plain('col_param') }}</th><th>{{ $plain('col_type') }}</th><th>{{ $plain('col_description') }}</th></tr></thead>
                      <tbody>
                        @foreach($endpoint[$group] as $param)
                          <tr>
                            <td>
                              <div class="docs-param-name">
                                <code dir="ltr">{{ $param['name'] }}</code>
                                <span>
                                  <span class="docs-pill {{ $param['required'] ? 'req' : '' }}">{{ $param['required'] ? $plain('required') : $plain('optional') }}</span>
                                  @if($param['premium'])<span class="docs-pill pro">{{ $plain('premium_only') }}</span>@endif
                                </span>
                              </div>
                            </td>
                            <td>{{ __('developers.docs.types.'.$param['type']) }}</td>
                            <td>{!! \App\Support\Api\ApiDocs::rich($param['description']) !!}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                @endif
              @endforeach

              @if(!empty($endpoint['headers']))
                <h4>{{ $plain('response') }}</h4>
                <div class="docs-table-wrap">
                  <table class="docs-table">
                    <thead><tr><th>{{ $plain('col_header') }}</th><th>{{ $plain('col_meaning') }}</th></tr></thead>
                    <tbody>
                      @foreach($endpoint['headers'] as $header)
                        <tr><td><code dir="ltr">{{ $header }}</code></td><td>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</td></tr>
                      @endforeach
                    </tbody>
                  </table>
                </div>
              @endif
            </div>
            <div class="docs-code">
              @include('developers._docs-code', ['samples' => $endpoint['samples']])
              @include('developers._docs-code', ['label' => $plain('response'), 'single' => $endpoint['response_html']])
            </div>
          </div>
        </section>
      @endforeach

      {{-- Reference --}}
      <section class="docs-section" id="errors" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('errors.title') }}<a class="docs-anchor" href="#errors" aria-hidden="true">#</a></h2>
            <p>{!! $t('errors.body') !!}</p>
            <div class="docs-table-wrap">
              <table class="docs-table">
                <thead><tr><th>{{ $plain('errors.col_status') }}</th><th>{{ $plain('errors.col_code') }}</th><th>{{ $plain('errors.col_meaning') }}</th></tr></thead>
                <tbody>
                  @foreach(__('developers.docs.errors.rows') as [$status, $code, $meaning])
                    <tr><td>{{ $status }}</td><td><code dir="ltr">{{ $code }}</code></td><td>{!! $tx($meaning) !!}</td></tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('response'), 'single' => $docs['error_example']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="rate-limits" data-section>
        <div class="docs-text">
          <h2>{{ $plain('limits.title') }}<a class="docs-anchor" href="#rate-limits" aria-hidden="true">#</a></h2>
          <p>{!! $t('limits.body') !!}</p>
          <ul>
            @foreach(__('developers.docs.limits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
          <div class="docs-table-wrap">
            <table class="docs-table">
              <thead><tr><th>{{ $plain('col_header') }}</th><th>{{ $plain('col_meaning') }}</th></tr></thead>
              <tbody>
                @foreach(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'X-Request-Id'] as $header)
                  <tr><td><code dir="ltr">{{ $header }}</code></td><td>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="docs-section" id="versioning" data-section>
        <div class="docs-text">
          <h2>{{ $plain('versioning.title') }}<a class="docs-anchor" href="#versioning" aria-hidden="true">#</a></h2>
          <p>{!! $t('versioning.body') !!}</p>
          <p><a href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{!! $t('versioning.openapi') !!}</a></p>
          <h4>{{ $plain('versioning.changelog_title') }}</h4>
          <ul>
            @foreach(__('developers.docs.versioning.changelog') as [$date, $change])
              <li><time datetime="{{ $date }}" dir="ltr">{{ $date }}</time> · {{ $change }}</li>
            @endforeach
          </ul>
        </div>
      </section>

      <section class="docs-section" id="support" data-section>
        <div class="docs-text">
          <h2>{{ $plain('support.title') }}<a class="docs-anchor" href="#support" aria-hidden="true">#</a></h2>
          <p>{!! $t('support.body', ['email' => '<span data-code>'.$supportEmail.'</span>']) !!}</p>
        </div>
      </section>
    </main>
  </div>

  @include('components.footer')
  @include('components.scripts')
  @include('developers._docs-script')
</body>
</html>
