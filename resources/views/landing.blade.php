<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen — Premium Voice SaaS</title>
  <meta name="description" content="Listen transforms text into premium Arabic and English speech with subscription-ready architecture and polished user experience." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#06080f;--surface:#11192b;--surface-2:#17223a;--line:#2a395c;--text:#f5f7ff;--muted:#a8b7da;--a:#fb8308;--b:#5906b2;--shadow:0 22px 50px rgba(0,0,0,.35)}
    html[data-theme="light"]{--bg:#eef2ff;--surface:#fff;--surface-2:#f6f9ff;--line:#d9e2f1;--text:#111a2c;--muted:#586988;--shadow:0 14px 34px rgba(31,53,97,.15)}
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(1000px 540px at 92% -16%,color-mix(in oklab,var(--b) 28%,transparent),transparent 64%),radial-gradient(900px 380px at 4% -12%,color-mix(in oklab,var(--a) 22%,transparent),transparent 58%),var(--bg)}
    .container{width:min(1200px,92%);margin:0 auto}
    .header{position:sticky;top:0;z-index:50;background:color-mix(in oklab,var(--bg) 84%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
    .nav{min-height:78px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900;display:flex;align-items:center;gap:.55rem}.brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;padding:.72rem .95rem;border-radius:.76rem;font-weight:700;text-decoration:none;border:1px solid var(--line);background:var(--surface);color:var(--text);cursor:pointer}
    .btn-primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

    .hero{padding:5rem 0 3.2rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:center}
    .eyebrow{display:inline-block;padding:.3rem .65rem;border-radius:999px;border:1px solid var(--line);font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
    h1{margin:.8rem 0 .8rem;font-size:clamp(2.2rem,5.8vw,4.6rem);line-height:1.03;letter-spacing:-.03em}
    p{margin:0;color:var(--muted)}
    .cta{display:flex;gap:.62rem;flex-wrap:wrap;margin-top:1.2rem}

    .panel{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow)}
    .grid{display:grid;gap:.62rem;grid-template-columns:repeat(2,minmax(0,1fr));margin-top:.8rem}
    .box{border:1px solid var(--line);border-radius:.78rem;background:var(--surface);padding:.65rem}
    .box b{display:block;font-size:1.08rem}

    .section{padding:4rem 0}
    .section h2{margin:0 0 .45rem;font-size:clamp(1.6rem,3.2vw,2.6rem)}
    .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem;margin-top:1rem}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow)}
    .card h3{margin:.15rem 0 .45rem}
    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}

    @media (max-width:1000px){.hero-grid,.cards,.grid{grid-template-columns:1fr}}
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
  <header class="header">
    <div class="container nav">
      <div class="brand">🎧 <span>Listen</span></div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn" id="themeToggle">Theme</button>
        <a class="btn" href="{{ route('demo.page') }}">Demo Lab</a>
        @auth
          <a class="btn btn-primary" href="{{ route('dashboard') }}">Dashboard</a>
        @else
          <a class="btn btn-primary" href="{{ route('login') }}">Get Started</a>
        @endauth
      </div>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <div>
          <span class="eyebrow">Premium text-to-speech SaaS</span>
          <h1>World-class voice output for Arabic and English.</h1>
          <p>Listen is built for scale: subscription control, usage governance, queue reliability, and curated WaveNet voices for male and female playback.</p>
          <div class="cta">
            <a class="btn btn-primary" href="{{ route('demo.page') }}">Try the Voice Demo</a>
            @auth
              <a class="btn" href="{{ route('dashboard') }}">Manage Jobs</a>
            @else
              <a class="btn" href="{{ route('register') }}">Create Account</a>
            @endauth
          </div>
        </div>

        <aside class="panel">
          <h3 style="margin:.1rem 0 .35rem">Why teams choose Listen</h3>
          <p>Elegant frontend + production backend for shipping fast and scaling confidently.</p>
          <div class="grid">
            <div class="box"><small>Voices</small><b>Male + Female</b></div>
            <div class="box"><small>Languages</small><b>Arabic + English</b></div>
            <div class="box"><small>Engine</small><b>Google WaveNet</b></div>
            <div class="box"><small>Architecture</small><b>Queue + SaaS limits</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="section">
      <h2>Ready for serious SaaS growth</h2>
      <p>Designed to look premium while handling real production workloads.</p>
      <div class="cards">
        <article class="card"><h3>Voice Governance</h3><p>Plan limits by minutes and characters with clean monthly usage tracking.</p></article>
        <article class="card"><h3>Reliable Processing</h3><p>Background queue ensures predictable delivery and cleaner UX at scale.</p></article>
        <article class="card"><h3>Demo Experience</h3><p>A dedicated demo lab page for voice selection, A/B listening, and quality checks.</p></article>
      </div>
    </section>
  </main>

  <footer class="foot">
    <div class="container">© {{ date('Y') }} Listen — built by SoliCode.</div>
  </footer>

  <script>
    document.getElementById('themeToggle')?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });
  </script>
</body>
</html>
