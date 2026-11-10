@include('components.page-head', ['pageTitle' => __('app.plan.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    .app-plan { max-width: 560px; margin: 0 auto; padding: 1.25rem 1rem 3rem; display: grid; gap: 1rem; }
    .app-plan h1 { font-size: 1.5rem; margin: 0 0 .9rem; }
    .app-plan h2 { font-size: 1.05rem; margin: 0 0 .7rem; }
    .app-plan .page-card { padding: 1.15rem; }
    .app-plan .info-list { display: grid; gap: 0; margin: 0; border: 1px solid var(--line, rgba(127,127,127,.25)); border-radius: 14px; overflow: hidden; }
    .app-plan .info-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .8rem .95rem; }
    .app-plan .info-row + .info-row { border-top: 1px solid var(--line, rgba(127,127,127,.25)); }
    .app-plan .info-label { color: var(--muted); font-size: .95rem; }
    .app-plan .info-value { font-weight: 700; text-align: end; }
    .app-plan .status.done { display: inline-block; padding: .2rem .7rem; border-radius: 999px; background: color-mix(in oklab, var(--brand-accent) 16%, transparent); color: var(--brand-accent); font-size: .9rem; }
    .app-plan-note { margin: .9rem 0 0; color: var(--muted); font-size: .95rem; line-height: 1.7; }
    .app-plan-links { display: grid; gap: .55rem; }
    .app-plan-links .btn { width: 100%; justify-content: center; min-height: 46px; }
    .app-plan-who { color: var(--muted); font-size: .92rem; margin: 0 0 .9rem; overflow-wrap: anywhere; }
    .app-plan-who bdi { color: var(--text); }
  </style>
</head>
<body>
  @php
    $locale = app()->getLocale();
    $planName = $plan
      ? ($plan->getLocalizedName($locale) ?: $plan->name)
      : __('app.plan.free');
    $endsOn = ! empty($usage['pack_expires_at'])
      ? \Illuminate\Support\Carbon::parse($usage['pack_expires_at'])->locale($locale)->translatedFormat('j F Y')
      : null;
  @endphp
  @include('components.header')

  <main class="main-content app-plan">
    <article class="page-card">
      <h1><span class="grad-text">{{ __('app.plan.title') }}</span></h1>
      <div class="info-list">
        <div class="info-row"><span class="info-label">{{ __('app.plan.current') }}</span><span class="info-value"><span class="status done">{{ $planName }}</span></span></div>
        <div class="info-row"><span class="info-label">{{ __('app.plan.credits_left') }}</span><span class="info-value" dir="ltr">{{ number_format((int) ($usage['remaining_chars'] ?? 0)) }}</span></div>
        @if($endsOn)
          <div class="info-row"><span class="info-label">{{ __('app.plan.ends') }}</span><span class="info-value">{{ $endsOn }}</span></div>
        @endif
      </div>
      <p class="app-plan-note" data-in-app-note>{{ __('app.not_sold_here') }}</p>
      <div class="app-plan-links" style="margin-top: 1rem;">
        <a class="btn btn-primary" href="{{ $studioUrl }}">{{ __('app.plan.back') }}</a>
      </div>
    </article>

    <article class="page-card">
      <h2>{{ __('app.plan.account') }}</h2>
      <p class="app-plan-who">{{ __('app.plan.signed_in_as') }} <bdi>{{ auth()->user()->email ?: auth()->user()->name }}</bdi></p>
      <div class="app-plan-links">
        <a class="btn" href="{{ route('contact') }}">{{ __('app.plan.help') }}</a>
        <a class="btn" href="{{ route('legal.privacy') }}">{{ __('app.plan.privacy') }}</a>
        <a class="btn" href="{{ route('legal.terms') }}">{{ __('app.plan.terms') }}</a>
        <a class="btn" href="{{ route('contact', ['topic' => 'delete']) }}">{{ __('app.plan.delete_account') }}</a>
        <form method="post" action="{{ route('logout') }}" style="margin: 0;">
          @csrf
          <button type="submit" class="btn" style="width: 100%; justify-content: center; min-height: 46px;">{{ __('app.plan.sign_out') }}</button>
        </form>
      </div>
    </article>
  </main>

  @include('components.scripts', ['noSupportChat' => true])
</body>
</html>
