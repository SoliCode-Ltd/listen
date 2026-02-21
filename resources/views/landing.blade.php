<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen</title>
  <meta name="description" content="Launch your voice SaaS with premium Arabic and English playback, polished UX, and production-ready architecture." />
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
    .bg-orb{position:fixed;z-index:-1;pointer-events:none;border-radius:999px;filter:blur(36px);opacity:.25;animation:float 10s ease-in-out infinite}
    .bg-orb.a{width:16rem;height:16rem;background:color-mix(in oklab,var(--a) 74%,white 26%);left:-4rem;top:15vh}
    .bg-orb.b{width:20rem;height:20rem;background:color-mix(in oklab,var(--b) 75%,white 25%);right:-4rem;bottom:8vh;animation-delay:-3s}

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

    .hero{padding:4.2rem 0 2.6rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:center}
    .eyebrow{display:inline-block;padding:.3rem .62rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
    h1{margin:.7rem 0 .8rem;font-size:clamp(2.2rem,6vw,4.8rem);line-height:1.02;letter-spacing:-.03em}
    .lead{margin:0;color:var(--muted);max-width:58ch}
    .hero-cta{display:flex;gap:.58rem;flex-wrap:wrap;margin-top:1.2rem}

    .hero-art{position:relative;display:grid;place-items:center}
    .hero-art::before{
      content:"";
      position:absolute;
      width:min(420px,82%);
      aspect-ratio:1/1;
      border-radius:999px;
      background:radial-gradient(circle,color-mix(in oklab,var(--a) 30%,transparent),transparent 68%);
      filter:blur(18px);
      opacity:.45;
      animation:pulseGlow 6s ease-in-out infinite;
      z-index:0;
    }
    .hero-art img{
      width:min(520px,100%);
      height:auto;
      filter:drop-shadow(0 16px 28px rgba(0,0,0,.24));
      animation:float 7s ease-in-out infinite;
      position:relative;
      z-index:1;
    }

    .section{padding:3.2rem 0}
    .section h2{margin:0 0 .45rem;font-size:clamp(1.5rem,3.3vw,2.55rem)}
    .muted{color:var(--muted)}

    .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .card{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);transition:transform .22s ease,border-color .22s ease}
    .card:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .card h3{margin:.2rem 0 .48rem}

    .premium-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .premium-card{
      background:linear-gradient(180deg,var(--surface2),var(--surface));
      border:1px solid var(--line);
      border-radius:1rem;
      padding:1rem;
      box-shadow:var(--shadow);
      transition:transform .22s ease,border-color .22s ease;
    }
    .premium-card:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--a) 40%,var(--line) 60%)}
    .premium-card h3{margin:.2rem 0 .4rem;font-size:1.05rem}
    .premium-metric{display:inline-block;margin-top:.5rem;padding:.3rem .56rem;border-radius:999px;border:1px solid var(--line);font-size:.78rem;color:var(--muted)}

    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}

    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
    @keyframes pulseGlow{0%,100%{transform:scale(.95);opacity:.35}50%{transform:scale(1.05);opacity:.58}}
    @media (max-width:960px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .hero-grid,.cards,.premium-grid{grid-template-columns:1fr}
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
    <section class="hero">
      <div class="hero-grid">
        <div class="reveal">
          <!-- <span class="eyebrow">Voice SaaS designed for growth</span> -->
          <h1>Build voice experiences users actually love.</h1>
          <p class="lead">Listen helps you launch and scale a modern voice product with polished UX, premium voice presets, bilingual support, and production-ready architecture.</p>
          <div class="hero-cta">
            @guest
              <a class="btn primary" href="{{ route('demo.page') }}">Try Demo</a>
              <a class="btn" href="{{ route('register') }}">Create Free Account</a>
            @else
              <a class="btn primary" href="{{ route('dashboard') }}">Dashboard</a>
              <a class="btn" href="{{ route('extension.page') }}">Chrome Extension</a>
            @endguest
          </div>
        </div>

        <aside class="hero-art reveal">
          <img src="{{ asset('icons/listen-galaxy2.png') }}" alt="Listen people collaboration illustration" />
        </aside>
      </div>
    </section>

    <section class="section reveal">
      <h2>Enterprise-Grade Delivery Surface</h2>
      <p class="muted">Purpose-built for teams that require reliability, auditability, and predictable voice operations at scale.</p>
      <div class="cards">
        <article class="card reveal">
          <h3>Operational Reliability</h3>
          <p class="muted">Deterministic processing flow, resilient job execution, and clear system feedback for production incidents.</p>
        </article>
        <article class="card reveal">
          <h3>Policy Enforcement</h3>
          <p class="muted">Usage, plan governance, and entitlement controls are enforced in backend logic, not only in UI.</p>
        </article>
        <article class="card reveal">
          <h3>Security By Default</h3>
          <p class="muted">API-key isolation, role-safe account actions, and clean data boundaries for multi-tenant growth.</p>
        </article>
      </div>
    </section>

    <section class="section reveal">
      <h2>Why This Feels Premium</h2>
      <p class="muted">A high-confidence platform experience requires more than visuals. It requires system rigor end-to-end.</p>
      <div class="premium-grid">
        <article class="premium-card reveal">
          <h3>Voice Fidelity Governance</h3>
          <p class="muted">Arabic and English voice mapping is validated against approved catalogs to prevent label-to-voice drift in production.</p>
          <span class="premium-metric">Catalog integrity</span>
        </article>
        <article class="premium-card reveal">
          <h3>Fast Path Processing</h3>
          <p class="muted">Long requests are routed for non-blocking processing and prioritized execution so user-facing flows remain responsive.</p>
          <span class="premium-metric">Queue-aware routing</span>
        </article>
        <article class="premium-card reveal">
          <h3>Commercial Plan Controls</h3>
          <p class="muted">Entitlement checks cover request limits, overage policy, commercial usage rights, and processing class per plan.</p>
          <span class="premium-metric">Policy enforcement</span>
        </article>
        <article class="premium-card reveal">
          <h3>Extension UX Discipline</h3>
          <p class="muted">Playback controls, selection handling, and status feedback are tuned for stable behavior in real browsing environments.</p>
          <span class="premium-metric">User trust</span>
        </article>
      </div>
      <div style="margin-top:.85rem">
        <a class="btn primary" href="{{ route('pricing') }}">Full Pricing</a>
      </div>
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


