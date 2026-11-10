@include('components.page-head', ['pageTitle' => __('app.plan.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    /* The app's account page, in the site's own type and spacing (components/styles). */
    .acct { max-width: 620px; margin: 0 auto; padding: clamp(1.5rem, 5vw, 2.5rem) clamp(1.1rem, 4.5vw, 1.75rem) 4rem; display: grid; gap: 1.5rem; }
    .acct-hello { display: flex; align-items: center; gap: 1rem; }
    .acct-avatar { flex: none; display: grid; place-items: center; width: 60px; height: 60px; border-radius: 50%; overflow: hidden;
      background: var(--brand-accent); color: #fff; font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; }
    .acct-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .acct-hello h1 { margin: 0; font-family: var(--font-display); font-size: clamp(1.55rem, 6vw, 1.9rem); font-weight: 700; line-height: 1.3; letter-spacing: -.01em; overflow-wrap: anywhere; }
    html[dir="rtl"] .acct-hello h1 { letter-spacing: 0; line-height: 1.5; }
    .acct-hello p { margin: .2rem 0 0; color: var(--muted); font-size: 1rem; line-height: 1.6; overflow-wrap: anywhere; }

    .acct-card { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: clamp(1.25rem, 5vw, 1.75rem); }
    .acct-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.35rem; }
    .acct-card h2 { margin: 0; font-family: var(--font-display); font-size: 1.2rem; font-weight: 700; line-height: 1.5; }
    .acct-plan { display: inline-flex; align-items: center; padding: .4rem .95rem; border-radius: 999px; font-size: .95rem; font-weight: 700; line-height: 1.5;
      background: color-mix(in oklab, var(--brand-accent) 14%, transparent); color: var(--brand-accent); }
    html:not([data-theme="light"]) .acct-plan { color: var(--brand-subtle, #A99CFF); }

    .acct-credits { display: flex; align-items: baseline; flex-wrap: wrap; gap: .4rem .65rem; }
    .acct-credits strong { font-family: var(--font-display); font-size: clamp(2.3rem, 11vw, 3rem); font-weight: 800; line-height: 1.15; letter-spacing: -.02em; unicode-bidi: isolate; }
    .acct-credits span { color: var(--muted); font-size: 1.05rem; line-height: 1.6; }
    .acct-bar { height: 10px; margin: 1.1rem 0 .7rem; border-radius: 999px; background: var(--surface2); overflow: hidden; }
    .acct-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--brand-accent), #C56BFF); }
    html[dir="rtl"] .acct-bar i { background: linear-gradient(270deg, var(--brand-accent), #C56BFF); }
    .acct-used { margin: 0; color: var(--muted); font-size: .98rem; line-height: 1.7; }
    .acct-facts { margin: 1.35rem 0 0; padding: 0; list-style: none; border-top: 1px solid var(--line); }
    .acct-facts li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 0 0; font-size: 1rem; line-height: 1.6; }
    .acct-facts span { color: var(--muted); }
    .acct-facts b { font-weight: 700; text-align: end; }
    .acct-cta { margin-top: 1.5rem; }
    .acct-cta .btn { width: 100%; justify-content: center; min-height: 54px; font-size: 1.05rem; border-radius: 14px; }

    .acct-list { margin: 0; padding: 0; list-style: none; }
    .acct-list li + li { border-top: 1px solid var(--line); }
    .acct-row { display: flex; align-items: center; gap: .95rem; width: 100%; min-height: 60px; padding: .55rem 0; background: none; border: 0;
      color: var(--text); font: inherit; font-size: 1.05rem; font-weight: 600; line-height: 1.6; text-align: start; text-decoration: none; cursor: pointer; }
    .acct-row > svg { flex: none; width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .acct-row .acct-ico { color: var(--brand-accent); }
    html:not([data-theme="light"]) .acct-row .acct-ico { color: var(--brand-subtle, #A99CFF); }
    .acct-row span { flex: 1; min-width: 0; }
    .acct-row .acct-go { width: 18px; height: 18px; color: var(--muted); }
    html[dir="rtl"] .acct-row .acct-go { transform: scaleX(-1); }
    .acct-row.is-out, .acct-row.is-out .acct-ico { color: #E5484D; }
    .acct-row.is-quiet { color: var(--muted); font-weight: 500; }
    .acct-row.is-quiet .acct-ico { color: var(--muted); }
    .acct-row:active { opacity: .7; }
    .acct-card.is-list { padding-block: .6rem; }
    .acct-card.is-list h2 { padding: .85rem 0 .35rem; }
  </style>
</head>
<body>
  @php
    $locale = app()->getLocale();
    $member = auth()->user();
    $planName = $plan ? ($plan->getLocalizedName($locale) ?: $plan->name) : __('app.plan.free');
    $left = max(0, (int) ($usage['remaining_chars'] ?? 0));
    $total = max(0, (int) ($usage['plan_chars'] ?? 0));
    $usedPercent = $total > 0 ? max(0, min(100, (int) round(($total - min($left, $total)) / $total * 100))) : 0;
    $packEnds = ! empty($usage['pack_expires_at'])
      ? \Illuminate\Support\Carbon::parse($usage['pack_expires_at'])->locale($locale)->translatedFormat('j F Y')
      : null;
    $firstName = \Illuminate\Support\Str::of((string) $member->name)->squish()->explode(' ')->first() ?: '';
    $go = '<svg class="acct-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
  @endphp
  @include('components.header')

  <main class="main-content acct">
    <header class="acct-hello">
      <div class="acct-avatar" aria-hidden="true">
        @if($member->google_avatar)
          <img src="{{ $member->google_avatar }}" alt="">
        @else
          {{ mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $member->name ?? '') ?: 'U', 0, 1)) }}
        @endif
      </div>
      <div>
        <h1>{{ $firstName !== '' ? __('app.plan.hello', ['name' => $firstName]) : __('app.plan.title') }}</h1>
        <p><bdi dir="ltr">{{ $member->displayContact() }}</bdi></p>
      </div>
    </header>

    <section class="acct-card" aria-labelledby="acctPlan">
      <div class="acct-card-head">
        <h2 id="acctPlan">{{ __('app.plan.plan_heading') }}</h2>
        <span class="acct-plan">{{ $planName }}</span>
      </div>
      <div class="acct-credits">
        <strong dir="ltr">{{ number_format($left) }}</strong>
        <span>{{ __('app.plan.credits_left') }}</span>
      </div>
      @if($total > 0)
        <div class="acct-bar" role="img" aria-label="{{ $usedPercent }}%"><i style="width: {{ $usedPercent }}%"></i></div>
        <p class="acct-used">{{ __('app.plan.used', ['used' => number_format($total - min($left, $total)), 'total' => number_format($total)]) }}</p>
      @endif
      @if($packEnds || $renewsOn)
        <ul class="acct-facts">
          @if($packEnds)
            <li><span>{{ __('app.plan.ends') }}</span><b>{{ $packEnds }}</b></li>
          @elseif($renewsOn)
            <li><span>{{ __('app.plan.renews') }}</span><b>{{ $renewsOn }}</b></li>
          @endif
        </ul>
      @endif
      <div class="acct-cta">
        <a class="btn btn-primary" href="{{ route('app.upgrade') }}" data-app-upgrade data-no-prefetch>{{ __('app.plan.upgrade') }}</a>
      </div>
    </section>

    <section class="acct-card is-list" aria-labelledby="acctMore">
      <h2 id="acctMore">{{ __('app.plan.account') }}</h2>
      <ul class="acct-list">
        <li><a class="acct-row" href="{{ route('contact') }}"><svg class="acct-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.6M12 17h.01"/></svg><span>{{ __('app.plan.help') }}</span>{!! $go !!}</a></li>
        <li><a class="acct-row" href="{{ route('legal.privacy') }}"><svg class="acct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.6-2.9 8.3-7 10-4.1-1.700-7-5.400-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg><span>{{ __('app.plan.privacy') }}</span>{!! $go !!}</a></li>
        <li><a class="acct-row" href="{{ route('legal.terms') }}"><svg class="acct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7z"/><path d="M15 3v4h4M10 12h6M10 16h6"/></svg><span>{{ __('app.plan.terms') }}</span>{!! $go !!}</a></li>
        <li>
          <form method="post" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="acct-row is-out"><svg class="acct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg><span>{{ __('app.plan.sign_out') }}</span></button>
          </form>
        </li>
        <li><a class="acct-row is-quiet" href="{{ route('contact', ['topic' => 'delete']) }}"><svg class="acct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg><span>{{ __('app.plan.delete_account') }}</span>{!! $go !!}</a></li>
      </ul>
    </section>
  </main>

  @include('components.scripts', ['noSupportChat' => true])
</body>
</html>
