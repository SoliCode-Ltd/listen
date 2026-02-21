<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Pricing</title>
  <meta name="description" content="Simple, scalable pricing for Listen voice SaaS." />
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
  <style>
    .hero {
      padding: 4rem 0 2rem;
      text-align: center;
    }
    .eyebrow {
      display: inline-block;
      padding: .4rem .85rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .78rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
      margin-bottom: 1rem;
    }
    .hero h1 {
      margin: 0 0 .75rem;
      font-size: clamp(2rem, 5.5vw, 4rem);
      line-height: 1.05;
      letter-spacing: -.03em;
    }
    .hero-copy {
      max-width: 68ch;
      margin: 0 auto;
      color: var(--muted);
      font-size: 1.05rem;
      line-height: 1.6;
    }

    .toggle-wrap {
      display: flex;
      justify-content: center;
      margin: 1.5rem 0 2.5rem;
    }
    .billing-toggle {
      display: inline-flex;
      align-items: center;
      background: linear-gradient(145deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-full);
      padding: .3rem;
      box-shadow: var(--shadow);
    }
    .billing-toggle button {
      border: 0;
      background: transparent;
      color: var(--muted);
      font-weight: 700;
      padding: .5rem 1rem;
      border-radius: var(--radius-full);
      cursor: pointer;
      font-size: .9rem;
      transition: all .2s ease;
    }
    .billing-toggle button.active {
      background: linear-gradient(135deg, var(--a), var(--b));
      color: #fff;
    }

    .plans {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.25rem;
    }
    .plan {
      background: linear-gradient(160deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.75rem;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
      transition: transform .28s ease, border-color .28s ease;
    }
    .plan::after {
      content: "";
      position: absolute;
      inset: auto -20% -55% -20%;
      height: 58%;
      background: radial-gradient(ellipse at center, color-mix(in oklab, var(--b) 24%, transparent), transparent 70%);
      opacity: .45;
      pointer-events: none;
    }
    .plan:hover {
      transform: translateY(-6px);
      border-color: color-mix(in oklab, var(--a) 55%, var(--line));
    }
    .plan.popular {
      border-color: color-mix(in oklab, var(--a) 50%, var(--b));
    }
    .badge {
      position: absolute;
      top: 1rem;
      right: 1rem;
      font-size: .74rem;
      font-weight: 700;
      border: 1px solid color-mix(in oklab, var(--a) 55%, var(--line));
      background: color-mix(in oklab, var(--a) 16%, var(--surface));
      color: color-mix(in oklab, var(--a) 82%, var(--text));
      padding: .28rem .6rem;
      border-radius: var(--radius-full);
      z-index: 1;
    }
    .plan-head h2 {
      margin: .15rem 0 .25rem;
      font-size: 1.3rem;
    }
    .plan-head p {
      margin: 0 0 .5rem;
      color: var(--muted);
      font-size: .9rem;
    }
    .price-row {
      display: flex;
      align-items: flex-end;
      gap: .4rem;
      margin: .5rem 0 .25rem;
    }
    .price {
      font-size: 2.5rem;
      font-weight: 900;
      line-height: 1;
    }
    .price-sub {
      font-size: .9rem;
      color: var(--muted);
      padding-bottom: .3rem;
    }
    .included {
      font-size: .88rem;
      color: var(--muted);
      margin: .2rem 0 1rem;
    }
    .spec-table {
      display: grid;
      gap: .6rem;
    }
    .spec-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: .65rem .75rem;
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 88%, transparent);
    }
    .spec-row dt {
      font-size: .82rem;
      color: var(--muted);
    }
    .spec-row dd {
      margin: 0;
      font-size: .88rem;
      font-weight: 700;
      color: var(--text);
      text-align: right;
    }
    .plan-actions {
      margin-top: 1.25rem;
      position: relative;
      z-index: 1;
    }

    .faq {
      margin-top: 3rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .faq .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.25rem;
    }
    .faq h3 {
      margin: 0 0 .45rem;
      font-size: 1.05rem;
    }
    .faq p {
      margin: 0;
      color: var(--muted);
      font-size: .92rem;
      line-height: 1.55;
    }

    .cta {
      margin-top: 2.5rem;
      margin-bottom: 3rem;
      text-align: center;
      padding: 2rem;
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      background: linear-gradient(145deg, var(--surface2), var(--surface));
    }
    .cta h2 {
      margin: 0 0 .5rem;
    }
    .cta p {
      color: var(--muted);
      margin: 0 0 1rem;
    }

    .reveal {
      opacity: 0;
      transform: translateY(14px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @media (max-width: 960px) {
      .faq {
        grid-template-columns: 1fr;
      }
      .hero {
        padding: 2.5rem 0 1.5rem;
      }
    }
  </style>
  <script>
    (() => {
      const saved = localStorage.getItem('listen_theme');
      const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
      document.documentElement.setAttribute('data-theme', saved || (prefersLight ? 'light' : 'dark'));
    })();
  </script>
</head>
<body>
  @include('components.header')

  <main class="container">
    <section class="hero reveal">
      <span class="eyebrow">Enterprise-ready plans</span>
      <h1>Pricing built for production workloads.</h1>
      <p class="hero-copy">From early validation to high-volume deployment, every plan includes strict entitlement enforcement, predictable usage economics, and bilingual voice coverage.</p>
      <div class="toggle-wrap">
        <div class="billing-toggle">
          <button id="monthlyBtn" class="active" type="button">Monthly</button>
          <button id="yearlyBtn" type="button">Yearly - save 20%</button>
        </div>
      </div>
    </section>

    <section class="plans">
      @forelse($pricingPlans as $plan)
        <article class="plan reveal {{ $plan['is_popular'] ? 'popular' : '' }}" data-month="{{ number_format($plan['price_monthly'], 2, '.', '') }}" data-year="{{ number_format($plan['price_yearly'], 2, '.', '') }}">
          @if($plan['is_popular'])
            <span class="badge">Most Popular</span>
          @endif
          <header class="plan-head">
            <h2>{{ $plan['name'] }}</h2>
            <p>{{ $plan['slug'] === 'free' ? 'Ideal for evaluation and internal testing' : 'Built for client-facing delivery and scale' }}</p>
          </header>
          <div class="price-row">
            <div class="price">
              @if($plan['price_monthly'] <= 0)
                $0
              @else
                $<span class="price-value">{{ rtrim(rtrim(number_format($plan['price_monthly'], 2, '.', ''), '0'), '.') }}</span>
              @endif
            </div>
            @if($plan['price_monthly'] > 0)
              <div class="price-sub">per month</div>
            @endif
          </div>
          <p class="included">{{ number_format($plan['monthly_chars']) }} characters included each month</p>
          <dl class="spec-table">
            <div class="spec-row">
              <dt>Requests</dt>
              <dd>{{ $plan['requests_unlimited'] ? 'Unlimited' : number_format($plan['monthly_requests']).' / month' }}</dd>
            </div>
            <div class="spec-row">
              <dt>Max Input</dt>
              <dd>{{ number_format($plan['max_chars_per_request']) }} chars / request</dd>
            </div>
            <div class="spec-row">
              <dt>Overage</dt>
              <dd>{{ $plan['allow_overage'] ? ('$'.number_format(((int) ($plan['overage_price_per_million_cents'] ?? 0)) / 100, 2).' / 1M chars') : 'No overage' }}</dd>
            </div>
            <div class="spec-row">
              <dt>Commercial Use</dt>
              <dd>{{ $plan['commercial_use_allowed'] ? 'Allowed' : 'Not allowed' }}</dd>
            </div>
            <div class="spec-row">
              <dt>Processing</dt>
              <dd>
                @if($plan['slug'] === 'free')
                  Standard speed
                @elseif(strtolower((string) $plan['processing_priority']) === 'high')
                  High priority processing
                @else
                  Standard priority
                @endif
              </dd>
            </div>
          </dl>
          @if($plan['slug'] === 'free')
            <div class="plan-actions">
              @auth
                <a class="btn {{ $plan['is_popular'] ? 'btn-primary' : '' }}" href="{{ route('dashboard') }}">Use Free Plan</a>
              @else
                <a class="btn {{ $plan['is_popular'] ? 'btn-primary' : '' }}" href="{{ route('register') }}">Create Free Account</a>
              @endauth
            </div>
          @endif
        </article>
      @empty
        <article class="plan reveal">
          <h2 style="margin: .1rem 0 .2rem; font-size: 1.15rem;">No plans found</h2>
          <p style="color: var(--muted);">Please seed plans in the database.</p>
        </article>
      @endforelse
    </section>

    <section class="faq reveal">
      <article class="card">
        <h3>Can I change plan any time?</h3>
        <p>Yes. Upgrade instantly, downgrade at next billing cycle.</p>
      </article>
      <article class="card">
        <h3>What happens if I hit limits?</h3>
        <p>You can upgrade immediately or wait for monthly reset.</p>
      </article>
      <article class="card">
        <h3>Do you support both Arabic and English?</h3>
        <p>Yes - all paid plans include both language families.</p>
      </article>
      <article class="card">
        <h3>Can I start with a free account?</h3>
        <p>Absolutely. You can register free and move up when usage grows.</p>
      </article>
    </section>

    <section class="cta reveal">
      <h2>Ready to grow fast?</h2>
      <p>Start free, then move up as your usage scales.</p>
      @auth
        <a class="btn btn-primary" href="{{ route('dashboard') }}">Dashboard</a>
      @else
        <a class="btn btn-primary" href="{{ route('register') }}">Create Your Account</a>
      @endauth
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script>
    const monthlyBtn = document.getElementById('monthlyBtn');
    const yearlyBtn = document.getElementById('yearlyBtn');
    const plans = Array.from(document.querySelectorAll('.plan'));

    function formatPrice(v) {
      const n = Number(v || 0);
      return n % 1 === 0 ? String(n) : n.toFixed(2).replace(/\.00$/, '');
    }

    function setBilling(mode) {
      const isYearly = mode === 'yearly';
      monthlyBtn?.classList.toggle('active', !isYearly);
      yearlyBtn?.classList.toggle('active', isYearly);

      plans.forEach((plan) => {
        const priceEl = plan.querySelector('.price-value');
        if (!priceEl) return;
        const value = isYearly ? plan.dataset.year : plan.dataset.month;
        priceEl.textContent = formatPrice(value);
        const sub = plan.querySelector('.price-sub');
        if (sub) sub.textContent = isYearly ? 'per month (annual billing)' : 'per month';
      });
    }

    monthlyBtn?.addEventListener('click', () => setBilling('monthly'));
    yearlyBtn?.addEventListener('click', () => setBilling('yearly'));
  </script>
</body>
</html>
