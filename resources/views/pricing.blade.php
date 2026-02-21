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
  <style>
    :root{--bg:#070910;--surface:#11182a;--surface2:#15213a;--line:#2b3a5d;--text:#f4f7ff;--muted:#9fb0d4;--a:#fb8308;--b:#5906b2;--shadow:0 16px 46px rgba(0,0,0,.30)}
    html[data-theme="light"]{--bg:#eff3ff;--surface:#fff;--surface2:#f7f9ff;--line:#d8e2f1;--text:#121a2d;--muted:#5b6c89;--shadow:0 14px 34px rgba(35,56,97,.13)}
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;background:radial-gradient(1200px 500px at 92% -20%,color-mix(in oklab,var(--b) 25%,transparent),transparent 60%),radial-gradient(900px 420px at -5% 0%,color-mix(in oklab,var(--a) 18%,transparent),transparent 58%),var(--bg);color:var(--text)}
    .container{width:min(1200px,92%);margin:auto}

    .header{position:sticky;top:0;z-index:60;background:color-mix(in oklab,var(--bg) 82%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
    .nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:.8rem}
    .brand{display:flex;align-items:center;gap:.55rem;font-weight:900;text-decoration:none;color:var(--text)}
    .brand-icon{width:1.2rem;height:1.2rem;display:block}
    .brand-icon svg{width:100%;height:100%;display:block;fill:currentColor}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}

    .desktop-nav,.desktop-actions{display:flex;align-items:center;gap:.55rem}
    .desktop-nav a,.mobile-menu a{text-decoration:none;color:var(--muted);font-weight:600;padding:.5rem .62rem;border-radius:.6rem}
    .desktop-nav a:hover,.mobile-menu a:hover{color:var(--text);background:color-mix(in oklab,var(--surface) 85%,transparent)}

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.7rem .92rem;border-radius:.72rem;border:1px solid var(--line);background:var(--surface);color:var(--text);font-weight:700;text-decoration:none;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
    .btn:hover{transform:translateY(-2px);box-shadow:0 10px 26px color-mix(in oklab,var(--b) 22%,transparent)}
    .btn.primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}
    .theme-btn{padding:.58rem;min-width:2.4rem}
    .theme-btn .icon{width:1rem;height:1rem;stroke:currentColor;fill:none;stroke-width:1.9}
    .theme-btn .sun{display:none}
    html[data-theme="light"] .theme-btn .sun{display:block}
    html[data-theme="light"] .theme-btn .moon{display:none}

    .user-chip{display:flex;align-items:center;gap:.5rem;padding:.34rem .5rem;border:1px solid var(--line);border-radius:999px;background:var(--surface)}
    .user-avatar{width:1.9rem;height:1.9rem;border-radius:999px;display:grid;place-items:center;background:linear-gradient(135deg,color-mix(in oklab,var(--a) 36%,transparent),color-mix(in oklab,var(--b) 36%,transparent));font-size:.8rem;font-weight:800;color:#fff;overflow:hidden}
    .user-avatar img{width:100%;height:100%;object-fit:cover}
    .user-meta{display:grid;line-height:1.1}
    .user-meta strong{font-size:.74rem}
    .user-meta small{font-size:.66rem;color:var(--muted)}

    .mobile-controls{display:none;align-items:center;gap:.45rem}
    .mobile-menu{display:none;padding:0 0 1rem}
    .mobile-menu.open{display:grid;gap:.35rem}
    .mobile-user{padding:.42rem .62rem;border-radius:.62rem;background:var(--surface);border:1px solid var(--line);font-size:.8rem;color:var(--muted)}

    .hero{padding:3.5rem 0 1.8rem;text-align:center}
    .eyebrow{display:inline-block;padding:.34rem .7rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
    h1{margin:.72rem 0 .7rem;font-size:clamp(2rem,5.5vw,4.2rem);line-height:1.04;letter-spacing:-.03em}
    .muted{color:var(--muted)}
    .hero-copy{max-width:72ch;margin:0 auto}

    .toggle-wrap{display:flex;justify-content:center;margin:1.2rem 0 1.6rem}
    .toggle{display:inline-flex;align-items:center;background:linear-gradient(145deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:999px;padding:.28rem;box-shadow:var(--shadow)}
    .toggle button{border:0;background:transparent;color:var(--muted);font-weight:800;padding:.44rem .86rem;border-radius:999px;cursor:pointer}
    .toggle button.active{background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

    .plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.15rem}
    .plan{background:linear-gradient(160deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1.05rem;padding:1.35rem;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:transform .26s ease,border-color .26s ease}
    .plan::after{content:"";position:absolute;inset:auto -20% -55% -20%;height:58%;background:radial-gradient(ellipse at center,color-mix(in oklab,var(--b) 24%,transparent),transparent 70%);opacity:.45;pointer-events:none}
    .plan:hover{transform:translateY(-5px);border-color:color-mix(in oklab,var(--a) 52%,var(--line) 48%)}
    .plan.popular{border-color:color-mix(in oklab,var(--a) 46%,var(--b) 54%)}
    .badge{position:absolute;top:.9rem;right:.9rem;font-size:.72rem;font-weight:700;border:1px solid color-mix(in oklab,var(--a) 55%,var(--line) 45%);background:color-mix(in oklab,var(--a) 16%,var(--surface) 84%);color:color-mix(in oklab,var(--a) 82%,var(--text) 18%);padding:.22rem .54rem;border-radius:999px;z-index:1}
    .plan-head h2{margin:.1rem 0 .2rem;font-size:1.2rem}
    .plan-head p{margin:0 0 .45rem}
    .price-row{display:flex;align-items:flex-end;gap:.35rem;margin:.4rem 0 .2rem}
    .price{font-size:2.2rem;font-weight:900;line-height:1}
    .price-sub{font-size:.86rem;color:var(--muted);padding-bottom:.26rem}
    .included{font-size:.84rem;color:var(--muted);margin:.18rem 0 .86rem}
    .spec-table{display:grid;gap:.56rem}
    .spec-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.58rem .68rem;border:1px solid color-mix(in oklab,var(--line) 75%,transparent);border-radius:.74rem;background:color-mix(in oklab,var(--surface) 88%,transparent)}
    .spec-row dt{font-size:.8rem;color:var(--muted)}
    .spec-row dd{margin:0;font-size:.86rem;font-weight:700;color:var(--text);text-align:right}
    .plan-actions{margin-top:1.08rem;position:relative;z-index:1}

    .faq{margin-top:1.9rem;display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
    .faq .card{background:var(--surface);border:1px solid var(--line);border-radius:.85rem;padding:.85rem}
    .faq h3{margin:.1rem 0 .35rem;font-size:1rem}

    .cta{margin-top:1.5rem;text-align:center;padding:1.2rem;border:1px solid var(--line);border-radius:1rem;background:linear-gradient(145deg,var(--surface2),var(--surface))}
    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted);margin-top:2rem}

    @media (max-width:960px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .faq{grid-template-columns:1fr}
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
  <header class="header reveal">
    <div class="container nav">
      <a class="brand" href="{{ route('home') }}" aria-label="Listen home">
        <span class="brand-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" role="img"><path d="M12 3a8 8 0 0 0-8 8v6a3 3 0 0 0 3 3h1.5a2.5 2.5 0 0 0 2.5-2.5v-3A2.5 2.5 0 0 0 8.5 12H6a6 6 0 1 1 12 0h-2.5a2.5 2.5 0 0 0-2.5 2.5v3a2.5 2.5 0 0 0 2.5 2.5H17a3 3 0 0 0 3-3v-6a8 8 0 0 0-8-8Z"/></svg>
        </span>
        <span>Listen</span>
      </a>

      <nav class="desktop-nav">
        <a href="{{ route('home') }}">Home</a>
        @guest
          <a href="{{ route('demo.page') }}">Demo</a>
        @endguest
        <a href="{{ route('pricing') }}">Pricing</a>
        <a href="{{ route('extension.page') }}">Chrome Extension</a>
      </nav>

      <div class="desktop-actions">
        <button type="button" class="btn theme-btn" id="themeToggleDesktop" aria-label="Toggle theme">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
        @auth
          <div class="user-chip">
            <div class="user-avatar">
              @if(auth()->user()->google_avatar)
                <img src="{{ auth()->user()->google_avatar }}" alt="{{ auth()->user()->name }}" />
              @else
                {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
              @endif
            </div>
          <div class="user-meta">
            <strong>{{ auth()->user()->name }}</strong>
            <small>{{ auth()->user()->email }}</small>
          </div>
          </div>
          <a class="btn primary" href="{{ route('dashboard') }}">Dashboard</a>
        @else
          <a class="btn" href="{{ route('login') }}">Login</a>
          <a class="btn primary" href="{{ route('register') }}">Start Free</a>
        @endauth
      </div>

      <div class="mobile-controls">
        <button type="button" class="btn theme-btn" id="themeToggleMobile" aria-label="Toggle theme">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
        <button type="button" class="btn" id="mobileMenuBtn" aria-label="Menu">Menu</button>
      </div>
    </div>
    <div class="container mobile-menu" id="mobileMenu">
      <a href="{{ route('home') }}">Home</a>
      @guest
        <a href="{{ route('demo.page') }}">Demo</a>
      @endguest
      <a href="{{ route('pricing') }}">Pricing</a>
      <a href="{{ route('extension.page') }}">Chrome Extension</a>
      @auth
        <div class="mobile-user">Logged in as {{ auth()->user()->name }}</div>
        <a href="{{ route('dashboard') }}">Dashboard</a>
      @else
        <a href="{{ route('login') }}">Login</a>
        <a href="{{ route('register') }}">Start Free</a>
      @endauth
    </div>
  </header>

  <main class="container">
    <section class="hero reveal">
      <!-- <span class="eyebrow">Enterprise-ready plans</span> -->
      <h1>Pricing built for real production workloads.</h1>
      <p class="muted hero-copy">From early validation to high-volume deployment, every plan includes strict entitlement enforcement, predictable usage economics, and bilingual voice coverage.</p>
      <div class="toggle-wrap">
        <div class="toggle">
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
            <p class="muted">{{ $plan['slug'] === 'free' ? 'Ideal for evaluation and internal testing' : 'Built for client-facing delivery and scale' }}</p>
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
              <dd>{{ $plan['allow_overage'] ? ('$'.number_format(((int) ($plan['overage_price_per_million_cents'] ?? 0)) / 100, 2).' / 1,000,000 chars') : 'No overage' }}</dd>
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
                <a class="btn {{ $plan['is_popular'] ? 'primary' : '' }}" href="{{ route('dashboard') }}">Use Free Plan</a>
              @else
                <a class="btn {{ $plan['is_popular'] ? 'primary' : '' }}" href="{{ route('register') }}">Create Free Account</a>
              @endauth
            </div>
          @endif
        </article>
      @empty
        <article class="plan reveal">
          <h2 style="margin:.1rem 0 .2rem;font-size:1.15rem">No plans found</h2>
          <p class="muted">Please seed plans in the database.</p>
        </article>
      @endforelse
    </section>

    <section class="faq reveal">
      <article class="card">
        <h3>Can I change plan any time?</h3>
        <p class="muted">Yes. Upgrade instantly, downgrade at next billing cycle.</p>
      </article>
      <article class="card">
        <h3>What happens if I hit limits?</h3>
        <p class="muted">You can upgrade immediately or wait for monthly reset.</p>
      </article>
      <article class="card">
        <h3>Do you support both Arabic and English?</h3>
        <p class="muted">Yes - all paid plans include both language families.</p>
      </article>
      <article class="card">
        <h3>Can I start with a free account?</h3>
        <p class="muted">Absolutely. You can register free and move up when usage grows.</p>
      </article>
    </section>

    <section class="cta reveal">
      <h2 style="margin:.1rem 0 .45rem">Ready to grow fast?</h2>
      <p class="muted" style="margin:0 0 .8rem">Start free, then move up as your usage scales.</p>
      @auth
        <a class="btn primary" href="{{ route('dashboard') }}">Dashboard</a>
      @else
        <a class="btn primary" href="{{ route('register') }}">Create Your Account</a>
      @endauth
    </section>
  </main>

  <footer class="foot">
    <div class="container">&copy; {{ date('Y') }} Listen. Crafted by SoliCode Ltd.</div>
  </footer>

  <script>
    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) entry.target.classList.add('show');
      }
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));

    const setTheme = () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    };
    document.getElementById('themeToggleDesktop')?.addEventListener('click', setTheme);
    document.getElementById('themeToggleMobile')?.addEventListener('click', setTheme);

    const menuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    menuBtn?.addEventListener('click', () => mobileMenu?.classList.toggle('open'));

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
        if (!priceEl) return; // free plan

        const value = isYearly ? plan.dataset.year : plan.dataset.month;
        priceEl.textContent = formatPrice(value);

        const sub = plan.querySelector('.price-sub');
        if (sub) {
          sub.textContent = isYearly ? 'per month (annual billing)' : 'per month';
        }
      });
    }

    monthlyBtn?.addEventListener('click', () => setBilling('monthly'));
    yearlyBtn?.addEventListener('click', () => setBilling('yearly'));
  </script>
</body>
</html>


