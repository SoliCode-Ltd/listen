{{--
  "Sign in with Listen": an AI app asks to use the member's account
  (App\Mcp\SignInWithListen::consent). It says who is asking, where the
  access goes and what it allows, before anything is granted. A plan that
  cannot connect apps gets the reason and the way forward instead.
--}}
@include('components.page-head', ['pageTitle' => __('connect.page_title')])
@php
  $c = fn (string $key, array $replace = []) => __('connect.'.$key, $replace);
  $app = (string) $client->name;
  $initial = mb_strtoupper(mb_substr(trim($app) !== '' ? trim($app) : '?', 0, 1));
  $canConnect = $status === \App\Support\Api\ApiPlanAccess::OK;
  // A sentence with a name or an address in it, kept left to right inside Arabic.
  $withLtr = fn (string $key, string $placeholder, string $value) => str_replace('@@', '<bdi dir="ltr">'.e($value).'</bdi>', e($c($key, [$placeholder => '@@'])));
@endphp
<style>
  .connect-page { min-height: calc(100vh - 160px); display: grid; place-items: center; padding: 2.5rem 1rem 3.5rem; }
  .connect-card { width: min(460px, 100%); padding: 2rem 2rem 1.6rem; border-radius: 20px; border: 1px solid var(--line); background: var(--surface); box-shadow: var(--shadow); }

  /* The two parties, joined: the one memorable thing on the page. */
  .connect-pair { display: flex; align-items: center; justify-content: center; gap: .5rem; margin-bottom: 1.5rem; direction: ltr; }
  .connect-mark { flex: none; display: grid; place-items: center; width: 56px; height: 56px; border-radius: 16px; }
  .connect-mark.is-listen { background: var(--brand-accent); }
  .connect-mark.is-listen svg { width: 34px; height: 34px; }
  .connect-mark.is-app { border: 1px solid var(--line); background: color-mix(in oklab, var(--text) 4%, var(--surface)); color: var(--text); font-size: 1.45rem; font-weight: 700; }
  .connect-link { display: flex; align-items: center; gap: .3rem; color: var(--muted); }
  .connect-link i { display: block; width: 18px; height: 0; border-top: 2px dotted color-mix(in oklab, var(--muted) 60%, transparent); }
  .connect-link svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

  .connect-card h1 { margin: 0 0 .45rem; font-size: 1.35rem; line-height: 1.35; text-align: center; overflow-wrap: anywhere; }
  .connect-who { margin: 0 0 1.4rem; color: var(--muted); font-size: .9rem; text-align: center; overflow-wrap: anywhere; }
  .connect-who bdi { color: var(--text); font-weight: 600; }

  .connect-can { margin: 0 0 .65rem; font-size: .92rem; font-weight: 650; overflow-wrap: anywhere; }
  .connect-list { list-style: none; margin: 0 0 1.2rem; padding: 0; display: grid; gap: .55rem; }
  .connect-list li { display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: .65rem; align-items: start; font-size: .94rem; line-height: 1.5; }
  .connect-list svg { width: 20px; height: 20px; margin-top: .1rem; fill: none; stroke: var(--brand-accent); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

  .connect-fine { margin: 0 0 1.4rem; padding: .85rem 1rem; border-radius: 12px; background: color-mix(in oklab, var(--text) 4%, var(--surface)); color: var(--muted); font-size: .86rem; line-height: 1.6; }
  .connect-fine p { margin: 0; }
  .connect-fine p + p { margin-top: .35rem; }
  .connect-fine bdi { color: var(--text); font-weight: 600; }

  .connect-body { margin: 0 0 1.4rem; color: var(--muted); font-size: .95rem; line-height: 1.65; text-align: center; }

  .connect-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
  .connect-actions.is-single { grid-template-columns: 1fr; }
  .connect-actions form { margin: 0; }
  .connect-btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 46px; padding: 0 1rem; border-radius: var(--btn-radius, 12px); border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-weight: 650; font-size: .95rem; text-decoration: none; cursor: pointer; }
  .connect-btn:hover { border-color: color-mix(in oklab, var(--brand-accent) 40%, var(--line)); }
  .connect-btn.primary { border-color: transparent; background: var(--brand-accent); color: #fff; }
  .connect-btn.primary:hover { background: var(--brand-hover, var(--brand-accent)); }
  .connect-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }

  @media (max-width: 480px) {
    .connect-page { padding: 1.25rem .75rem 2.5rem; align-items: start; }
    .connect-card { padding: 1.5rem 1.2rem 1.25rem; border-radius: 16px; }
    .connect-actions { grid-template-columns: 1fr; }
    .connect-actions .is-allow { order: -1; }
  }

  /* A plain bar: the brand and the page's own language switch (the site header's links lead to /en or /ar pages). */
  .connect-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; width: min(1120px, 100% - 2rem); margin: 0 auto; padding: 1.1rem 0; }
  .connect-bar img { display: block; height: 30px; width: auto; }
  .connect-lang { padding: .45rem .85rem; border-radius: 999px; border: 1px solid var(--line); color: var(--text); font-size: .88rem; font-weight: 600; text-decoration: none; }
  .connect-lang:hover { border-color: color-mix(in oklab, var(--brand-accent) 40%, var(--line)); }
  .connect-lang:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  html[data-theme="light"] .connect-bar .logo-dark, html:not([data-theme="light"]) .connect-bar .logo-light { display: none; }
</style>
<script>
  (() => {
    // The theme the member chose on the site.
    try {
      const saved = localStorage.getItem('listen_theme');
      if (saved) document.documentElement.setAttribute('data-theme', saved);
    } catch (e) {}
  })();
</script>
</head>
<body>
  @php($arabicUi = app()->getLocale() === 'ar')
  <header class="connect-bar">
    <a href="{{ url('/'.app()->getLocale()) }}" aria-label="{{ $arabicUi ? 'إسمع' : 'Listen' }}">
      <img class="logo-dark" src="{{ \App\Support\VersionedAsset::url($arabicUi ? 'images/logo-ar-dark.svg' : 'images/logo-dark.svg') }}" alt="{{ $arabicUi ? 'إسمع' : 'Listen' }}">
      <img class="logo-light" src="{{ \App\Support\VersionedAsset::url($arabicUi ? 'images/logo-ar-light.svg' : 'images/logo-light.svg') }}" alt="">
    </a>
    <a class="connect-lang" href="{{ $switchUrl }}" lang="{{ $arabicUi ? 'en' : 'ar' }}" hreflang="{{ $arabicUi ? 'en' : 'ar' }}">{{ $arabicUi ? 'English' : 'العربية' }}</a>
  </header>

  <main class="connect-page">
    <section class="connect-card" aria-labelledby="connectTitle">
      <div class="connect-pair" aria-hidden="true">
        <span class="connect-mark is-app">{{ $initial }}</span>
        <span class="connect-link"><i></i><svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg><i></i></span>
        <span class="connect-mark is-listen"><svg viewBox="0 0 128 128"><rect x="18" y="59" width="8" height="10" rx="4" fill="#fff"/><rect x="32" y="46" width="8" height="36" rx="4" fill="#fff"/><rect x="46" y="35" width="8" height="58" rx="4" fill="#fff"/><rect x="60" y="24" width="8" height="80" rx="4" fill="#fff"/><rect x="74" y="35" width="8" height="58" rx="4" fill="#fff"/><rect x="88" y="46" width="8" height="36" rx="4" fill="#fff"/><rect x="102" y="59" width="8" height="10" rx="4" fill="#fff"/></svg></span>
      </div>

      @if($canConnect)
        <h1 id="connectTitle">{{ $c('title', ['app' => $app]) }}</h1>
        <p class="connect-who">{!! $withLtr('signed_in_as', 'email', (string) ($user->email ?: $user->name)) !!}</p>

        <p class="connect-can">{{ $c('can_title', ['app' => $app]) }}</p>
        <ul class="connect-list">
          @foreach(['speak', 'voices', 'history', 'account'] as $item)
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><span>{{ $c('can.'.$item) }}</span></li>
          @endforeach
        </ul>

        <div class="connect-fine">
          <p>{{ $c('cannot') }}</p>
          <p>{{ $c('manage') }}</p>
          @if($returnsTo !== '')<p>{!! $withLtr('returns_to', 'host', $returnsTo) !!}</p>@endif
        </div>

        <div class="connect-actions">
          <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="connect-btn">{{ $c('cancel') }}</button>
          </form>
          <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="is-allow" id="connectAllow">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="connect-btn primary">{{ $c('allow') }}</button>
          </form>
        </div>
      @else
        @php($trial = $status === \App\Support\Api\ApiPlanAccess::TRIAL)
        <h1 id="connectTitle">{{ $trial ? $c('trial_title') : $c('plan_title') }}</h1>
        <p class="connect-body">{{ $trial ? $c('trial_body', ['app' => $app]) : $c('plan_body', ['app' => $app, 'plan' => $planName]) }}</p>
        <div class="connect-actions {{ $trial ? 'is-single' : '' }}">
          <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="connect-btn">{{ $c('cancel') }}</button>
          </form>
          @unless($trial)
            <a class="connect-btn primary is-allow" href="{{ route('pricing') }}">{{ $c('see_plans') }}</a>
          @endunless
        </div>
      @endif
    </section>
  </main>
</body>
</html>
