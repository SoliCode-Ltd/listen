@include('components.page-head', ['pageTitle' => __('ui.dub.seo_title'), 'page' => 'dub'])
  {{-- The studio's look: the same voice library, pickers and editor card as the dashboard and the demo. --}}
  <link rel="stylesheet" href="{{ asset('css/voice-studio.css') }}?v={{ filemtime(public_path('css/voice-studio.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/dub-studio.css') }}?v={{ filemtime(public_path('css/dub-studio.css')) }}">
  <style>
    .dub-page{padding:2rem 0 3.5rem}
    .dub-hero{text-align:center;max-width:760px;margin:0 auto 1.6rem}
    .dub-hero h1{margin:0 0 .7rem;font-size:clamp(2rem,4.6vw,3rem);font-weight:800;line-height:1.1;letter-spacing:-.02em}
    .dub-hero p{margin:0 auto;max-width:62ch;color:var(--muted);font-size:1.08rem;line-height:1.65}
    .dub-features{display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem;margin:1.3rem 0 0;padding:0;list-style:none}
    .dub-features li{display:inline-flex;align-items:center;gap:.45rem;padding:.42rem .85rem;border:1px solid var(--line);border-radius:999px;background:var(--surface);font-size:.86rem;font-weight:600}
    .dub-features svg{width:16px;height:16px;fill:none;stroke:var(--a);stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
    .dub-costs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem;margin-top:2rem}
    .dub-costs h2{grid-column:1/-1;margin:0;font-size:1.15rem}
    .dub-costs p{margin:0;padding:1rem 1.1rem;border:1px solid var(--line);border-radius:16px;background:var(--surface);color:var(--muted);line-height:1.55}
    .dub-costs strong{display:block;color:var(--text);margin-bottom:.2rem}
    @media (max-width:700px){.dub-costs{grid-template-columns:1fr}}
  </style>
</head>
<body>
  @include('components.header')

  <main class="container dub-page">
    <section class="dub-hero">
      <h1>{{ __('ui.dub.hero_title') }}</h1>
      <p>{{ __('ui.dub.hero_text') }}</p>
      <ul class="dub-features">
        @foreach(['music', 'feeling', 'speakers', 'timing'] as $feature)
          <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>{{ __('ui.dub.feature_'.$feature) }}</li>
        @endforeach
      </ul>
    </section>

    @if(session('dub_error'))
      <p class="dub-error" role="alert" style="text-align:center">{{ session('dub_error') }}</p>
    @endif

    <x-dub.studio :dub="$dubStudio" />

    <section class="dub-costs">
      <h2>{{ __('ui.dub.cost_title') }}</h2>
      <p><strong>{{ __('ui.dub.cost_free_title') }}</strong>{{ __('ui.dub.cost_free', ['seconds' => (int) config('listen.dubbing.max_seconds', 60)]) }}</p>
      <p><strong>{{ __('ui.dub.cost_plan_title') }}</strong>{{ __('ui.dub.cost_plan', ['credits' => \App\Support\Credits::PER_SECOND]) }}</p>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script src="{{ asset('js/voice-studio-icons.js') }}?v={{ filemtime(public_path('js/voice-studio-icons.js')) }}" defer></script>
  <script src="{{ asset('js/dub-studio.js') }}?v={{ filemtime(public_path('js/dub-studio.js')) }}" defer></script>
  <script src="{{ asset('js/voice-studio.js') }}?v={{ filemtime(public_path('js/voice-studio.js')) }}" defer></script>
</body>
</html>
