@include('components.page-head', ['pageTitle' => __('developers.docs.seo_title'), 'page' => 'developers'])
@include('developers._docs-styles')
</head>
<body>
  @include('components.header')

  @php
    $t = fn (string $key, array $html = []) => \App\Support\Api\ApiDocs::rich('developers.docs.'.$key, $html);
    $tx = fn (string $text) => \App\Support\Api\ApiDocs::richText($text);
    $plain = fn (string $key) => __('developers.docs.'.$key);
    $arabic = app()->getLocale() === 'ar';
    $keysUrl = route('developers.keys');
    $voiceName = $arabic && ($voice['name_ar'] ?? '') !== '' ? $voice['name_ar'] : $voice['name'];
    $supportEmail = '<a href="mailto:support@listenai.studio" dir="ltr">support@listenai.studio</a>';
    $check = '<span class="docs-yes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><span class="sr-only">'.e($plain('plans.included')).'</span></span>';
    $dash = '<span class="docs-no"><span class="sr-only">'.e($plain('plans.not_included')).'</span></span>';
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
        ['sound-environments', $plain('environments.title')],
        ['dialogue', $plain('dialogue.title')],
        ['plan-features', $plain('plan_features.title')],
        ['long-texts', $plain('long.title')],
        ['formats', $plain('formats.title')],
        ['safe-retries', $plain('idempotency.title')],
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
    // A fixed waveform for the sample player: the same shape on every visit.
    $bars = [];
    for ($i = 0; $i < 72; $i++) {
      $bars[] = (int) round(16 + 84 * abs(sin($i * 0.47) * cos($i * 0.13 + 0.6)) * (0.55 + 0.45 * ((crc32('listen'.$i) % 100) / 100)));
    }
  @endphp

  <div class="docs-wrap" data-docs>
    <aside class="docs-nav">
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
        <div>
          <h1>{{ $plain('title') }}</h1>
          <p class="docs-lede">{{ $plain('subtitle') }}</p>
          <div class="docs-hero-actions">
            <a class="docs-btn primary" href="{{ $keysUrl }}">{{ $plain('cta_keys') }}</a>
            <a class="docs-btn" href="#quickstart">{{ $plain('cta_quickstart') }}</a>
          </div>
          <div class="docs-base">
            <span>{{ $plain('base_url') }}</span>
            <code dir="ltr">{{ $baseUrl }}</code>
          </div>
        </div>

        {{-- A real request, and the voice it asks for. --}}
        <div class="docs-demo">
          @include('developers._docs-code', ['label' => \App\Support\Api\ApiCodeSamples::LABELS['curl'], 'codeLabel' => true, 'single' => $docs['hero']])
          @php
            // The recording the request above made; else a sample of the voice.
            $answerUrl = $hero['audio_url'] ?? $voice['preview_url'] ?? null;
            $answerTitle = !empty($hero['dialect'])
              ? __('developers.docs.hero_voice_dialect', ['name' => $voiceName, 'dialect' => \App\Support\VoiceFlag::label($hero['dialect'], $arabic)])
              : __('developers.docs.hero_voice', ['name' => $voiceName]);
            $answerNote = $hero !== null ? $plain('hero_result') : __('developers.docs.hero_sample', ['name' => $voiceName]);
            $answerPlay = $hero !== null ? $plain('hero_play_result') : __('developers.docs.hero_play', ['name' => $voiceName]);
          @endphp
          @if($answerUrl)
            <div class="docs-answer" data-sample>
              <button type="button" class="docs-play" aria-pressed="false" data-sample-play aria-label="{{ $answerPlay }}" data-label-play="{{ $answerPlay }}" data-label-pause="{{ $plain('hero_pause') }}">
                <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
                <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
              </button>
              <div class="docs-wave" data-sample-wave aria-hidden="true">
                @foreach($bars as $height)<i style="height: {{ $height }}%"></i>@endforeach
              </div>
              <div class="docs-answer-meta">
                <span class="docs-answer-voice">
                  @if(!empty($voice['avatar_url']))<img src="{{ $voice['avatar_url'] }}" alt="" width="26" height="26" loading="lazy" onerror="this.remove()">@endif
                  <span>{{ $answerTitle }}<small>{{ $answerNote }}</small></span>
                </span>
                <span class="docs-answer-status mono" data-code>200 OK  audio/mpeg</span>
              </div>
              {{-- "initialized": the site's player script leaves it alone; this one has its own controls. --}}
              <audio class="initialized" preload="none" src="{{ $answerUrl }}" data-sample-audio></audio>
            </div>
          @endif
        </div>
      </header>

      {{-- Getting started --}}
      <section class="docs-section" id="intro" data-section>
        <div class="docs-text">
          <h2>{{ $plain('intro.title') }}<a class="docs-anchor" href="#intro" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('intro.body') !!}</p>
          <ul>
            @foreach(__('developers.docs.intro.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
          <p class="docs-note">{{ $plain('intro.code_note') }}</p>
        </div>
      </section>

      <section class="docs-section" id="quickstart" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('quickstart.title') }}<a class="docs-anchor" href="#quickstart" aria-hidden="true" tabindex="-1">#</a></h2>
            <ol class="docs-steps">
              <li><a href="{{ $keysUrl }}">{!! $t('quickstart.step1') !!}</a></li>
              <li>{!! $t('quickstart.step2') !!}</li>
              <li>{!! $t('quickstart.step3') !!}</li>
            </ol>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => 'Shell', 'codeLabel' => true, 'single' => $docs['env']])
            @include('developers._docs-code', ['samples' => $docs['quickstart']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="authentication" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('auth.title') }}<a class="docs-anchor" href="#authentication" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('auth.body') !!}</p>
            <p class="docs-note warn">{!! $t('auth.security') !!}</p>
            <h4>{{ $plain('auth.scopes_title') }}</h4>
            <p>{!! $t('auth.scopes_body') !!}</p>
            <dl class="docs-params">
              @foreach(\App\Support\Api\ApiScopes::all() as $scope)
                <div class="docs-param"><dt><code dir="ltr">{{ $scope }}</code></dt><dd>{{ __('developers.scopes.'.$scope) }}</dd></div>
              @endforeach
            </dl>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('request'), 'single' => $docs['auth']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="plans" data-section>
        <div class="docs-text" style="max-width: none">
          <h2>{{ $plain('plans.title') }}<a class="docs-anchor" href="#plans" aria-hidden="true" tabindex="-1">#</a></h2>
          <p style="max-width: 720px">{!! $t('plans.body') !!}</p>
          @if($planRows === [])
            <p class="docs-note">{{ $plain('plans.none') }}</p>
          @else
            <div class="docs-table-wrap">
              <table class="docs-table docs-plans">
                <thead>
                  <tr>
                    <th scope="col"><span class="sr-only">{{ $plain('plans.title') }}</span></th>
                    @foreach($planRows as $row)<th scope="col">{{ $row['name'] }}</th>@endforeach
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_credits') }}</th>
                    @foreach($planRows as $row)
                      <td>{{ number_format($row['credits']) }}@if($row['is_pack'])<span class="sr-only">{{ $plain('plans.row_credits_pack') }}</span>@endif</td>
                    @endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_rpm') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['rpm']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_concurrency') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['concurrency']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_max_chars') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['max_chars']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_daily') }}</th>
                    @foreach($planRows as $row)<td>{{ $row['daily_requests'] > 0 ? number_format($row['daily_requests']) : $plain('plans.row_daily_none') }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_formats') }}</th>
                    @foreach($planRows as $row)<td class="docs-formats">@foreach($row['formats'] as $format)<code dir="ltr">{{ $format }}</code>@endforeach</td>@endforeach
                  </tr>
                  <tr class="docs-plans-group"><th scope="rowgroup" colspan="{{ count($planRows) + 1 }}">{{ $plain('plans.features_heading') }}</th></tr>
                  @foreach(['custom_direction', 'sound_environments', 'dialogue', 'cloned_voices'] as $feature)
                    <tr>
                      <th scope="row">{{ $plain('plans.features.'.$feature) }}</th>
                      @foreach($planRows as $row)<td>{!! $row['features'][$feature] ? $check : $dash !!}</td>@endforeach
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
          <h2>{{ $plain('credits.title') }}<a class="docs-anchor" href="#credits" aria-hidden="true" tabindex="-1">#</a></h2>
          <ul>
            @foreach(__('developers.docs.credits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
        </div>
      </section>

      {{-- Guides --}}
      <section class="docs-section" id="dialects" data-section>
        <div class="docs-text">
          <h2>{{ $plain('dialects.title') }}<a class="docs-anchor" href="#dialects" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('dialects.body') !!}</p>
        </div>
      </section>

      <section class="docs-section" id="styles" data-section>
        <div class="docs-text">
          <h2>{{ $plain('styles.title') }}<a class="docs-anchor" href="#styles" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('styles.body') !!}</p>
          <p>{!! $t('styles.tags') !!}</p>
        </div>
      </section>

      <section class="docs-section" id="sound-environments" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('environments.title') }}<a class="docs-anchor" href="#sound-environments" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('environments.body') !!}</p>
            <div class="docs-envs">
              @foreach($docs['environments'] as $group)
                <div class="docs-env-group">{{ $group['name'] }}</div>
                @foreach($group['items'] as $item)
                  <div class="docs-env">
                    <div><b>{{ $item['name'] }}</b><span>{{ $item['description'] }}</span></div>
                    <code dir="ltr">{{ $item['value'] }}</code>
                  </div>
                @endforeach
              @endforeach
            </div>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['samples' => $docs['environment_sample']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="dialogue" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('dialogue.title') }}<a class="docs-anchor" href="#dialogue" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('dialogue.body') !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['samples' => $docs['dialogue']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="plan-features" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('plan_features.title') }}<a class="docs-anchor" href="#plan-features" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('plan_features.body') !!}</p>
            <p>{!! $t('plan_features.refusal') !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('response'), 'single' => $docs['feature_error']])
          </div>
        </div>
      </section>

      @foreach([['long-texts', 'long'], ['formats', 'formats']] as [$id, $key])
        <section class="docs-section" id="{{ $id }}" data-section>
          <div class="docs-text">
            <h2>{{ $plain($key.'.title') }}<a class="docs-anchor" href="#{{ $id }}" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t($key.'.body') !!}</p>
          </div>
        </section>
      @endforeach

      <section class="docs-section" id="safe-retries" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('idempotency.title') }}<a class="docs-anchor" href="#safe-retries" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('idempotency.body') !!}</p>
            <ul>
              @foreach(__('developers.docs.idempotency.points') as $point)
                <li>{!! $tx($point) !!}</li>
              @endforeach
            </ul>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('request'), 'single' => $docs['idempotency']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="retries" data-section>
        <div class="docs-text">
          <h2>{{ $plain('retries.title') }}<a class="docs-anchor" href="#retries" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('retries.body') !!}</p>
        </div>
      </section>

      <section class="docs-section" id="best-practices" data-section>
        <div class="docs-text">
          <h2>{{ $plain('practices.title') }}<a class="docs-anchor" href="#best-practices" aria-hidden="true" tabindex="-1">#</a></h2>
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
              <h3>{{ $plain('endpoints.'.$endpoint['key'].'.title') }}<a class="docs-anchor" href="#{{ $endpoint['id'] }}" aria-hidden="true" tabindex="-1">#</a></h3>
              <div class="docs-route" data-code>
                <span class="docs-method mono m-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span>
                <code>{{ $endpoint['path'] }}</code>
              </div>
              <p>{!! $t('endpoints.'.$endpoint['key'].'.summary') !!}</p>
              <p class="docs-meta">{{ $plain('permission') }}: <code dir="ltr">{{ $endpoint['scope'] }}</code></p>

              @foreach(['header_params', 'path_params', 'query_params', 'body_params'] as $group)
                @if(!empty($endpoint[$group]))
                  @include('developers._docs-params', ['title' => $plain($group), 'params' => $endpoint[$group]])
                @endif
              @endforeach

              @if(!empty($endpoint['headers']))
                <h4>{{ $plain('response_headers') }}</h4>
                <dl class="docs-params">
                  @foreach($endpoint['headers'] as $header)
                    <div class="docs-param"><dt><code dir="ltr">{{ $header }}</code></dt><dd>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</dd></div>
                  @endforeach
                </dl>
              @endif
            </div>
            <div class="docs-code">
              @include('developers._docs-code', ['samples' => $endpoint['samples']])
              @include('developers._docs-code', ['label' => $plain('response'), 'single' => $endpoint['response_html']])
            </div>
          </div>
        </section>
      @endforeach

      {{-- More --}}
      <section class="docs-section" id="errors" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h2>{{ $plain('errors.title') }}<a class="docs-anchor" href="#errors" aria-hidden="true" tabindex="-1">#</a></h2>
            <p>{!! $t('errors.body') !!}</p>
            <div class="docs-table-wrap">
              <table class="docs-table">
                <thead><tr><th scope="col">{{ $plain('errors.col_status') }}</th><th scope="col">{{ $plain('errors.col_code') }}</th><th scope="col">{{ $plain('errors.col_meaning') }}</th></tr></thead>
                <tbody>
                  @foreach(__('developers.docs.errors.rows') as [$status, $code, $meaning])
                    <tr><td><span class="docs-status mono {{ str_starts_with($status, '5') ? 's5' : '' }}">{{ $status }}</span></td><td><code dir="ltr">{{ $code }}</code></td><td>{!! $tx($meaning) !!}</td></tr>
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
          <h2>{{ $plain('limits.title') }}<a class="docs-anchor" href="#rate-limits" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('limits.body') !!}</p>
          <ul>
            @foreach(__('developers.docs.limits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
          <dl class="docs-params">
            @foreach(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'X-Request-Id'] as $header)
              <div class="docs-param"><dt><code dir="ltr">{{ $header }}</code></dt><dd>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</dd></div>
            @endforeach
          </dl>
        </div>
      </section>

      <section class="docs-section" id="versioning" data-section>
        <div class="docs-text">
          <h2>{{ $plain('versioning.title') }}<a class="docs-anchor" href="#versioning" aria-hidden="true" tabindex="-1">#</a></h2>
          <p>{!! $t('versioning.body') !!}</p>
          <p><a href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{!! $t('versioning.openapi') !!}</a></p>
          <h4>{{ $plain('versioning.changelog_title') }}</h4>
          <dl class="docs-params">
            @foreach(__('developers.docs.versioning.changelog') as [$date, $change])
              <div class="docs-param"><dt><time datetime="{{ $date }}" dir="ltr">{{ $date }}</time></dt><dd>{{ $change }}</dd></div>
            @endforeach
          </dl>
        </div>
      </section>

      <section class="docs-section" id="support" data-section>
        <div class="docs-text">
          <h2>{{ $plain('support.title') }}<a class="docs-anchor" href="#support" aria-hidden="true" tabindex="-1">#</a></h2>
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
