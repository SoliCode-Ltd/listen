@include('components.page-head', ['pageTitle' => __('agents.talk_page_title', ['business' => $agent->business_name]), 'page' => 'agent_talk'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  <main class="ag ag-talkpage" id="agents" style="max-width:none">
    @php $female = ($voice['gender'] ?? null) === 'female'; @endphp
    <section class="ag-card ag-talkpage-card" data-ag-standalone>
      {{-- Who answers: the business, and its assistant's face next to its name (as on the website's call card). --}}
      <header class="ag-talkpage-who">
        <span class="ag-talkpage-face {{ $available ? 'is-live' : '' }}" aria-hidden="true">
          @if(! empty($voice['avatar']))<img src="{{ $voice['avatar'] }}" alt="" width="56" height="56">@else<b>{{ mb_substr($agent->business_name, 0, 1) }}</b>@endif
        </span>
        <div>
          <h1>{{ $agent->business_name }}</h1>
          <p><b>{{ $agent->name }}</b> · {{ __($female ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle') }}</p>
        </div>
      </header>
      @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voice['avatar'] ?? null, 'showCost' => false, 'female' => ($voice['gender'] ?? null) === 'female'])
      @unless($available)
        <p class="ag-note">{{ __('agents.error_paused') }}</p>
      @endunless
      <p class="ag-talkpage-mic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ __('agents.talk_page_mic') }}</p>
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
        'states' => \App\Support\Agents\Gendered::lines((array) __('agents.talk_states', ['name' => $agent->name]), ($voice['gender'] ?? null) === 'female'),
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
