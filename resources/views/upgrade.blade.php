<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Upgrade', 'page' => 'upgrade'])
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  @include('components.styles')
  <style>
    .main { padding: 1.8rem 0 3rem; }
    .hero { text-align: center; margin-bottom: 1.5rem; }
    .hero h1 { margin: 0 0 .5rem; font-size: clamp(2rem, 4vw, 3rem); }
    .hero p { margin: 0; color: var(--muted); }
    .plans { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
    .plan { position: relative; background: linear-gradient(170deg, var(--surface2), var(--surface)); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 1.3rem; box-shadow: var(--shadow); }
    .plan.popular { border-color: color-mix(in oklab, var(--a) 50%, var(--line)); }
    .badge { position: absolute; top: .8rem; right: .8rem; padding: .25rem .6rem; border-radius: var(--radius-full); font-size: .74rem; border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line)); background: color-mix(in oklab, var(--a) 14%, var(--surface)); color: var(--text); }
    .plan h2 { margin: 0 0 .3rem; }
    .price { font-size: 2rem; font-weight: 800; margin: .4rem 0 .2rem; }
    .spec { display: grid; gap: .45rem; margin-top: .75rem; }
    .row { display: flex; justify-content: space-between; gap: .8rem; padding: .55rem .6rem; border: 1px solid color-mix(in oklab, var(--line) 70%, transparent); border-radius: var(--radius); background: color-mix(in oklab, var(--surface) 88%, transparent); font-size: .86rem; }
    .row span:first-child { color: var(--muted); }
    .actions { margin-top: .9rem; display: flex; gap: .55rem; }
    .btn[disabled] { opacity: .55; pointer-events: none; }
    @media (max-width: 980px) { .plans { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  @include('components.header')
  <main class="container main">
    <section class="hero">
      <h1>Choose the right plan for your workload</h1>
      <p>Compare limits, priority, commercial use, overage pricing, and voice quality in one view.</p>
    </section>

    <section class="plans">
      @foreach($plans as $plan)
        <article class="plan {{ $plan['is_popular'] ? 'popular' : '' }}">
          @if($plan['is_popular'])
            <span class="badge">Most Popular</span>
          @endif
          <h2>{{ $plan['name'] }}</h2>
          <div class="price">
            @if($plan['price_monthly'] <= 0)
              $0
            @else
              ${{ rtrim(rtrim(number_format($plan['price_monthly'], 2, '.', ''), '0'), '.') }}
              <small style="font-size:.85rem; color:var(--muted); font-weight:600;">/ month</small>
            @endif
          </div>
          <small style="color:var(--muted);">Yearly equivalent: ${{ rtrim(rtrim(number_format($plan['price_yearly'], 2, '.', ''), '0'), '.') }} / month</small>

          <div class="spec">
            <div class="row"><span>Characters / month</span><span>{{ number_format($plan['monthly_chars']) }}</span></div>
            <div class="row"><span>Requests</span><span>{{ $plan['requests_unlimited'] ? 'Unlimited' : number_format($plan['monthly_requests']).' / month' }}</span></div>
            <div class="row"><span>Commercial use</span><span>{{ $plan['commercial_use_allowed'] ? 'Allowed' : 'Not allowed' }}</span></div>
            <div class="row"><span>Processing priority</span><span>{{ ucfirst($plan['processing_priority']) }}</span></div>
            <div class="row"><span>Overage</span><span>{{ $plan['overage_label'] }}</span></div>
            <div class="row"><span>Voice Quality</span><span>{{ $plan['voice_tier'] }}</span></div>
          </div>

          <div class="actions">
            @if($plan['slug'] === $currentPlanSlug)
              <button class="btn" disabled>Current Plan</button>
            @elseif($plan['slug'] === 'creator')
              <a class="btn btn-primary" href="{{ route('billing.page', ['plan' => 'creator']) }}">Upgrade to Creator</a>
            @elseif($plan['slug'] === 'pro')
              <a class="btn btn-primary" href="{{ route('billing.page', ['plan' => 'pro']) }}">Upgrade to Pro</a>
            @else
              <a class="btn" href="{{ route('dashboard') }}">Current Free Access</a>
            @endif
          </div>
        </article>
      @endforeach
    </section>
  </main>
  @include('components.footer')
  @include('components.scripts')
</body>
</html>
