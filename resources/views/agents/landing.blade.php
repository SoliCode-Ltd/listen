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
    $scenes = __('agents_landing.scenes');
    $order = __('agents_landing.scene_order');
    $first = $scenes[$order[0]];
    $steps = __('agents_landing.steps');
    $speaker = fn (string $slug) => asset('images/speakers/'.$slug.'.webp').'?v=1';
    $shot = fn (int $i) => asset('images/agents/landing/'.($ar ? 'ar' : 'en').'/step-'.($i + 1).'.webp').'?v=1';
    // Every dialect (Arabic page) or accent (English page) the agent speaks, beyond the calls shown.
    $regionNames = ['nile' => $ar ? 'مصر والسودان' : 'Egypt & Sudan', 'gulf' => $ar ? 'الخليج' : 'Gulf', 'levant' => $ar ? 'الشام' : 'Levant', 'iraq_yemen' => $ar ? 'العراق واليمن' : 'Iraq & Yemen', 'maghreb' => $ar ? 'المغرب العربي' : 'Maghreb', 'fusha' => $ar ? 'الفصحى' : 'Fusha'];
    $allSpeech = $ar
      ? collect(\App\Support\ArabicDialects::ALL)->groupBy('region', true)->sortBy(fn ($g, $r) => array_search($r, array_keys($regionNames)))->map(fn ($g, $r) => ['title' => $regionNames[$r] ?? $r, 'names' => $g->pluck('ar')->all()])->values()->all()
      : [['title' => null, 'names' => collect(\App\Support\EnglishAccents::ALL)->pluck('en')->all()]];
    $speechCount = collect($allSpeech)->sum(fn ($g) => count($g['names']));
    $moreCount = max(0, $speechCount - ($ar ? count($order) - 1 : count($order)));
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
    $safeIcons = [
      '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/><path d="M4 4l16 16"/>',
      '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M17 4l3 3-3 3"/>',
      '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10.5 11V9.8a1.5 1.5 0 0 1 3 0V11"/>',
      '<circle cx="12" cy="13" r="7.5"/><path d="M12 9.5V13l2.5 1.5M9.5 3h5"/>',
      '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
      '<path d="M6 3h9l3 3v15H6z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
    ];
  @endphp

  <main class="agl" id="agents-landing">
    {{-- A call you can watch, in one dialect after another; and a real one with Listen's own agent. --}}
    <section class="agl-hero">
      <div class="agl-hero-text">
        <h1>{{ __('agents_landing.hero_title') }}</h1>
        <p class="agl-lead">{{ __('agents_landing.hero_text') }}</p>
        <div class="agl-cta-row">
          <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ $hasAgents ? __('agents_landing.hero_cta_member') : __('agents_landing.hero_cta') }}</a>
          <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live') }}</button>
          <a class="ag-btn ag-btn-lg ag-btn-ghost" href="#agl-real" data-agl-watch>{{ __('agents_landing.real_play') }}</a>
        </div>
        <p class="agl-note" data-agl-live-note hidden>{{ __('agents_landing.talk_live_note') }}</p>
        @if($freeMinutes > 0)<p class="agl-note">{{ trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]) }}</p>@endif
      </div>

      <div class="agl-call" id="agl-call" data-agl-call>
        <div class="agl-call-card">
          {{-- Like the call button's card: the agent's photo next to its name, and one orb that moves with the voice. --}}
          <div class="agl-call-head">
            <img class="agl-call-face" src="{{ $speaker($first['avatar']) }}" alt="" width="46" height="46" data-agl-face>
            <div class="agl-call-who">
              <strong data-agl-business dir="auto">{{ $first['business'] }}</strong>
              <span data-agl-agent data-pattern="{{ __('agents_landing.call_assistant') }}">{{ str_replace(':name', $first['agent'], __('agents_landing.call_assistant')) }}</span>
            </div>
            <span class="agl-call-live"><i aria-hidden="true"></i>{{ __('agents_landing.call_live') }} <b data-agl-clock dir="ltr">00:00</b></span>
          </div>
          <div class="agl-call-stage" data-vp-track data-agl-stage>
            <span class="agl-call-orb">
              <span class="agl-call-rings" aria-hidden="true"><i></i><i></i></span>
              <x-orb name="listen" />
            </span>
          </div>
          <div class="agl-lines" data-agl-lines aria-hidden="true">
            @foreach($first['lines'] as [$role, $text])
              <p class="agl-line is-{{ $role }} is-on" dir="auto"><span>{{ $text }}</span></p>
            @endforeach
          </div>
          <p class="agl-chip is-on" data-agl-chip><i aria-hidden="true"></i><span>{{ $first['chip'] }}</span></p>
        </div>
        <div class="agl-dialects" role="group" aria-label="{{ __('agents_landing.dialects_label') }}">
          @foreach($order as $i => $key)
            <button type="button" class="agl-dialect {{ $i === 0 ? 'is-on' : '' }}" data-agl-scene="{{ $i }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">{{ $scenes[$key]['label'] }}</button>
          @endforeach
          @if($moreCount > 0)
            <button type="button" class="agl-dialect agl-dialect-more" aria-expanded="false" aria-controls="agl-all-speech" data-agl-more>{{ __('agents_landing.dialects_more', ['count' => $moreCount]) }}</button>
          @endif
        </div>
        {{-- All of them, by region: the agent speaks any of these. --}}
        <div class="agl-all" id="agl-all-speech" data-agl-all hidden>
          <p class="agl-all-title">{{ __('agents_landing.dialects_all') }} <b dir="ltr">{{ $speechCount }}</b></p>
          @foreach($allSpeech as $group)
            <div class="agl-all-group">
              @if($group['title'])<span class="agl-all-region">{{ $group['title'] }}</span>@endif
              <ul>@foreach($group['names'] as $name)<li>{{ $name }}</li>@endforeach</ul>
            </div>
          @endforeach
        </div>
      </div>
    </section>

    {{-- Real calls, recorded live, in the page's own language: hear one before reading about it. --}}
    <section class="agl-section agl-real-wrap" id="agl-real">
      <div class="agl-head agl-head-split" data-agl-rise>
        <h2>{{ __('agents_landing.real_title') }}</h2>
        <p>{{ __('agents_landing.real_text') }}</p>
      </div>
      {{-- The page's one orb is in the hero's call: here the agent's own face moves with the voice. --}}
      <x-agents.real-call size="wide" visual="face" data-agl-rise style="--rise-d: .08s" />
    </section>

    {{-- How it works: the real setup, one step after another as the page scrolls. --}}
    <section class="agl-section agl-how" data-agl-how>
      <div class="agl-head" data-agl-rise>
        <h2>{{ __('agents_landing.steps_title') }}</h2>
        <p>{{ __('agents_landing.steps_text') }}</p>
      </div>
      <div class="agl-how-grid">
        <ol class="agl-how-steps">
          @foreach($steps as $i => [$title, $text])
            <li class="agl-how-step {{ $i === 0 ? 'is-on' : '' }}" data-agl-how-step="{{ $i }}" data-label="{{ __('agents_landing.how_step_of', ['n' => $i + 1, 'total' => count($steps)]) }} · {{ $title }}">
              <span class="agl-step-n">{{ $i + 1 }}</span>
              <div class="agl-how-copy">
                <h3>{{ $title }}</h3>
                <p>{{ $text }}</p>
                <figure class="agl-how-inline"><img src="{{ $shot($i) }}" alt="{{ $title }}" width="1200" height="863" loading="lazy"></figure>
              </div>
            </li>
          @endforeach
        </ol>
        <div class="agl-how-frame" aria-hidden="true">
          <div class="agl-how-screen">
            @foreach($steps as $i => $step)
              <img src="{{ $shot($i) }}" alt="" width="1200" height="863" loading="lazy" class="{{ $i === 0 ? 'is-on' : '' }}" data-agl-how-shot="{{ $i }}">
            @endforeach
          </div>
          <p class="agl-how-foot">
            <span data-agl-how-label>{{ __('agents_landing.how_step_of', ['n' => 1, 'total' => count($steps)]) }} · {{ $steps[0][0] }}</span>
            <span class="agl-how-dots">@foreach($steps as $i => $step)<i class="{{ $i === 0 ? 'is-on' : '' }}"></i>@endforeach</span>
          </p>
        </div>
      </div>
    </section>

    {{-- What it does on every call, shown working. --}}
    <section class="agl-section agl-bento-wrap" data-agl-reveal>
      <div class="agl-head agl-head-split" data-agl-rise>
        <h2>{{ __('agents_landing.bento_title') }}</h2>
        <p>{{ __('agents_landing.bento_text') }}</p>
      </div>
      <x-agents.bento />
      <p class="agl-honest" data-agl-rise><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>{{ __('agents_landing.honest') }}</p>
    </section>

    {{-- One agent, every channel: where callers come from, the agent, a live call. --}}
    <section class="agl-section" data-agl-reveal>
      <div class="agl-head agl-head-split" data-agl-rise>
        <h2>{{ __('agents_landing.flow_title') }}</h2>
        <p>{{ __('agents_landing.flow_text') }}</p>
      </div>
      <div class="agl-flow" aria-hidden="true" data-agl-rise style="--rise-d: .1s">
        <ul class="agl-flow-sources">
          @foreach(__('agents_landing.flow_sources') as $i => $label)
            <li>
              <span class="agl-flow-icon">
                @switch($i)
                  @case(0)<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18M15 15h3"/></svg>@break
                  @case(1)<svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>@break
                  @case(2)<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/></svg>@break
                  @default<svg viewBox="0 0 24 24"><path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
                @endswitch
              </span>
              {{ $label }}
            </li>
          @endforeach
        </ul>
        <svg class="agl-flow-lines" viewBox="0 0 120 320" preserveAspectRatio="none">
          @foreach([26, 115, 205, 294] as $y)
            <path d="M0 {{ $y }} C 62 {{ $y }}, 58 160, 120 160" />
          @endforeach
        </svg>
        <div class="agl-flow-agent">
          {{-- Listen's mark, its bars moving like a voice. --}}
          <span class="agl-flow-logo" aria-hidden="true">
            <svg viewBox="0 0 128 128"><rect x="5" y="59" width="10" height="10" rx="5"/><rect x="23" y="42" width="10" height="44" rx="5"/><rect x="41" y="28" width="10" height="72" rx="5"/><rect x="59" y="14" width="10" height="100" rx="5"/><rect x="77" y="28" width="10" height="72" rx="5"/><rect x="95" y="42" width="10" height="44" rx="5"/><rect x="113" y="59" width="10" height="10" rx="5"/></svg>
          </span>
          <strong>{{ __('agents_landing.flow_agent') }}</strong>
          <span>{{ __('agents_landing.flow_agent_sub') }}</span>
        </div>
        <svg class="agl-flow-out" viewBox="0 0 64 320" preserveAspectRatio="none"><path d="M0 160 H 64" /></svg>
        <div class="agl-flow-call">
          <p class="agl-flow-call-head"><span><i></i>{{ __('agents_landing.call_live') }}</span><b dir="ltr">00:12</b></p>
          @foreach(__('agents_landing.flow_lines') as $i => [$role, $text])
            <p class="agl-flow-line is-{{ $role }}" style="--d: {{ $i }}" dir="auto">{{ $text }}</p>
          @endforeach
        </div>
      </div>
      <p class="agl-builders" data-agl-rise>{{ __('agents_landing.flow_builders') }}</p>
    </section>

    {{-- Every call written down. --}}
    <section class="agl-section agl-calls" data-agl-reveal>
      <div class="agl-head" data-agl-rise>
        <h2>{{ __('agents_landing.calls_title') }}</h2>
        <p>{{ __('agents_landing.calls_text') }}</p>
      </div>
      <div class="agl-inbox" aria-hidden="true" data-agl-rise style="--rise-d: .1s">
        @foreach(__('agents_landing.calls_demo') as [$summary, $channel, $length])
          <div class="agl-inbox-row">
            <span class="ag-pill">{{ $channel }}</span>
            <p dir="auto">{{ $summary }}</p>
            <span class="agl-inbox-len">{{ $length }}</span>
          </div>
        @endforeach
        <div class="agl-inbox-customer">
          <img src="{{ $speaker(__('agents_landing.calls_avatar')) }}" alt="" width="40" height="40" loading="lazy" onerror="this.remove()">
          <div><strong>{{ __('agents_landing.calls_customer') }}</strong><span dir="ltr">{{ __('agents_landing.calls_phone') }}</span></div>
          <span class="ag-btn ag-btn-sm ag-btn-primary">{{ __('agents_landing.calls_call') }}</span>
        </div>
      </div>
    </section>

    {{-- Safe by design: the rules every call keeps, and the limits the owner holds. --}}
    <section class="agl-section agl-safe">
      <div class="agl-head agl-head-split" data-agl-rise>
        <h2>{{ __('agents_landing.safe_title') }}</h2>
        <p>{{ __('agents_landing.safe_text') }}</p>
      </div>
      <ul class="agl-safe-list" data-agl-rise style="--rise-d: .08s">
        @foreach(__('agents_landing.safe') as $i => [$title, $text])
          <li>
            <span class="agl-safe-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $safeIcons[$i] ?? '' !!}</svg></span>
            <h3>{{ $title }}</h3>
            <p>{{ $text }}</p>
          </li>
        @endforeach
      </ul>
    </section>

    {{-- What it costs: the plans that include agents, and the rate in plain minutes. --}}
    <section class="agl-price" data-agl-rise>
      <div class="agl-price-copy">
        <h2>{{ __('agents_landing.plans_title') }}</h2>
        <p>{{ __('agents_landing.plans_text', ['rate' => number_format($rate)]) }}</p>
        <p class="agl-price-free">{{ __('agents_landing.plans_free', ['seconds' => number_format(\App\Support\Agents\AgentAccess::freeTestSeconds())]) }}</p>
      </div>
      @if($agentPlans !== [])
        <ul class="agl-price-plans">
          @foreach($agentPlans as $plan)
            <li><b>{{ $plan['name'] }}</b><span>{{ trans_choice('agents_landing.plans_line', $plan['agents'], ['count' => $plan['agents'], 'minutes' => number_format($plan['minutes'])]) }}</span></li>
          @endforeach
        </ul>
      @endif
      <a class="ag-btn ag-btn-lg" href="{{ route('pricing') }}">{{ __('agents_landing.price_cta') }}</a>
    </section>

    {{-- Questions. --}}
    <section class="agl-section agl-faq">
      <div class="agl-head" data-agl-rise><h2>{{ __('agents_landing.faq_title') }}</h2></div>
      <div class="agl-faq-list" data-agl-rise style="--rise-d: .08s">
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
      <span class="agl-flow-logo agl-final-logo" aria-hidden="true">
        <svg viewBox="0 0 128 128"><rect x="5" y="59" width="10" height="10" rx="5"/><rect x="23" y="42" width="10" height="44" rx="5"/><rect x="41" y="28" width="10" height="72" rx="5"/><rect x="59" y="14" width="10" height="100" rx="5"/><rect x="77" y="28" width="10" height="72" rx="5"/><rect x="95" y="42" width="10" height="44" rx="5"/><rect x="113" y="59" width="10" height="10" rx="5"/></svg>
      </span>
      <h2 data-agl-rise>{{ __('agents_landing.final_title') }}</h2>
      <div class="agl-cta-row agl-cta-center" data-agl-rise style="--rise-d: .1s">
        <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $startUrl }}">{{ __('agents_landing.final_cta') }}</a>
        <button type="button" class="ag-btn ag-btn-lg agl-live-btn" data-agl-live hidden>{!! $micIcon !!}{{ __('agents_landing.talk_live') }}</button>
      </div>
      @if($freeMinutes > 0)<p class="agl-note">{{ trans_choice('agents_landing.hero_note', $freeMinutes, ['count' => $freeMinutes]) }}</p>@endif
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.orb-script')
  @php
    $landing = [
      'scenes' => array_map(fn ($key) => [
        'agent' => $scenes[$key]['agent'],
        'business' => $scenes[$key]['business'],
        'face' => $speaker($scenes[$key]['avatar']),
        'lines' => array_map(fn ($l) => ['role' => $l[0], 'text' => $l[1], 'note' => $l[2] ?? null], $scenes[$key]['lines']),
        'chip' => $scenes[$key]['chip'],
      ], $order),
    ];
  @endphp
  <script>window.LISTEN_AGENTS_LANDING = {!! \Illuminate\Support\Js::from($landing) !!};</script>
  <script src="{{ asset('js/agents-landing.js') }}?v={{ filemtime(public_path('js/agents-landing.js')) }}" defer></script>
</body>
</html>
