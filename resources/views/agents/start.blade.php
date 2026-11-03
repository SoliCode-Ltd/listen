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
        <a class="ag-card ag-start-card" href="{{ $studioUrl }}" data-orb-host>
          <x-orb name="arabic" />
          <h2>{{ __('agents.start_studio_title') }}</h2>
          <p>{{ __('agents.start_studio_text') }}</p>
          <span class="ag-btn ag-btn-sm">{{ __('agents.switch_studio') }}</span>
        </a>
        <a class="ag-card ag-start-card" href="{{ $agentsUrl }}" data-orb-host>
          <x-orb name="listen" />
          <h2>{{ __('agents.start_agents_title') }}</h2>
          <p>{{ __('agents.start_agents_text') }}</p>
          <span class="ag-btn ag-btn-sm ag-btn-primary">{{ __('agents.empty_cta') }}</span>
        </a>
      </div>
      <a class="ag-btn ag-btn-ghost" href="{{ $studioUrl }}" style="margin-top:.4rem">{{ __('agents.start_skip') }}</a>
    </section>
  </main>

  @include('components.scripts')
  @include('components.orb-script')
</body>
</html>
