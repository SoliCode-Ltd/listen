@include('components.page-head', ['pageTitle' => __('agents_landing.meta_title'), 'page' => 'agents_landing'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/agents-landing.css') }}?v={{ filemtime(public_path('css/agents-landing.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $ar = app()->getLocale() === 'ar';
    $hasAgents = auth()->check() && auth()->user()->agents()->exists();
    $startUrl = $hasAgents ? route('voice-agents.index') : (auth()->check() ? route('voice-agents.create') : route('register', ['next' => 'agents']));
    $steps = __('agents_landing.steps');
    $ui = __('agents_landing.steps_ui');
    $speaker = fn (string $slug) => asset('images/speakers/'.$slug.'.webp').'?v=1';
    // Every dialect (Arabic page) or accent (English page) the agent speaks, by region.
    $regionNames = ['nile' => $ar ? 'مصر والسودان' : 'Egypt & Sudan', 'gulf' => $ar ? 'الخليج' : 'Gulf', 'levant' => $ar ? 'الشام' : 'Levant', 'iraq_yemen' => $ar ? 'العراق واليمن' : 'Iraq & Yemen', 'maghreb' => $ar ? 'المغرب العربي' : 'Maghreb', 'fusha' => $ar ? 'الفصحى' : 'Fusha'];
    $allSpeech = $ar
      ? collect(\App\Support\ArabicDialects::ALL)->groupBy('region', true)->sortBy(fn ($g, $r) => array_search($r, array_keys($regionNames)))->map(fn ($g, $r) => ['title' => $regionNames[$r] ?? $r, 'names' => $g->pluck('ar')->all()])->values()->all()
      : [['title' => null, 'names' => collect(\App\Support\EnglishAccents::ALL)->pluck('en')->all()]];
    $speechCount = collect($allSpeech)->sum(fn ($g) => count($g['names']));
    $micIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>';
    // The plans that include agents, with what each gives (Plans → Voice agents), and the rate.
    $rate = \App\Support\Agents\AgentAccess::creditsPerMinute();
    // The free test minutes, as the admin set them (Agents → Settings).
    $freeMinutes = intdiv(\App\Support\Agents\AgentAccess::freeTestSeconds(), 60);
    $agentPlans = rescue(fn () => \App\Models\Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get()
      ->filter(fn ($p) => \App\Support\Agents\AgentAccess::planColumns() && \App\Support\Agents\AgentAccess::planIncludes($p))
      ->map(function ($p) use ($rate) {
          $limits = \App\Support\Agents\AgentAccess::limits($p);
          $minutes = intdiv(max(0, (int) $p->monthly_chars), $rate);
          if ($limits['minutes'] > 0) { $minutes = min($minutes, $limits['minutes']); }
          return ['name' => $p->getLocalizedName(), 'agents' => $limits['agents'], 'minutes' => $minutes, 'slug' => $p->slug];
      })->values()->all(), [], false);
    $factIcons = [
      '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
      '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
      '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
    ];
    $doesIcons = [
      '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
      '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
      '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/>',
      '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14a6.5 6.5 0 0 1 3 6"/>',
    ];
    $safeIcons = [
      '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/><path d="M4 4l16 16"/>',
      '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M17 4l3 3-3 3"/>',
      '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10.5 11V9.8a1.5 1.5 0 0 1 3 0V11"/>',
      '<circle cx="12" cy="13" r="7.5"/><path d="M12 9.5V13l2.5 1.5M9.5 3h5"/>',
      '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
      '<path d="M6 3h9l3 3v15H6z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
    ];
    $channelIcons = [
      '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18M15 15h3"/>',
      '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
      '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/>',
      '<path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
    ];
    // The voices shown in "Pick its voice": the library's own speakers of the calls on this page.
    $voices = $ar
      ? [['ar-fatima', 'فاطمة'], ['ar-hamed', 'حامد'], ['ar-sumaya', 'سمية'], ['ar-raed', 'رائد'], ['ar-tamim', 'تميم'], ['ar-turki', 'تركي']]
      : [['en-caleb', 'Caleb'], ['en-alice', 'Alice'], ['en-sienna', 'Sienna'], ['en-george', 'George'], ['en-finn', 'Finn'], ['en-oliver', 'Oliver']];
    $calls = (array) __('agents_landing.calls_demo');
    $logo = '<svg viewBox="0 0 128 128"><rect x="5" y="59" width="10" height="10" rx="5"/><rect x="23" y="42" width="10" height="44" rx="5"/><rect x="41" y="28" width="10" height="72" rx="5"/><rect x="59" y="14" width="10" height="100" rx="5"/><rect x="77" y="28" width="10" height="72" rx="5"/><rect x="95" y="42" width="10" height="44" rx="5"/><rect x="113" y="59" width="10" height="10" rx="5"/></svg>';
  @endphp

  <main class="agl" id="agents-landing">
    {{-- The hero: what it is in two lines, and a real call to hear right away. The page's one orb is in it. --}}
    <section class="agl-hero">
      <div class="agl-hero-text">
        <h1>{{ __('agents_landing.hero_title') }}</h1>
        <p class="agl-lead">{{ __('agents_landing.hero_text') }}</p>
        <div class="agl-cta-row">
          <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ $hasAgents ? __('agents_landing.hero_cta_member') : __('agents_landing.hero_cta') }}</a>
          <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live') }}</button>
        </div>
        <p class="agl-note" data-agl-live-note hidden>{{ __('agents_landing.talk_live_note') }}</p>
        @if($freeMinutes > 0)<p class="agl-note">{{ trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]) }}</p>@endif
        <ul class="agl-facts">
          @foreach(__('agents_landing.home_facts') as $i => $fact)
            <li><svg viewBox="0 0 24 24" aria-hidden="true">{!! $factIcons[$i] ?? '' !!}</svg><span>{{ $fact }}</span></li>
          @endforeach
        </ul>
      </div>
      <div class="agl-hero-call" id="agl-real">
        <x-agents.real-call size="hero" />
      </div>
    </section>

    {{-- Every dialect (or accent) it speaks, by region; the full list opens below. --}}
    <section class="agl-speaks" aria-labelledby="agl-speaks-title">
      <div class="agl-speaks-copy">
        <h2 id="agl-speaks-title">{{ __('agents_landing.speaks_title') }}</h2>
        <p>{{ __('agents_landing.speaks_text', ['count' => $speechCount]) }}</p>
      </div>
      <ul class="agl-speaks-regions">
        @foreach($allSpeech as $group)
          @if($group['title'])<li><b>{{ $group['title'] }}</b><span dir="ltr">{{ count($group['names']) }}</span></li>@endif
        @endforeach
        @unless($ar)
          @foreach(array_slice($allSpeech[0]['names'], 0, 6) as $name)<li><b>{{ $name }}</b></li>@endforeach
        @endunless
      </ul>
      <button type="button" class="agl-speaks-more" aria-expanded="false" aria-controls="agl-all-speech" data-agl-more>{{ __('agents_landing.speaks_all') }} <span dir="ltr">{{ $speechCount }}</span></button>
      <div class="agl-all" id="agl-all-speech" data-agl-all hidden>
        @foreach($allSpeech as $group)
          <div class="agl-all-group">
            @if($group['title'])<span class="agl-all-region">{{ $group['title'] }}</span>@endif
            <ul>@foreach($group['names'] as $name)<li>{{ $name }}</li>@endforeach</ul>
          </div>
        @endforeach
      </div>
    </section>

    {{-- How it works: four steps, each with a drawing of its own screen (never out of date). --}}
    <section class="agl-section agl-steps" aria-labelledby="agl-steps-title">
      <div class="agl-head">
        <h2 id="agl-steps-title">{{ __('agents_landing.steps_title') }}</h2>
        <p>{{ __('agents_landing.steps_text') }}</p>
      </div>
      <ol class="agl-track">
        @foreach($steps as $i => [$title, $text])
          <li class="agl-step">
            <div class="agl-ui agl-ui-{{ $i + 1 }}" aria-hidden="true">
              @switch($i)
                @case(0)
                  {{-- Your website, read: what it found. --}}
                  <div class="agl-ui-site">
                    <span class="agl-ui-dots"><i></i><i></i><i></i></span>
                    <span class="agl-ui-addr"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><i></i></span>
                  </div>
                  <p class="agl-ui-reading"><span class="agl-ui-spin"></span>{{ $ui['reading'] }}</p>
                  <ul class="agl-ui-facts">
                    @foreach($ui['facts'] as $fact)<li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>{{ $fact }}</li>@endforeach
                  </ul>
                  <p class="agl-ui-found">{{ $ui['found'] }}</p>
                  @break
                @case(1)
                  {{-- Real voices, one picked. --}}
                  <ul class="agl-ui-voices">
                    @foreach($voices as $v => [$slug, $name])
                      <li class="{{ $v === 0 ? 'is-on' : '' }}"><img src="{{ $speaker($slug) }}" alt="" width="52" height="52" loading="lazy"><span>{{ $name }}</span></li>
                    @endforeach
                  </ul>
                  @break
                @case(2)
                  {{-- A test call, and a wrong answer corrected once. --}}
                  <div class="agl-ui-chat">
                    <p class="is-caller">{{ $ui['talk_q'] }}</p>
                    <p class="is-agent">{{ $ui['talk_a'] }}</p>
                    <span class="agl-ui-fix"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>{{ $ui['talk_fix'] }}</span>
                  </div>
                  @break
                @default
                  {{-- Live: its link, its code, a button on the website. --}}
                  <ul class="agl-ui-live">
                    {{-- Its link, its code, a button on the website: the matching channel icons. --}}
                    @foreach($ui['live'] as $k => $label)
                      <li><span class="agl-ui-tile"><svg viewBox="0 0 24 24">{!! $channelIcons[[1, 2, 0][$k] ?? 0] !!}</svg></span>{{ $label }}</li>
                    @endforeach
                  </ul>
                  <p class="agl-ui-on"><i></i>{{ $ui['live_on'] }}</p>
              @endswitch
            </div>
            <span class="agl-step-n" aria-hidden="true">{{ $i + 1 }}</span>
            <h3>{{ $title }}</h3>
            <p>{{ $text }}</p>
          </li>
        @endforeach
      </ol>
    </section>

    {{-- What it does on every call: the month at a glance and four things it does. --}}
    <section class="agl-section agl-does" data-agl-reveal aria-labelledby="agl-does-title">
      <div class="agl-head">
        <h2 id="agl-does-title">{{ __('agents_landing.bento_title') }}</h2>
        <p>{{ __('agents_landing.bento_text') }}</p>
      </div>
      <div class="agl-does-grid">
        <article class="agl-month">
          <h3>{{ __('agents_landing.bento_stats_title') }}</h3>
          <div class="agl-month-nums">
            @foreach(__('agents_landing.bento_stats') as [$number, $label])
              <div><b data-agl-count="{{ $number }}">{{ $number }}</b><span>{{ $label }}</span></div>
            @endforeach
          </div>
          <div class="agl-month-bars" aria-hidden="true">
            @foreach([38, 52, 44, 61, 57, 70, 48, 66, 74, 59, 81, 69, 88, 76] as $b => $h)<i style="--h: {{ $h }}%; --d: {{ $b }}"></i>@endforeach
          </div>
          <p>{{ __('agents_landing.bento_stats_caption') }}</p>
        </article>
        <ul class="agl-does-list">
          @foreach(__('agents_landing.does') as $i => [$title, $text])
            <li>
              <span class="agl-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $doesIcons[$i] ?? '' !!}</svg></span>
              <div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
            </li>
          @endforeach
        </ul>
      </div>
      <p class="agl-honest"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>{{ __('agents_landing.honest') }}</p>
    </section>

    {{-- One agent, every channel: where callers come from, the agent, a live call. --}}
    <section class="agl-section" data-agl-reveal aria-labelledby="agl-flow-title">
      <div class="agl-head">
        <h2 id="agl-flow-title">{{ __('agents_landing.flow_title') }}</h2>
        <p>{{ __('agents_landing.flow_text') }}</p>
      </div>
      <div class="agl-flow" aria-hidden="true">
        <ul class="agl-flow-sources">
          @foreach(__('agents_landing.flow_sources') as $i => $label)
            <li><span class="agl-flow-icon"><svg viewBox="0 0 24 24">{!! $channelIcons[$i] ?? '' !!}</svg></span>{{ $label }}</li>
          @endforeach
        </ul>
        <svg class="agl-flow-lines" viewBox="0 0 120 320" preserveAspectRatio="none">
          @foreach([26, 115, 205, 294] as $y)
            <path d="M0 {{ $y }} C 62 {{ $y }}, 58 160, 120 160" />
          @endforeach
        </svg>
        <div class="agl-flow-agent">
          <span class="agl-flow-logo">{!! $logo !!}</span>
          <strong>{{ __('agents_landing.flow_agent') }}</strong>
          <span>{{ __('agents_landing.flow_agent_sub') }}</span>
        </div>
        <svg class="agl-flow-out" viewBox="0 0 64 320" preserveAspectRatio="none"><path d="M0 160 H 64" /></svg>
        <div class="agl-flow-call">
          <p class="agl-flow-call-head"><span><i></i>{{ __('agents_landing.call_live') }}</span><b dir="ltr">00:12</b></p>
          @foreach(__('agents_landing.flow_lines') as $i => [$role, $text])
            <p class="agl-flow-line is-{{ $role }}" style="--d: {{ $i }}">{{ $text }}</p>
          @endforeach
        </div>
      </div>
      <p class="agl-builders">{{ __('agents_landing.flow_builders') }}</p>
    </section>

    {{-- Every call written down: the owner's call list, one call open with its contact and what was said. --}}
    <section class="agl-section agl-calls" aria-labelledby="agl-calls-title">
      <div class="agl-head">
        <h2 id="agl-calls-title">{{ __('agents_landing.calls_title') }}</h2>
        <p>{{ __('agents_landing.calls_text') }}</p>
      </div>
      <div class="agl-inbox" aria-hidden="true">
        <div class="agl-inbox-list">
          <p class="agl-inbox-title">{{ __('agents_landing.calls_list') }}</p>
          @foreach($calls as $c => [$summary, $channel, $length])
            <div class="agl-inbox-row {{ $c === 0 ? 'is-on' : '' }}">
              <span class="agl-inbox-icon"><svg viewBox="0 0 24 24">{!! $channelIcons[$c] ?? '' !!}</svg></span>
              <div>
                <p>{{ $summary }}</p>
                <span>{{ $channel }} <i></i> {{ $length }}</span>
              </div>
              <time>{{ __('agents_landing.calls_when')[$c] ?? '' }}</time>
            </div>
          @endforeach
        </div>
        <div class="agl-inbox-detail">
          <p class="agl-inbox-title">{{ __('agents_landing.calls_summary') }}</p>
          <p class="agl-inbox-summary">{{ $calls[0][0] ?? '' }}</p>
          <div class="agl-inbox-contact">
            <img src="{{ $speaker(__('agents_landing.calls_avatar')) }}" alt="" width="44" height="44" loading="lazy">
            <div>
              <strong>{{ __('agents_landing.calls_customer') }}</strong>
              <span>{{ __('agents_landing.calls_left') }} <bdi dir="ltr">{{ __('agents_landing.calls_phone') }}</bdi></span>
            </div>
            <span class="ag-btn ag-btn-sm ag-btn-primary">{{ __('agents_landing.calls_call') }}</span>
          </div>
          <div class="agl-inbox-lines">
            @foreach(__('agents_landing.calls_lines') as [$role, $text])
              <p class="is-{{ $role }}">{{ $text }}</p>
            @endforeach
          </div>
        </div>
      </div>
    </section>

    {{-- Safe by design: the rules every call keeps, and the limits the owner holds. --}}
    <section class="agl-section agl-safe" aria-labelledby="agl-safe-title">
      <div class="agl-head">
        <h2 id="agl-safe-title">{{ __('agents_landing.safe_title') }}</h2>
        <p>{{ __('agents_landing.safe_text') }}</p>
      </div>
      <ul class="agl-safe-list">
        @foreach(__('agents_landing.safe') as $i => [$title, $text])
          <li>
            <span class="agl-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $safeIcons[$i] ?? '' !!}</svg></span>
            <div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
          </li>
        @endforeach
      </ul>
    </section>

    {{-- What it costs: the rate in plain words and the plans that include agents. --}}
    <section class="agl-section agl-price" aria-labelledby="agl-price-title">
      <div class="agl-price-copy">
        <h2 id="agl-price-title">{{ __('agents_landing.plans_title') }}</h2>
        <p>{{ __('agents_landing.plans_text', ['rate' => number_format($rate)]) }}</p>
        <p class="agl-price-free">{{ __('agents_landing.plans_free', ['seconds' => number_format(\App\Support\Agents\AgentAccess::freeTestSeconds())]) }}</p>
        <a class="ag-btn ag-btn-lg" href="{{ route('pricing') }}">{{ __('agents_landing.price_cta') }}</a>
      </div>
      @if($agentPlans !== [])
        <ul class="agl-price-plans">
          @foreach($agentPlans as $plan)
            <li><b>{{ $plan['name'] }}</b><span>{{ trans_choice('agents_landing.plans_line', $plan['agents'], ['count' => $plan['agents'], 'minutes' => number_format($plan['minutes'])]) }}</span></li>
          @endforeach
        </ul>
      @endif
    </section>

    {{-- Questions. --}}
    <section class="agl-section agl-faq" aria-labelledby="agl-faq-title">
      <div class="agl-head"><h2 id="agl-faq-title">{{ __('agents_landing.faq_title') }}</h2></div>
      <div class="agl-faq-list">
        @foreach(__('agents_landing.faq') as $i => [$q, $a])
          <details {{ $i === 0 ? 'open' : '' }}>
            <summary>{{ $q }}</summary>
            <p>{{ str_replace(':rate', number_format($rate), $a) }}</p>
          </details>
        @endforeach
      </div>
    </section>

    <section class="agl-final">
      {{-- Listen's mark, not a second orb: the page has one orb, in the call. --}}
      <span class="agl-flow-logo agl-final-logo" aria-hidden="true">{!! $logo !!}</span>
      <h2>{{ __('agents_landing.final_title') }}</h2>
      <div class="agl-cta-row agl-cta-center">
        <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ __('agents_landing.final_cta') }}</a>
        <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live') }}</button>
      </div>
      @if($freeMinutes > 0)<p class="agl-note">{{ trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]) }}</p>@endif
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.orb-script')
  <script src="{{ asset('js/agents-landing.js') }}?v={{ filemtime(public_path('js/agents-landing.js')) }}" defer></script>
</body>
</html>
