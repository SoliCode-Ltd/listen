@include('components.page-head', ['pageTitle' => __('agents.start_title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $first = trim(strtok((string) (auth()->user()->name ?? ''), ' ')) ?: '';
    $art = __('agents.start_art');
    $check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  @endphp

  {{-- A new member's first page: two paths, each with a small live picture of what it is. --}}
  <main class="ag ag-welcome" id="agents">
    <header class="ag-welcome-head">
      @if($first !== '')<p class="ag-welcome-hi">{{ __('agents.start_hello', ['name' => $first]) }}</p>@endif
      <h1>{{ __('agents.start_title') }}</h1>
      <p>{{ __('agents.start_subtitle') }}</p>
    </header>

    <div class="ag-welcome-cards">
      {{-- Voice-overs: the Studio, its voices and a player. --}}
      <a class="ag-welcome-card" href="{{ $studioUrl }}" style="--i: 0">
        <div class="ag-welcome-art is-studio" aria-hidden="true">
          <div class="ag-wa-editor">
            <p class="ag-wa-line" dir="auto">{{ $art['line'] }}</p>
            <div class="ag-wa-chips">@foreach($art['chips'] as $i => $chip)<i class="{{ $i === 1 ? 'is-on' : '' }}">{{ $chip }}</i>@endforeach</div>
            <div class="ag-wa-player">
              <b class="ag-wa-play"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z"/></svg></b>
              <span class="ag-wa-wave">@for($b = 0; $b < 26; $b++)<i style="--k: {{ [.35, .6, .9, .55, .75, 1, .5, .8, .4, .7, .95, .6, .45, .85, .65, .4, .75, .55, .9, .5, .7, .35, .6, .8, .45, .3][$b] }}; --d: {{ $b }}"></i>@endfor</span>
              <em dir="ltr">0:08</em>
            </div>
          </div>
        </div>
        <div class="ag-welcome-body">
          <span class="ag-welcome-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3.5 10.5v3M7.5 7.5v9M12 4v16M16.5 7.5v9M20.5 10.5v3"/></svg></span>
          <h2>{{ __('agents.start_studio_title') }}</h2>
          <p>{{ __('agents.start_studio_text') }}</p>
          <ul>@foreach(__('agents.start_studio_points') as $point)<li>{!! $check !!}{{ $point }}</li>@endforeach</ul>
          <span class="ag-btn ag-btn-lg">{{ __('agents.start_studio_cta') }}</span>
        </div>
      </a>

      {{-- A voice agent: a business's website with its call card, the orb moving with the voice. --}}
      <a class="ag-welcome-card is-agents" href="{{ $agentsUrl }}" style="--i: 1">
        <div class="ag-welcome-art is-agents" aria-hidden="true">
          <div class="ag-wa-site"><b></b><b class="is-short"></b><b></b></div>
          <div class="ag-wa-call">
            <div class="ag-wa-call-head"><strong dir="auto">{{ $art['business'] }}</strong><span><i></i>00:12</span></div>
            <div class="ag-wa-orb"><i></i><i></i><b></b></div>
            <p class="ag-wa-state">{{ $art['state'] }}</p>
            <p class="ag-wa-caption" dir="auto">{{ $art['caption'] }}</p>
          </div>
          <span class="ag-wa-button"><i><svg viewBox="0 0 24 24"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/></svg></i>{{ $art['button'] }}</span>
        </div>
        <div class="ag-welcome-body">
          <span class="ag-welcome-icon is-accent" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/></svg></span>
          <h2>{{ __('agents.start_agents_title') }}</h2>
          <p>{{ __('agents.start_agents_text') }}</p>
          <ul>@foreach(__('agents.start_agents_points') as $point)<li>{!! $check !!}{{ $point }}</li>@endforeach</ul>
          <span class="ag-btn ag-btn-lg ag-btn-primary">{{ __('agents.empty_cta') }}</span>
        </div>
      </a>
    </div>

    <a class="ag-welcome-skip" href="{{ $studioUrl }}">{{ __('agents.start_skip') }}</a>
  </main>

  @include('components.scripts')
</body>
</html>
