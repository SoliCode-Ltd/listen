@include('components.page-head', ['pageTitle' => __('account.deleted.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    .acct-gone { min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem 1rem; }
    .acct-gone-card { width: 100%; max-width: 420px; text-align: center; padding: 1.75rem 1.25rem; }
    .acct-gone-card h1 { font-size: 1.4rem; line-height: 1.5; margin: 0 0 .6rem; }
    .acct-gone-card p { color: var(--muted); line-height: 1.7; margin: 0 0 1.25rem; }
    .acct-gone-card .btn { width: 100%; justify-content: center; min-height: 48px; }
  </style>
</head>
<body>
  <main class="acct-gone">
    <article class="page-card acct-gone-card">
      <h1>{{ __('account.deleted.title') }}</h1>
      <p>{{ __('account.deleted.text') }}</p>
      <a class="btn btn-primary" href="{{ $nextUrl }}">{{ __('account.deleted.next') }}</a>
    </article>
  </main>
  @include('components.scripts', ['noSupportChat' => true])
</body>
</html>
