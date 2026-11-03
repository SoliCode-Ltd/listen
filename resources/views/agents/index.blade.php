@include('components.page-head', ['pageTitle' => __('agents.title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/usage.css') }}?v={{ filemtime(public_path('css/usage.css')) }}">
</head>
<body>
  @include('components.header')

  <main class="ag" id="agents">
    @if($agents->isEmpty())
      <section class="ag-card ag-empty">
        <x-orb name="listen" />
        <h1 class="sr-only">{{ __('agents.title') }}</h1>
        <h2>{{ __('agents.empty_title') }}</h2>
        <p>{{ __('agents.empty_text') }}</p>
        <ol class="ag-flow">
          @foreach(['business', 'voice', 'talk', 'live'] as $i => $step)
            <li><b>{{ $i + 1 }}</b>{{ __('agents.steps.'.$step) }}</li>
          @endforeach
        </ol>
        <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ route('voice-agents.create') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ __('agents.empty_cta') }}
        </a>
      </section>
      @if(in_array($balance['state'], ['plan', 'out', 'low'], true))
        <div style="margin-top:1rem">@include('agents.partials.balance', ['balance' => $balance])</div>
      @endif
    @else
      <div class="ag-head">
        <div>
          <h1>{{ __('agents.title') }}</h1>
          <p>{{ __('agents.subtitle') }}</p>
        </div>
        @if($agents->count() < $limits['agents'])
          <a class="ag-btn ag-btn-primary" href="{{ route('voice-agents.create') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ __('agents.new_agent') }}
          </a>
        @else
          <p class="ag-head-note">{{ trans_choice('agents.error_max_agents', $limits['agents'], ['count' => $limits['agents']]) }}</p>
        @endif
      </div>

      {{-- The account's call time, once, here (each agent's page shows only its own numbers). --}}
      @include('agents.partials.balance', ['balance' => $balance])

      <div class="ag-list-head">
        <h2>{{ __('agents.list_count') }} <small>{{ $agents->count() }}</small></h2>
        <span>{{ __('agents.list_month', ['date' => $since->copy()->locale(app()->getLocale())->translatedFormat('j F')]) }}</span>
      </div>
      <div class="ag-grid">
        @foreach($agents as $agent)
          @php
            $count = (int) ($calls[$agent->id]->n ?? 0);
            $mins = intdiv((int) ($calls[$agent->id]->s ?? 0), 60);
            $people = (int) ($customers[$agent->id] ?? 0);
            $last = isset($lastCalls[$agent->id]) ? \Illuminate\Support\Carbon::parse($lastCalls[$agent->id]) : null;
            $setupDone = $agent->status !== \App\Models\Agent::DRAFT;
            $mark = $agent->widgets->sortBy('id')->first()?->look('accent') ?? '#7659FA';
            $voice = $voices[$agent->id] ?? null;
            $speaks = ($agent->language === 'en' ? \App\Support\EnglishAccents::ALL : \App\Support\ArabicDialects::ALL)[$agent->dialect ?? ''][app()->getLocale() === 'ar' ? 'ar' : 'en'] ?? '';
          @endphp
          <a class="ag-card ag-agent" href="{{ $setupDone ? route('voice-agents.show', $agent) : route('voice-agents.edit', $agent) }}">
            <div class="ag-agent-top">
              {{-- The voice's face, or the agent's first letter in its call button's colour before a voice is picked. --}}
              <span class="ag-agent-mark {{ $agent->isLive() ? 'is-live' : '' }}" style="--m: {{ $mark }}" aria-hidden="true">
                @if($voice && $voice['avatar'])<img src="{{ $voice['avatar'] }}" alt="" width="52" height="52" loading="lazy">@else{{ mb_substr($agent->name, 0, 1) }}@endif
              </span>
              <div class="ag-agent-who">
                <div class="ag-agent-name">{{ $agent->name }}</div>
                <div class="ag-agent-biz">{{ $agent->business_name }}</div>
              </div>
              <span class="ag-pill is-{{ $agent->status }}"><i></i>{{ __('agents.status.'.$agent->status) }}</span>
            </div>
            @if($voice)
              <div class="ag-agent-voice">
                @if($voice['flag'])<img src="{{ $voice['flag'] }}" alt="" width="18" height="13" loading="lazy">@endif
                <span>{{ __('agents.voice_of', ['name' => $voice['name']]) }}</span>@if($speaks !== '')<small>{{ $speaks }}</small>@endif
              </div>
            @endif
            {{-- This agent's month: the same three numbers on every card, in the same place. --}}
            <dl class="ag-agent-nums">
              <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>{{ __('agents.card_stat_calls') }}</dt><dd>{{ number_format($count) }}</dd></div>
              <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ __('agents.card_stat_minutes') }}</dt><dd>{{ number_format($mins) }}</dd></div>
              <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="8" r="3.5"/><path d="M3.5 20a6.5 6.5 0 0 1 13 0M19 8v6M16 11h6"/></svg>{{ __('agents.card_stat_customers') }}</dt><dd>{{ number_format($people) }}</dd></div>
            </dl>
            <div class="ag-agent-meta">
              {{-- Callers who could not get through today take the place of the last call; its page says why. --}}
              @if($m = $missed[$agent->id] ?? null)
                <span class="ag-agent-miss" role="status" title="{{ trans_choice('agents.missed', $m['count'], ['count' => $m['count'], 'name' => $agent->name, 'reason' => __('agents.missed_reasons.'.$m['reason'])]) }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>{{ trans_choice('agents.card_missed', $m['count'], ['count' => $m['count']]) }}</span>
              @else
                <span>{{ $setupDone ? ($last ? __('agents.card_last', ['when' => $last->diffForHumans()]) : __('agents.card_none')) : __('agents.card_setup') }}</span>
              @endif
              <span class="ag-agent-open">{{ $setupDone ? __('agents.card_open') : __('agents.card_continue') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></span>
            </div>
          </a>
        @endforeach
        @if($agents->count() < $limits['agents'])
          <a class="ag-agent-new" href="{{ route('voice-agents.create') }}">
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
            <b>{{ __('agents.new_agent') }}</b>
            <small>{{ trans_choice('agents.new_agent_left', $limits['agents'] - $agents->count(), ['count' => $limits['agents'] - $agents->count()]) }}</small>
          </a>
        @endif
      </div>

      {{-- Where the credits went: calls by agent, and the studio's audio (the same credits). --}}
      <div class="ag-usage"><x-usage.breakdown :usage="\App\Support\CreditUsage::for(auth()->user())" :per-minute="\App\Support\Agents\AgentAccess::creditsPerMinute()" /></div>
    @endif
  </main>

  @include('components.scripts')
  @include('components.orb-script')
</body>
</html>
