@include('components.page-head', ['pageTitle' => __('app.web.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    .openweb { min-height: calc(100dvh - 90px); display: grid; place-items: center; padding: 2rem clamp(1.1rem, 4.5vw, 1.75rem) 4rem; }
    .openweb-card { width: 100%; max-width: 460px; text-align: center; background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: clamp(1.75rem, 6vw, 2.25rem) clamp(1.25rem, 5vw, 1.75rem); }
    .openweb-mark { display: grid; place-items: center; width: 68px; height: 68px; margin: 0 auto 1.35rem; border-radius: 20px;
      background: color-mix(in oklab, var(--brand-accent) 14%, transparent); color: var(--brand-accent); }
    html:not([data-theme="light"]) .openweb-mark { color: var(--brand-subtle, #A99CFF); }
    .openweb-mark svg { width: 32px; height: 32px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .openweb-card h1 { margin: 0 0 .7rem; font-family: var(--font-display); font-size: clamp(1.45rem, 6vw, 1.7rem); font-weight: 700; line-height: 1.4; }
    .openweb-card p { margin: 0 0 1.6rem; color: var(--muted); font-size: 1.02rem; line-height: 1.8; }
    .openweb-card .btn { width: 100%; justify-content: center; min-height: 54px; font-size: 1.05rem; border-radius: 14px; }
    .openweb-card .btn + .btn { margin-top: .7rem; }
  </style>
</head>
<body>
  @include('components.header')
  <main class="main-content openweb">
    <section class="openweb-card">
      <div class="openweb-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div>
      <h1>{{ __('app.web.title') }}</h1>
      <p>{{ auth()->check() ? __('app.web.text') : __('app.web.text_guest') }}</p>
      <a class="btn btn-primary" href="{{ $webUrl }}" data-app-open-web data-no-prefetch>{{ __('app.web.button') }}</a>
      <a class="btn" href="{{ $backUrl }}" data-app-web-back>{{ __('app.web.back') }}</a>
    </section>
  </main>
  <script>window.LISTEN_APP_OPEN_WEB = @json($webUrl);</script>
  @include('components.scripts', ['noSupportChat' => true])
</body>
</html>
