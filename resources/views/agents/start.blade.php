@include('components.page-head', ['pageTitle' => __('agents.start_title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  @include('components.header')

  <main class="ag" id="agents">
    <section class="ag-start">
      <h1>{{ __('agents.start_title') }}</h1>
      <p>{{ __('agents.start_subtitle') }}</p>
      <div class="ag-start-cards">
        {{-- Clean icons, not orbs: the Studio's sound waves, the agent's call button. --}}
        <a class="ag-card ag-start-card" href="{{ $studioUrl }}">
          <span class="ag-start-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3.5 10.5v3M7.5 7.5v9M12 4v16M16.5 7.5v9M20.5 10.5v3"/></svg></span>
          <h2>{{ __('agents.start_studio_title') }}</h2>
          <p>{{ __('agents.start_studio_text') }}</p>
          <span class="ag-btn ag-btn-sm">{{ __('agents.switch_studio') }}</span>
        </a>
        <a class="ag-card ag-start-card is-agents" href="{{ $agentsUrl }}">
          <span class="ag-start-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/></svg></span>
          <h2>{{ __('agents.start_agents_title') }}</h2>
          <p>{{ __('agents.start_agents_text') }}</p>
          <span class="ag-btn ag-btn-sm ag-btn-primary">{{ __('agents.empty_cta') }}</span>
        </a>
      </div>
      <a class="ag-btn ag-btn-ghost" href="{{ $studioUrl }}" style="margin-top:.4rem">{{ __('agents.start_skip') }}</a>
    </section>
  </main>

  @include('components.scripts')
</body>
</html>
