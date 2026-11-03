@include('components.page-head', ['pageTitle' => __('agents.talk_page_title', ['business' => $agent->business_name]), 'page' => 'agent_talk'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  <main class="ag ag-talkpage" id="agents" style="max-width:none">
    <section class="ag-card ag-talkpage-card" data-ag-standalone>
      <div style="display:grid;gap:.35rem">
        <h1>{{ __('agents.talk_page_title', ['business' => $agent->business_name]) }}</h1>
        <p class="ag-muted">{{ __(($voice['gender'] ?? null) === 'female' ? 'agents.talk_page_subtitle_female' : 'agents.talk_page_subtitle', ['name' => $agent->name]) }}</p>
      </div>
      @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voice['avatar'] ?? null, 'showCost' => false])
      @unless($available)
        <p class="ag-note">{{ __('agents.error_paused') }}</p>
      @endunless
      <p class="ag-talkpage-foot"><a href="{{ url('/') }}" target="_blank" rel="noopener">{{ __('agents.powered') }}</a></p>
    </section>
  </main>

  @include('components.scripts', ['noSupportChat' => true])
  @include('components.orb-script')
  @php
    $agentsPage = [
      'mode' => 'link',
      'slug' => $widget->slug,
      'available' => $available,
      'start' => url('/agent-api/calls'),
      'agent' => ['name' => $agent->name, 'language' => $agent->language, 'gender' => $voice['gender'] ?? null],
      'text' => [
        'states' => __('agents.talk_states', ['name' => $agent->name]),
        'start' => __('agents.talk_start'), 'end' => __('agents.talk_end'),
        'mute' => __('agents.talk_mute'), 'unmute' => __('agents.talk_unmute'),
        'mic' => __('agents.talk_mic_blocked'), 'you' => __('agents.talk_you'),
        'paused' => __('agents.error_paused'),
      ],
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
