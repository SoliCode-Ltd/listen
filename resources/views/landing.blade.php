<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen — Premium Voice SaaS</title>
  <meta name="description" content="Launch your voice SaaS with premium Arabic and English playback, polished UX, and production-ready architecture." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#11182a;--surface2:#15213a;--line:#2b3a5d;--text:#f4f7ff;--muted:#9fb0d4;--a:#fb8308;--b:#5906b2;--shadow:0 16px 46px rgba(0,0,0,.30)}
    html[data-theme="light"]{--bg:#eff3ff;--surface:#fff;--surface2:#f7f9ff;--line:#d8e2f1;--text:#121a2d;--muted:#5b6c89;--shadow:0 14px 34px rgba(35,56,97,.13)}
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;background:radial-gradient(1200px 500px at 92% -20%,color-mix(in oklab,var(--b) 25%,transparent),transparent 60%),radial-gradient(900px 420px at -5% 0%,color-mix(in oklab,var(--a) 18%,transparent),transparent 58%),var(--bg);color:var(--text)}
    .container{width:min(1200px,92%);margin:auto}
    .bg-orb{position:fixed;z-index:-1;pointer-events:none;border-radius:999px;filter:blur(36px);opacity:.25;animation:float 10s ease-in-out infinite}
    .bg-orb.a{width:16rem;height:16rem;background:color-mix(in oklab,var(--a) 74%,white 26%);left:-4rem;top:15vh}
    .bg-orb.b{width:20rem;height:20rem;background:color-mix(in oklab,var(--b) 75%,white 25%);right:-4rem;bottom:8vh;animation-delay:-3s}

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

    .mobile-controls{display:none;align-items:center;gap:.45rem}
    .mobile-menu{display:none;padding:0 0 1rem}
    .mobile-menu.open{display:grid;gap:.35rem}

    .hero{padding:4.2rem 0 2.6rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:center}
    .eyebrow{display:inline-block;padding:.3rem .62rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
    h1{margin:.7rem 0 .8rem;font-size:clamp(2.2rem,6vw,4.8rem);line-height:1.02;letter-spacing:-.03em}
    .lead{margin:0;color:var(--muted);max-width:58ch}
    .hero-cta{display:flex;gap:.58rem;flex-wrap:wrap;margin-top:1.2rem}

    .panel{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);transition:transform .25s ease,border-color .25s ease}
    .panel:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--b) 45%,var(--line) 55%)}
    .stat-grid{margin-top:.75rem;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.58rem}
    .stat{background:var(--surface);border:1px solid var(--line);border-radius:.74rem;padding:.62rem}
    .stat b{display:block;font-size:1.06rem}

    .section{padding:3.2rem 0}
    .section h2{margin:0 0 .45rem;font-size:clamp(1.5rem,3.3vw,2.55rem)}
    .muted{color:var(--muted)}

    .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .card{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);transition:transform .22s ease,border-color .22s ease}
    .card:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .card h3{margin:.2rem 0 .48rem}

    .pricing-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .plan{border:1px solid var(--line);background:var(--surface);border-radius:1rem;padding:1rem}
    .plan h4{margin:.1rem 0 .35rem;font-size:1.05rem}
    .price{font-size:1.9rem;font-weight:900;margin:.2rem 0 .4rem}

    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}

    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
    @media (max-width:960px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .hero-grid,.cards,.pricing-strip,.stat-grid{grid-template-columns:1fr}
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
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

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
        <a href="{{ route('dashboard') }}">Dashboard</a>
      @else
        <a href="{{ route('login') }}">Login</a>
        <a href="{{ route('register') }}">Start Free</a>
      @endauth
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <div class="reveal">
          <span class="eyebrow">Voice SaaS designed for growth</span>
          <h1>Build voice experiences users actually love.</h1>
          <p class="lead">Listen helps you launch and scale a modern voice product with polished UX, premium voice presets, bilingual support, and production-ready architecture.</p>
          <div class="hero-cta">
            <a class="btn primary" href="{{ route('demo.page') }}">Try Demo</a>
            <a class="btn" href="{{ route('pricing') }}">See Pricing</a>
          </div>
        </div>

        <aside class="panel reveal">
          <h3 style="margin:.1rem 0 .4rem">Why this feels premium</h3>
          <p class="muted">Great first impression, reliable backend, and clear subscription model.</p>
          <div class="stat-grid">
            <div class="stat"><small class="muted">Voice modes</small><b>Male + Female</b></div>
            <div class="stat"><small class="muted">Languages</small><b>Arabic + English</b></div>
            <div class="stat"><small class="muted">Engine layer</small><b>Soli Voice Stack</b></div>
            <div class="stat"><small class="muted">Scale model</small><b>Queue + Plans</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="section reveal">
      <h2>Everything needed to launch fast</h2>
      <p class="muted">Built for founders who want product quality and speed.</p>
      <div class="cards">
        <article class="card reveal">
          <h3>Product-ready UI</h3>
          <p class="muted">Premium layouts, micro animations, theme toggle, and clean conversion flow.</p>
        </article>
        <article class="card reveal">
          <h3>Reliable generation</h3>
          <p class="muted">Queue-backed processing with usage tracking and subscription boundaries.</p>
        </article>
        <article class="card reveal">
          <h3>Founder-friendly controls</h3>
          <p class="muted">Voice selection, translation behavior, and plan strategy out of the box.</p>
        </article>
      </div>
    </section>

    <section class="section reveal">
      <h2>Starter pricing that scales</h2>
      <p class="muted">Clear, simple, and ready for your first wave of users.</p>
      <div class="pricing-strip">
        <article class="plan reveal">
          <h4>Starter</h4>
          <div class="price">$9<span style="font-size:.9rem">/mo</span></div>
          <p class="muted">120 minutes · 2,500 chars/request</p>
        </article>
        <article class="plan reveal">
          <h4>Growth</h4>
          <div class="price">$29<span style="font-size:.9rem">/mo</span></div>
          <p class="muted">500 minutes · 6,000 chars/request</p>
        </article>
        <article class="plan reveal">
          <h4>Scale</h4>
          <div class="price">$79<span style="font-size:.9rem">/mo</span></div>
          <p class="muted">1,800 minutes · 12,000 chars/request</p>
        </article>
      </div>
      <div style="margin-top:.85rem">
        <a class="btn primary" href="{{ route('pricing') }}">Open Full Pricing Page</a>
      </div>
    </section>
  </main>

  <footer class="foot">
    <div class="container">© {{ date('Y') }} Listen · Crafted by SoliCode.</div>
  </footer>

  <script>
    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) entry.target.classList.add('show');
      }
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));

    const toggleTheme = () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    };

    document.getElementById('themeToggleDesktop')?.addEventListener('click', toggleTheme);
    document.getElementById('themeToggleMobile')?.addEventListener('click', toggleTheme);

    const menuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    menuBtn?.addEventListener('click', () => {
      mobileMenu?.classList.toggle('open');
    });
  </script>
</body>
</html>
