@include('components.page-head', ['pageTitle' => __('developers.docs.seo_title'), 'page' => 'developers'])
@include('developers._docs-styles')
</head>
<body>
  @include('components.header')

  @php
    $t = fn (string $key, array $html = [], array $replace = []) => \App\Support\Api\ApiDocs::rich('developers.docs.'.$key, $html, $replace);
    $tx = fn (string $text) => \App\Support\Api\ApiDocs::richText($text);
    $plain = fn (string $key) => __('developers.docs.'.$key);
    $arabic = app()->getLocale() === 'ar';
    // Speech to text is described once it is public (open to everyone, on a plan on sale).
    $speechToTextPublic = \App\Support\StudioFacts::speechToText();
    // Options no plan includes are left out of the page (ApiFeatureGate::offered).
    $offered = $docs['offered'];
    $listed = fn (string $list) => \App\Support\Api\ApiDocs::listed($list, $offered, $arabic);
    $keysUrl = route('developers.keys');
    // The MCP server is shown once it runs here (routes/ai.php, laravel/mcp).
    $mcp = \App\Support\AgentDiscovery::mcpAvailable();
    $voiceName = $arabic && ($voice['name_ar'] ?? '') !== '' ? $voice['name_ar'] : $voice['name'];
    $contactEmail = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
    $supportEmail = '<a href="mailto:'.e($contactEmail).'" dir="ltr">'.e($contactEmail).'</a>';
    $check = '<span class="docs-yes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><span class="sr-only">'.e($plain('plans.included')).'</span></span>';
    $dash = '<span class="docs-no"><span class="sr-only">'.e($plain('plans.not_included')).'</span></span>';
    // A heading's own link, shown when the pointer is over it.
    $anchor = fn (string $id) => '<a class="docs-anchor" href="#'.$id.'" aria-hidden="true" tabindex="-1">#</a>';
    // An endpoint's path may break only before its slashes, so a long one wraps on a phone, never cut.
    $route = fn (string $path) => preg_replace('~(?<=.)/~', '<wbr>/', e($path));
    // The API reference by what each endpoint works on (ApiDocs::RESOURCES).
    $resources = \App\Support\Api\ApiDocs::resources($docs['endpoints']);
    $nav = [
      'start' => [
        ['intro', $plain('intro.title')],
        ['quickstart', $plain('quickstart.title')],
        ['authentication', $plain('auth.title')],
        ...($mcp ? [['mcp', $plain('mcp.title')]] : []),
        ['plans', $plain('plans.title')],
        ['credits', $plain('credits.title')],
      ],
      'guides' => [
        ['dialects', $plain('dialects.title')],
        ['styles', $plain('styles.title')],
        ...($offered['sound_environments'] ? [['sound-environments', $plain('environments.title')]] : []),
        ...($offered['dialogue'] ? [['dialogue', $plain('dialogue.title')]] : []),
        ['plan-features', $plain('plan_features.title')],
        ['long-texts', $plain('long.title')],
        ['formats', $plain('formats.title')],
        ['safe-retries', $plain('idempotency.title')],
        ['retries', $plain('retries.title')],
        ['best-practices', $plain('practices.title')],
      ],
      'reference' => array_map(fn (array $resource) => [
        'id' => $resource['id'],
        'title' => $plain('resources.'.$resource['key'].'.title'),
        'links' => array_map(fn (array $endpoint) => [$endpoint['id'], $plain('endpoints.'.$endpoint['key'].'.title'), $endpoint['method']], $resource['endpoints']),
      ], $resources),
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
    <aside class="docs-rail">
      <div class="docs-nav">
        @include('developers._docs-nav', ['nav' => $nav, 'searchId' => 'docsSearch'])
      </div>
    </aside>

    <main class="docs-main">
      {{-- Below the width of the side contents: a bar that stays at the top, naming the part being read. --}}
      <div class="docs-bar" data-docs-bar>
        <button type="button" class="docs-bar-button" aria-expanded="false" aria-controls="docsSheet" data-docs-open>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
          <span class="docs-bar-label">{{ $plain('on_this_page') }}</span>
          <span class="docs-bar-current" data-docs-current></span>
          <svg class="docs-bar-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
      </div>

      <header class="docs-hero">
        <div class="docs-hero-intro">
          <h1>{{ $plain('title') }}</h1>
          <p class="docs-lede">{{ __('developers.docs.subtitle', ['options' => $listed('subtitle')]) }}</p>
          <div class="docs-hero-actions">
            <a class="docs-btn primary" href="{{ $keysUrl }}">{{ $plain('cta_keys') }}</a>
            <a class="docs-btn" href="#quickstart">{{ $plain('cta_quickstart') }}</a>
            <div class="docs-base">
              <span>{{ $plain('base_url') }}</span>
              <code dir="ltr">{{ $baseUrl }}</code>
            </div>
          </div>
        </div>

        {{-- A real request beside its answer: the headers it came with, and the voice it returned. --}}
        @php
          // The recording the request returned (developer_docs_samples.php).
          $answerUrl = $hero['audio_url'] ?? null;
          $answerDialect = $hero['body']['dialect'] ?? null;
          $answerTitle = $answerDialect
            ? __('developers.docs.hero_voice_dialect', ['name' => $voiceName, 'dialect' => \App\Support\VoiceFlag::label($answerDialect, $arabic)])
            : __('developers.docs.hero_voice', ['name' => $voiceName]);
          $answerPlay = $plain('hero_play_result');
          $answerSeconds = (int) ($hero['seconds'] ?? 0);
        @endphp
        <div class="docs-console">
          <div class="docs-console-pane">
            @include('developers._docs-code', ['label' => $plain('request'), 'tag' => \App\Support\Api\ApiCodeSamples::LABELS['curl'], 'single' => $docs['hero']])
          </div>
          <div class="docs-console-pane is-response code-panel" data-code-panel>
            <div class="code-head">
              <span class="code-label">{{ $plain('response') }}<span class="code-tag mono is-ok" data-code>200 OK</span></span>
              <button type="button" class="code-copy" data-copy data-copy-label="{{ __('developers.docs.copy') }}" data-copied-label="{{ __('developers.docs.copied') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg><span>{{ __('developers.docs.copy') }}</span></button>
            </div>
<pre class="code-body" tabindex="0"><code>{!! $docs['hero_response'] !!}</code></pre>
            @if($answerUrl)
              @php
                // The recording's own shape when it could be read, else a plain one.
                $answerBars = !empty($hero['bars']) ? $hero['bars'] : $bars;
                $answerKb = (int) round(((int) ($hero['bytes'] ?? 0)) / 1024);
              @endphp
              {{-- The answer's body under its headers, as an answer reads: the recording itself, the
                   file the request saved (speech.mp3). --}}
              <div class="docs-answer" data-sample aria-label="{{ $plain('hero_result') }}" role="group">
                <div class="docs-answer-player">
                  <button type="button" class="docs-play" aria-pressed="false" data-sample-play aria-label="{{ $answerPlay }}" data-label-play="{{ $answerPlay }}" data-label-pause="{{ $plain('hero_pause') }}">
                    <svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
                    <svg class="i-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
                  </button>
                  {{-- Two copies of the waveform: the played one is revealed up to the playhead, frame by frame. --}}
                  <div class="docs-wave" data-sample-wave aria-hidden="true">
                    <div class="docs-wave-bars">@foreach($answerBars as $height)<i style="height: {{ $height }}%"></i>@endforeach</div>
                    <div class="docs-wave-bars is-played">@foreach($answerBars as $height)<i style="height: {{ $height }}%"></i>@endforeach</div>
                    <span class="docs-wave-head"></span>
                  </div>
                  <span class="docs-answer-time mono" data-sample-time data-total="{{ $answerSeconds }}">{{ $answerSeconds > 0 ? sprintf('%d:%02d', intdiv($answerSeconds, 60), $answerSeconds % 60) : '' }}</span>
                </div>
                <div class="docs-answer-about">
                  <span class="docs-answer-voice">
                    @if(!empty($voice['avatar_url']))<img src="{{ $voice['avatar_url'] }}" alt="" width="22" height="22" loading="lazy" onerror="this.remove()">@endif
                    <span>{{ $answerTitle }}</span>
                  </span>
                  <span class="docs-answer-file mono" dir="ltr"><span>speech.mp3</span>@if($answerKb > 0)<span>{{ $answerKb }} KB</span>@endif</span>
                </div>
                {{-- "initialized": the site's player script leaves it alone; this one has its own controls. --}}
                <audio class="initialized" preload="none" src="{{ $answerUrl }}" data-sample-audio></audio>
              </div>
            @endif
          </div>
        </div>
      </header>

      {{-- ───────── Getting started ───────── --}}
      <div class="docs-part" id="part-start">
        <h2>{{ $plain('groups.start') }}</h2>
        <p>{{ $plain('parts.start') }}</p>
      </div>

      <section class="docs-section" id="intro" data-section>
        <div class="docs-text">
          <h3>{{ $plain('intro.title') }}{!! $anchor('intro') !!}</h3>
          <p>{!! $t('intro.body') !!}</p>
          <ul>
            @php
              $introFeatures = $listed('intro_features');
            @endphp
            {{-- The formats and options some plan includes, then the rest. --}}
            <li>{!! $tx(__('developers.docs.intro.'.($docs['formats_offered'] === '' ? 'point_formats_mp3_only' : 'point_formats'), ['formats' => $docs['formats_offered']])) !!}</li>
            @foreach(__('developers.docs.intro.points') as $index => $point)
              <li>{!! $tx($point) !!}</li>
              @if($index === 0 && $introFeatures !== '')
                <li>{!! $tx(__('developers.docs.intro.points_features', ['options' => \Illuminate\Support\Str::ucfirst($introFeatures)])) !!}</li>
              @endif
            @endforeach
            @if($speechToTextPublic)
              <li>{!! $tx(__('developers.docs.intro.point_speech_to_text')) !!}</li>
            @endif
          </ul>
          <p class="docs-note">{{ $plain('intro.code_note') }}</p>
        </div>
      </section>

      <section class="docs-section" id="quickstart" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('quickstart.title') }}{!! $anchor('quickstart') !!}</h3>
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
            <h3>{{ $plain('auth.title') }}{!! $anchor('authentication') !!}</h3>
            <p>{!! $t('auth.body') !!}</p>
            <p class="docs-note warn">{!! $t('auth.security') !!}</p>
            <h4>{{ $plain('auth.scopes_title') }}</h4>
            <p>{!! $t('auth.scopes_body') !!}</p>
            <dl class="docs-params">
              @foreach(\App\Support\Api\ApiScopes::offered() as $scope)
                <div class="docs-param"><dt><code dir="ltr" class="docs-param-name">{{ $scope }}</code></dt><dd>{{ __('developers.scopes.'.$scope) }}</dd></div>
              @endforeach
            </dl>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('request'), 'single' => $docs['auth']])
          </div>
        </div>
      </section>

      @if($mcp)
      <section class="docs-section" id="mcp" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('mcp.title') }}{!! $anchor('mcp') !!}</h3>
            <p>{!! $t('mcp.body', [], ['url' => url('/mcp')]) !!}</p>
            <ol class="docs-steps">
              <li>{!! $t('mcp.step1') !!}</li>
              <li>{!! $t('mcp.step2') !!}</li>
              <li>{!! $t('mcp.step3') !!}</li>
            </ol>
            <p class="docs-note">{!! $t('mcp.note') !!}@if($speechToTextPublic) {!! $t('mcp.note_speech_to_text') !!}@endif</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('mcp.config_label'), 'single' => $docs['mcp_config']])
          </div>
        </div>

        {{-- Claude, step by step (named here by the owner's choice, CLAUDE.md). Its buttons keep their own English names. --}}
        @php
          $mcpUrl = url('/mcp');
          $ui = fn (string $text) => preg_replace('/\*\*([^*]+)\*\*/', '<strong class="docs-ui" dir="auto">$1</strong>', \App\Support\Api\ApiDocs::richText(str_replace(':url', $mcpUrl, $text)));
        @endphp
        <div class="docs-text docs-subrow" id="mcp-claude">
          <h4>{{ $plain('mcp.claude.title') }}{!! $anchor('mcp-claude') !!}</h4>
          <p>{!! $ui($plain('mcp.claude.body')) !!}</p>
          <ol class="docs-steps">
            @foreach(__('developers.docs.mcp.claude.steps') as $step)
              <li>{!! $ui($step) !!}</li>
            @endforeach
          </ol>
          <p class="docs-note">{!! $ui($plain('mcp.claude.team')) !!}</p>
        </div>
        <div class="docs-row docs-subrow" id="mcp-claude-code">
          <div class="docs-text">
            <h4>{{ $plain('mcp.claude.code_title') }}{!! $anchor('mcp-claude-code') !!}</h4>
            <p>{!! $ui($plain('mcp.claude.code_body')) !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('mcp.claude.code_label'), 'tag' => 'Claude Code', 'single' => $docs['mcp_claude_code']])
          </div>
        </div>
        <div class="docs-text" id="mcp-claude-help">
          <h4>{{ $plain('mcp.claude.trouble_title') }}{!! $anchor('mcp-claude-help') !!}</h4>
          <ul>
            @foreach(__('developers.docs.mcp.claude.trouble') as $point)
              <li>{!! $ui($point) !!}</li>
            @endforeach
          </ul>
        </div>

        <div class="docs-text docs-subrow">
          <h4>{{ $plain('mcp.tools_title') }}</h4>
          <p>{{ $plain('mcp.tools_body') }}</p>
          <dl class="docs-params">
            @foreach(\App\Mcp\Servers\ListenServer::toolNames() as $tool)
              <div class="docs-param"><dt><code dir="ltr" class="docs-param-name">{{ $tool }}</code></dt><dd>{{ __('developers.docs.mcp.tools.'.($tool === 'get_account' && $speechToTextPublic ? 'get_account_speech_to_text' : $tool)) }}</dd></div>
            @endforeach
          </dl>
        </div>
      </section>
      @endif

      <section class="docs-section" id="plans" data-section>
        <div class="docs-text is-wide">
          <h3>{{ $plain('plans.title') }}{!! $anchor('plans') !!}</h3>
          <p class="docs-measure">{!! $t('plans.body') !!}</p>
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
                      <td>{{ number_format($row['credits']) }}@if($row['is_pack'])<small class="docs-plans-sub">{{ $plain('plans.row_credits_pack') }}</small>@endif</td>
                    @endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_monthly_requests') }}</th>
                    @foreach($planRows as $row)<td>{{ $row['monthly_requests'] > 0 ? number_format($row['monthly_requests']) : $plain('plans.row_monthly_none') }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_daily') }}</th>
                    @foreach($planRows as $row)<td>{{ $row['daily_requests'] > 0 ? number_format($row['daily_requests']) : $plain('plans.row_daily_none') }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_rpm') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['rpm']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_reads') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['reads']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_concurrency') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['concurrency']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_queue') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['queue']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_max_chars') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['max_chars']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_max_chars_async') }}</th>
                    @foreach($planRows as $row)<td>{{ number_format($row['max_chars_async']) }}</td>@endforeach
                  </tr>
                  <tr>
                    <th scope="row">{{ $plain('plans.row_formats') }}</th>
                    @foreach($planRows as $row)<td class="docs-formats">@foreach($row['formats'] as $format)<code dir="ltr">{{ $format }}</code>@endforeach</td>@endforeach
                  </tr>
                  @if(in_array(true, $offered, true))
                    <tr class="docs-plans-group"><th scope="rowgroup" colspan="{{ count($planRows) + 1 }}">{{ $plain('plans.features_heading') }}</th></tr>
                  @endif
                  @foreach(array_keys(array_filter($offered)) as $feature)
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
          <h3>{{ $plain('credits.title') }}{!! $anchor('credits') !!}</h3>
          <ul>
            @foreach(__('developers.docs.credits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
        </div>
      </section>

      {{-- ───────── Guides ───────── --}}
      <div class="docs-part" id="part-guides">
        <h2>{{ $plain('groups.guides') }}</h2>
        <p>{{ $plain('parts.guides') }}</p>
      </div>

      <section class="docs-section" id="dialects" data-section>
        <div class="docs-text">
          <h3>{{ $plain('dialects.title') }}{!! $anchor('dialects') !!}</h3>
          <p>{!! $t('dialects.body') !!}</p>
        </div>
      </section>

      <section class="docs-section" id="styles" data-section>
        <div class="docs-text">
          <h3>{{ $plain('styles.title') }}{!! $anchor('styles') !!}</h3>
          <p>{!! $t('styles.body') !!}@if($offered['custom_direction']) {!! $t('styles.prompt') !!}@endif</p>
          <p>{!! $t('styles.tags') !!}</p>
        </div>
      </section>

      @if($offered['sound_environments'])
      <section class="docs-section" id="sound-environments" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('environments.title') }}{!! $anchor('sound-environments') !!}</h3>
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
      @endif

      @if($offered['dialogue'])
      <section class="docs-section" id="dialogue" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('dialogue.title') }}{!! $anchor('dialogue') !!}</h3>
            <p>{!! $t('dialogue.body') !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['samples' => $docs['dialogue']])
          </div>
        </div>
      </section>
      @endif

      <section class="docs-section" id="plan-features" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('plan_features.title') }}{!! $anchor('plan-features') !!}</h3>
            <p>{!! $t('plan_features.body', [], ['options' => $listed('plan_features')]) !!}</p>
            <p>{!! $t('plan_features.refusal') !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('response'), 'single' => $docs['feature_error']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="long-texts" data-section>
        <div class="docs-text">
          <h3>{{ $plain('long.title') }}{!! $anchor('long-texts') !!}</h3>
          <p>{!! $t('long.body', [], $docs['long_limits']) !!}@if($offered['dialogue']) {!! $t('long.dialogue', [], $docs['long_limits']) !!}@endif</p>
        </div>
      </section>

      <section class="docs-section" id="formats" data-section>
        <div class="docs-text">
          <h3>{{ $plain('formats.title') }}{!! $anchor('formats') !!}</h3>
          <p>{!! $docs['formats_offered'] === '' ? $t('formats.body_mp3_only') : $t('formats.body', [], ['formats' => $docs['formats_offered']]) !!}</p>
        </div>
      </section>

      <section class="docs-section" id="safe-retries" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('idempotency.title') }}{!! $anchor('safe-retries') !!}</h3>
            <p>{!! $t('idempotency.body') !!}</p>
            <ul>
              @foreach(__('developers.docs.idempotency.points') as $point)
                <li>{!! $tx($point) !!}</li>
              @endforeach
              @if($speechToTextPublic)<li>{!! $t('idempotency.point_speech_to_text') !!}</li>@endif
            </ul>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('request'), 'single' => $docs['idempotency']])
          </div>
        </div>
      </section>

      <section class="docs-section" id="retries" data-section>
        <div class="docs-text">
          <h3>{{ $plain('retries.title') }}{!! $anchor('retries') !!}</h3>
          <p>{!! $t('retries.body') !!}</p>
        </div>
      </section>

      <section class="docs-section" id="best-practices" data-section>
        <div class="docs-text">
          <h3>{{ $plain('practices.title') }}{!! $anchor('best-practices') !!}</h3>
          <ul>
            @foreach(__('developers.docs.practices.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
          </ul>
        </div>
      </section>

      {{-- ───────── API reference, by resource ───────── --}}
      <div class="docs-part" id="part-reference">
        <h2>{{ $plain('groups.reference') }}</h2>
        <p>{{ $plain('parts.reference') }}</p>
      </div>

      @foreach($resources as $resource)
        @php
          $resourceBody = $plain('resources.'.$resource['key'].'.body');
        @endphp
        <section class="docs-resource" id="{{ $resource['id'] }}" data-section>
          <h3>{{ $plain('resources.'.$resource['key'].'.title') }}{!! $anchor($resource['id']) !!}</h3>
          @if($resourceBody !== '')<p>{{ $resourceBody }}</p>@endif
          <ul class="docs-index" aria-label="{{ $plain('in_this_resource') }}">
            @foreach($resource['endpoints'] as $endpoint)
              <li>
                <a href="#{{ $endpoint['id'] }}">
                  <span class="docs-method mono m-{{ strtolower($endpoint['method']) }}" data-code>{{ $endpoint['method'] }}</span>
                  <code dir="ltr">{!! $route($endpoint['path']) !!}</code>
                  <span class="docs-index-name">{{ $plain('endpoints.'.$endpoint['key'].'.title') }}</span>
                </a>
              </li>
            @endforeach
          </ul>
        </section>

        @foreach($resource['endpoints'] as $endpoint)
          <section class="docs-section docs-endpoint" id="{{ $endpoint['id'] }}" data-section>
            <div class="docs-row">
              <div class="docs-text">
                <h4 class="docs-endpoint-title">{{ $plain('endpoints.'.$endpoint['key'].'.title') }}{!! $anchor($endpoint['id']) !!}</h4>
                <div class="docs-route" data-code>
                  <span class="docs-method mono m-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span>
                  <code>{!! $route($endpoint['path']) !!}</code>
                </div>
                <p>{!! $t('endpoints.'.$endpoint['key'].'.'.($endpoint['summary'] ?? 'summary'), [], $endpoint['summary_replace'] ?? []) !!}</p>
                <p class="docs-meta">{{ $plain('permission') }}: <code dir="ltr">{{ $endpoint['scope'] }}</code></p>

                @foreach(['header_params', 'path_params', 'query_params', 'body_params'] as $group)
                  @if(!empty($endpoint[$group]))
                    @include('developers._docs-params', ['title' => $plain($group), 'params' => $endpoint[$group]])
                  @endif
                @endforeach

                @if(!empty($endpoint['headers']))
                  <div class="docs-params-block">
                    <h5 class="docs-params-title">{{ $plain('response_headers') }}</h5>
                    <dl class="docs-params">
                      @foreach($endpoint['headers'] as $header)
                        <div class="docs-param"><dt><code dir="ltr" class="docs-param-name">{{ $header }}</code></dt><dd>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</dd></div>
                      @endforeach
                    </dl>
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
      @endforeach

      {{-- ───────── Errors, limits and support ───────── --}}
      <div class="docs-part" id="part-more">
        <h2>{{ $plain('groups.more') }}</h2>
        <p>{{ $plain('parts.more') }}</p>
      </div>

      <section class="docs-section" id="errors" data-section>
        <div class="docs-row">
          <div class="docs-text">
            <h3>{{ $plain('errors.title') }}{!! $anchor('errors') !!}</h3>
            <p>{!! $t('errors.body') !!}</p>
          </div>
          <div class="docs-code">
            @include('developers._docs-code', ['label' => $plain('response'), 'single' => $docs['error_example']])
          </div>
        </div>
        {{-- The codes, the whole width: they are looked up more than anything else here. --}}
        <div class="docs-table-wrap docs-errors-wrap">
              <table class="docs-table docs-errors">
                <thead><tr><th scope="col">{{ $plain('errors.col_status') }}</th><th scope="col">{{ $plain('errors.col_code') }}</th><th scope="col">{{ $plain('errors.col_meaning') }}</th></tr></thead>
                <tbody>
                  @foreach(__('developers.docs.errors.rows') as $error)
                    @php
                      [$status, $code, $meaning] = $error;
                    @endphp
                    @continue(isset($error[3]) && ! ($error[3] === 'speech_to_text' ? $speechToTextPublic : ($offered[$error[3]] ?? false)))
                    <tr><td><span class="docs-status mono {{ str_starts_with($status, '5') ? 's5' : '' }}" data-code>{{ $status }}</span></td><td><code dir="ltr">{{ $code }}</code></td><td>{!! $tx($meaning) !!}</td></tr>
                  @endforeach
                </tbody>
              </table>
        </div>
      </section>

      <section class="docs-section" id="rate-limits" data-section>
        <div class="docs-text">
          <h3>{{ $plain('limits.title') }}{!! $anchor('rate-limits') !!}</h3>
          <p>{!! $t('limits.body') !!}</p>
          <ul>
            @foreach(__('developers.docs.limits.points') as $point)
              <li>{!! $tx($point) !!}</li>
            @endforeach
            @if($speechToTextPublic)<li>{!! $t('limits.point_speech_to_text') !!}</li>@endif
          </ul>
          <dl class="docs-params">
            @foreach(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After', 'X-Request-Id'] as $header)
              <div class="docs-param"><dt><code dir="ltr" class="docs-param-name">{{ $header }}</code></dt><dd>{!! \App\Support\Api\ApiDocs::rich('developers.docs.headers.'.$header) !!}</dd></div>
            @endforeach
          </dl>
        </div>
      </section>

      <section class="docs-section" id="versioning" data-section>
        <div class="docs-text">
          <h3>{{ $plain('versioning.title') }}{!! $anchor('versioning') !!}</h3>
          <p>{!! $t('versioning.body') !!}</p>
          <p><a href="{{ route('v1.openapi') }}" target="_blank" rel="noopener">{!! $t('versioning.openapi') !!}</a></p>
          <h4>{{ $plain('versioning.changelog_title') }}</h4>
          <dl class="docs-params docs-changelog">
            @foreach(__('developers.docs.versioning.changelog') as $entry)
              @continue(($entry[2] ?? null) === 'speech_to_text' && ! $speechToTextPublic)
              <div class="docs-param"><dt><time datetime="{{ $entry[0] }}" dir="ltr">{{ $entry[0] }}</time></dt><dd>{!! $tx($entry[1]) !!}</dd></div>
            @endforeach
          </dl>
        </div>
      </section>

      <section class="docs-section" id="support" data-section>
        <div class="docs-text">
          <h3>{{ $plain('support.title') }}{!! $anchor('support') !!}</h3>
          <p>{!! $t('support.body', ['email' => '<span data-code>'.$supportEmail.'</span>']) !!}</p>
        </div>
      </section>
    </main>

    {{-- The contents on phones and tablets: a sheet over the page, with its search. --}}
    <div class="docs-sheet" id="docsSheet" data-docs-sheet hidden>
      <div class="docs-sheet-card" role="dialog" aria-modal="true" aria-label="{{ $plain('on_this_page') }}">
        <div class="docs-sheet-head">
          <span>{{ $plain('on_this_page') }}</span>
          <button type="button" class="docs-sheet-close" data-docs-close aria-label="{{ __('developers.docs.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <div class="docs-nav">
          @include('developers._docs-nav', ['nav' => $nav, 'searchId' => 'docsSearchMobile'])
        </div>
      </div>
    </div>
  </div>

  @include('components.footer')
  @include('components.scripts')
  @include('developers._docs-script')
</body>
</html>
