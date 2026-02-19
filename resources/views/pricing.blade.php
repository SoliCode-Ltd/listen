<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Pricing</title>
  <meta name="description" content="Simple, scalable pricing for Listen voice SaaS." />
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
    .brand{display:flex;align-items:center;gap:.55rem;font-weight:900}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}

    .desktop-nav,.desktop-actions{display:flex;align-items:center;gap:.55rem}
    .desktop-nav a,.mobile-menu a{text-decoration:none;color:var(--muted);font-weight:600;padding:.5rem .62rem;border-radius:.6rem}
    .desktop-nav a:hover,.mobile-menu a:hover{color:var(--text);background:color-mix(in oklab,var(--surface) 85%,transparent)}

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.7rem .92rem;border-radius:.72rem;border:1px solid var(--line);background:var(--surface);color:var(--text);font-weight:700;text-decoration:none;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
    .btn:hover{transform:translateY(-2px);box-shadow:0 10px 26px color-mix(in oklab,var(--b) 22%,transparent)}
    .btn.primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

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

    .hero{padding:3.3rem 0 1.4rem;text-align:center}
    h1{margin:.6rem 0 .7rem;font-size:clamp(2rem,5.5vw,4rem);line-height:1.04;letter-spacing:-.03em}
    .muted{color:var(--muted)}

    .toggle-wrap{display:flex;justify-content:center;margin:1rem 0 1.3rem}
    .toggle{display:inline-flex;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:999px;padding:.28rem}
    .toggle button{border:0;background:transparent;color:var(--muted);font-weight:700;padding:.42rem .78rem;border-radius:999px;cursor:pointer}
    .toggle button.active{background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

    .plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:.8rem}
    .plan{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:transform .25s ease,border-color .25s ease}
    .plan:hover{transform:translateY(-4px);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .plan.popular{border-color:color-mix(in oklab,var(--b) 58%,var(--line) 42%)}
    .badge{position:absolute;top:.8rem;right:.8rem;font-size:.72rem;border:1px solid color-mix(in oklab,var(--a) 55%,var(--line) 45%);background:color-mix(in oklab,var(--a) 16%,var(--surface) 84%);color:color-mix(in oklab,var(--a) 82%,var(--text) 18%);padding:.2rem .52rem;border-radius:999px}
    .price{font-size:2.15rem;font-weight:900;margin:.28rem 0 .15rem}
    .plan ul{margin:.7rem 0 0 1rem;padding:0;display:grid;gap:.35rem;color:var(--muted)}

    .faq{margin-top:1.7rem;display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
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
      <div class="brand">🎧 <span>Listen</span></div>

      <nav class="desktop-nav">
        <a href="{{ route('home') }}">Home</a>
        <a href="{{ route('demo.page') }}">Demo</a>
        <a href="{{ route('pricing') }}">Pricing</a>
      </nav>

      <div class="desktop-actions">
        <button type="button" class="btn" id="themeToggleDesktop" aria-label="Toggle theme">🌓</button>
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
        <button type="button" class="btn" id="themeToggleMobile" aria-label="Toggle theme">🌓</button>
        <button type="button" class="btn" id="mobileMenuBtn" aria-label="Menu">☰</button>
      </div>
    </div>
    <div class="container mobile-menu" id="mobileMenu">
      <a href="{{ route('home') }}">Home</a>
      <a href="{{ route('demo.page') }}">Demo</a>
      <a href="{{ route('pricing') }}">Pricing</a>
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
      <h1>Pricing powered by your live plans table.</h1>
      <p class="muted">No hardcoded cards. Any backend plan update appears here automatically.</p>
      <div class="toggle-wrap">
        <div class="toggle">
          <button id="monthlyBtn" class="active" type="button">Monthly</button>
          <button id="yearlyBtn" type="button">Yearly · save 20%</button>
        </div>
      </div>
    </section>

    <section class="plans">
      @forelse($pricingPlans as $plan)
        <article class="plan reveal {{ $plan['is_popular'] ? 'popular' : '' }}" data-month="{{ number_format($plan['price_monthly'], 2, '.', '') }}" data-year="{{ number_format($plan['price_yearly'], 2, '.', '') }}">
          @if($plan['is_popular'])
            <span class="badge">Most Popular</span>
          @endif
          <h2 style="margin:.1rem 0 .2rem;font-size:1.15rem">{{ $plan['name'] }}</h2>
          <p class="muted">{{ $plan['slug'] === 'free' ? 'Perfect to get started' : 'Built for growing teams' }}</p>
          <div class="price">
            @if($plan['price_monthly'] <= 0)
              Free
            @else
              $<span class="price-value">{{ rtrim(rtrim(number_format($plan['price_monthly'], 2, '.', ''), '0'), '.') }}</span><span style="font-size:1rem">/mo</span>
            @endif
          </div>
          <ul>
            <li>{{ number_format($plan['monthly_minutes']) }} minutes / month</li>
            <li>{{ number_format($plan['max_chars_per_request']) }} chars per request</li>
            @foreach(array_slice($plan['features'], 0, 3) as $feature)
              <li>{{ $feature }}</li>
            @endforeach
          </ul>
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
        <p class="muted">Yes — all paid plans include both language families.</p>
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
        <a class="btn primary" href="{{ route('dashboard') }}">Open Dashboard</a>
      @else
        <a class="btn primary" href="{{ route('register') }}">Create Your Account</a>
      @endauth
    </section>
  </main>

  <footer class="foot">
    <div class="container">© {{ date('Y') }} Listen · Built by SoliCode.</div>
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
      });
    }

    monthlyBtn?.addEventListener('click', () => setBilling('monthly'));
    yearlyBtn?.addEventListener('click', () => setBilling('yearly'));
  </script>
</body>
</html>
