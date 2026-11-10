@include('components.page-head', ['pageTitle' => __('app.signin.done_title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    .app-done { min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem 1rem; }
    .app-done-card { width: 100%; max-width: 420px; text-align: center; padding: 1.75rem 1.25rem; }
    .app-done-card img { height: 40px; width: auto; margin-bottom: 1.25rem; }
    .app-done-card h1 { font-size: 1.4rem; margin: 0 0 .6rem; }
    .app-done-card p { color: var(--muted); line-height: 1.7; margin: 0 0 1.25rem; overflow-wrap: anywhere; }
    .app-done-card .btn { width: 100%; justify-content: center; min-height: 48px; }
    .app-done-hint { font-size: .85rem; margin: .9rem 0 0 !important; }
    html[data-theme="light"] .app-done-card .logo-dark, html:not([data-theme="light"]) .app-done-card .logo-light { display: none; }
  </style>
</head>
<body>
  @php $ar = app()->getLocale() === 'ar'; @endphp
  <main class="app-done">
    <article class="page-card app-done-card">
      <img src="{{ \App\Support\VersionedAsset::url($ar ? 'images/logo-ar-dark.svg' : 'images/logo-dark.svg') }}" alt="{{ $ar ? 'إسمع' : 'Listen' }}" class="logo-dark">
      <img src="{{ \App\Support\VersionedAsset::url($ar ? 'images/logo-ar-light.svg' : 'images/logo-light.svg') }}" alt="{{ $ar ? 'إسمع' : 'Listen' }}" class="logo-light">
      <h1>{{ __('app.signin.done_title') }}</h1>
      <p>{!! __('app.signin.done_text', ['email' => '<bdi>'.e($member->email ?: $member->name).'</bdi>']) !!}</p>
      <a class="btn btn-primary" id="appReturn" href="{{ $appUrl }}">{{ __('app.signin.done_button') }}</a>
      <p class="app-done-hint">{{ __('app.signin.done_hint') }}</p>
    </article>
  </main>
  <script>
    // Straight back to the app; the button is there for browsers that want a tap first.
    window.setTimeout(function () { window.location.href = @json($appUrl); }, 350);
  </script>
</body>
</html>
