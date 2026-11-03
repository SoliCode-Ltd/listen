@include('components.page-head', ['pageTitle' => __('agents.title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
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
    @else
      <div class="ag-head">
        <div>
          <h1>{{ __('agents.title') }}</h1>
          <p>{{ __('agents.subtitle') }}</p>
        </div>
        @if($agents->count() < (int) config('listen.agents.max_agents', 10))
          <a class="ag-btn ag-btn-primary" href="{{ route('voice-agents.create') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ __('agents.new_agent') }}
          </a>
        @endif
      </div>

      <div class="ag-grid">
        @foreach($agents as $agent)
          @php
            $count = (int) ($calls[$agent->id]->n ?? 0);
            $last = isset($calls[$agent->id]) ? \Illuminate\Support\Carbon::parse($calls[$agent->id]->last) : null;
            $setupDone = $agent->status !== \App\Models\Agent::DRAFT;
          @endphp
          @php
            $mark = $agent->widgets->sortBy('id')->first()?->look('accent') ?? '#7659FA';
            $voice = $voices[$agent->id] ?? null;
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
            @if($voice && $voice['dialect_label'] !== '')
              <div class="ag-agent-voice">
                @if($voice['flag'])<img src="{{ $voice['flag'] }}" alt="" width="18" height="13" loading="lazy">@endif
                <span>{{ __('agents.voice_of', ['name' => $voice['name']]) }}</span><small>{{ $voice['dialect_label'] }}</small>
              </div>
            @endif
            <div class="ag-agent-meta">
              <span>{{ trans_choice('agents.card_calls', $count, ['count' => $count]) }}</span>
              <span class="ag-agent-open">{{ $setupDone ? ($last ? __('agents.card_last', ['when' => $last->diffForHumans()]) : __('agents.card_open')) : __('agents.card_continue') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></span>
            </div>
          </a>
        @endforeach
      </div>
    @endif
  </main>

  @include('components.scripts')
  @include('components.orb-script')
</body>
</html>
