@include('components.page-head', ['pageTitle' => __('agents.start_title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body class="ag-onb-page">
  @php
    $ar = app()->getLocale() === 'ar';
    $first = trim(strtok((string) (auth()->user()->name ?? ''), ' ')) ?: '';
    $shot = fn (string $name) => asset('images/onboarding/'.($ar ? 'ar' : 'en').'/'.$name.'.webp').'?v=1';
    $check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    $paths = [
      'studio' => ['href' => $studioUrl, 'cta' => __('agents.start_studio_cta'), 'title' => __('agents.start_studio_title'), 'text' => __('agents.start_studio_text'), 'points' => __('agents.start_studio_points', ['dialects' => count(\App\Support\ArabicDialects::ALL), 'accents' => count(\App\Support\EnglishAccents::ALL)]),
        'icon' => '<path d="M3.5 10.5v3M7.5 7.5v9M12 4v16M16.5 7.5v9M20.5 10.5v3"/>'],
      'agents' => ['href' => $agentsUrl, 'cta' => __('agents.empty_cta'), 'title' => __('agents.start_agents_title'), 'text' => __('agents.start_agents_text'), 'points' => __('agents.start_agents_points'),
        'icon' => '<path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/>'],
    ];
  @endphp

  {{-- A new member's first screen, focused like an app's onboarding: the choice on one side, the real
       product on the other, changing as they choose. No site menu, no chat bubble. --}}
  <main class="ag ag-onb" data-ag-onb>
    <section class="ag-onb-pane">
      <div class="ag-onb-top">
        <a class="ag-onb-logo" href="{{ route('home') }}" aria-label="{{ $ar ? 'إسمع' : 'Listen' }}">
          <img src="{{ \App\Support\VersionedAsset::url($ar ? 'images/logo-ar-dark.svg' : 'images/logo-dark.svg') }}" alt="" class="logo-dark">
          <img src="{{ \App\Support\VersionedAsset::url($ar ? 'images/logo-ar-light.svg' : 'images/logo-light.svg') }}" alt="" class="logo-light">
        </a>
        <a class="ag-onb-skip" href="{{ $studioUrl }}">{{ __('agents.start_skip') }}</a>
      </div>

      <div class="ag-onb-body">
        @if($first !== '')<p class="ag-onb-hello">{{ __('agents.start_hello', ['name' => $first]) }}</p>@endif
        <h1>{{ __('agents.start_title') }}</h1>
        <p class="ag-onb-sub">{{ __('agents.start_subtitle') }}</p>

        <div class="ag-onb-options" role="radiogroup" aria-label="{{ __('agents.start_label') }}">
          @foreach($paths as $key => $path)
            <label class="ag-onb-opt">
              <input type="radio" name="path" value="{{ $key }}" @checked($key === 'agents') data-href="{{ $path['href'] }}" data-cta="{{ $path['cta'] }}">
              <span class="ag-onb-opt-ico" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $path['icon'] !!}</svg></span>
              <span class="ag-onb-opt-text">
                <strong>{{ $path['title'] }}</strong>
                <small>{{ $path['text'] }}</small>
              </span>
              <span class="ag-onb-opt-radio" aria-hidden="true"></span>
            </label>
          @endforeach
        </div>

        <a class="ag-btn ag-btn-primary ag-btn-lg ag-onb-go" href="{{ $agentsUrl }}" data-ag-onb-go>
          <span>{{ __('agents.empty_cta') }}</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <p class="ag-onb-note">{{ __('agents.start_continue_note') }}</p>
      </div>
    </section>

    {{-- The real product, as it looks inside Listen. --}}
    <section class="ag-onb-stage" aria-hidden="true">
      @foreach($paths as $key => $path)
        <div class="ag-onb-show is-{{ $key }} {{ $key === 'agents' ? 'is-on' : '' }}" data-for="{{ $key }}">
          <div class="ag-onb-frame">
            <div class="ag-onb-bar"><i></i><i></i><i></i><span dir="ltr">{{ $key === 'studio' ? 'listenai.studio' : 'yourwebsite.com' }}</span></div>
            <img src="{{ $shot($key) }}" alt="" loading="{{ $key === 'agents' ? 'eager' : 'lazy' }}">
          </div>
          <div class="ag-onb-points">
            <p>{{ __('agents.start_shot_'.$key) }}</p>
            <ul>@foreach($path['points'] as $point)<li>{!! $check !!}{{ $point }}</li>@endforeach</ul>
          </div>
        </div>
      @endforeach
    </section>
  </main>

  @include('components.scripts', ['noSupportChat' => true])
  <script>
    // Picking a path changes the product on the right and where Continue goes.
    (function () {
      var box = document.querySelector('[data-ag-onb]');
      if (!box) return;
      var go = box.querySelector('[data-ag-onb-go]');
      function pick(input) {
        go.href = input.getAttribute('data-href');
        go.querySelector('span').textContent = input.getAttribute('data-cta');
        box.querySelectorAll('[data-for]').forEach(function (s) { s.classList.toggle('is-on', s.getAttribute('data-for') === input.value); });
      }
      box.querySelectorAll('input[name="path"]').forEach(function (r) {
        r.addEventListener('change', function () { pick(r); });
        if (r.checked) pick(r);
      });
    })();
  </script>
</body>
</html>
