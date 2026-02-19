<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen — SaaS Voice Platform</title>
  <meta name="description" content="Listen turns text into high-quality Arabic and English speech with subscriptions, usage controls, and production-ready reliability." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#070911;--surface:#121a2b;--surface-2:#161f34;--line:#2b3959;--text:#f4f7ff;--muted:#a9b6d7;
      --a:#ff8a00;--b:#6f19d8;--ok:#36c98f;--shadow:0 16px 40px rgba(0,0,0,.28);
    }
    html[data-theme="light"]{
      --bg:#f3f6ff;--surface:#ffffff;--surface-2:#f7f9ff;--line:#dce2f0;--text:#101626;--muted:#53607a;
      --a:#f78a10;--b:#6119c0;--ok:#198f65;--shadow:0 12px 30px rgba(33,51,91,.12);
    }
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(1000px 500px at 90% -15%,color-mix(in oklab,var(--b) 25%,transparent),transparent 60%),var(--bg);line-height:1.62}
    .container{width:min(1180px,92%);margin:0 auto}
    .header{position:sticky;top:0;z-index:50;background:color-mix(in oklab,var(--bg) 82%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
    .nav{min-height:78px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900;letter-spacing:.02em;display:flex;align-items:center;gap:.55rem}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}
    .links{display:flex;align-items:center;gap:1rem}
    .links a{color:var(--muted);text-decoration:none;font-weight:600}
    .links a:hover{color:var(--text)}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;text-decoration:none;border-radius:.84rem;padding:.84rem 1.08rem;font-weight:700;border:1px solid transparent;cursor:pointer}
    .btn-primary{background:linear-gradient(90deg,var(--a),var(--b));color:#fff}
    .btn-ghost{border-color:var(--line);color:var(--text);background:var(--surface)}
    .btn-theme{padding:.58rem .8rem;border-radius:.7rem;background:var(--surface);border:1px solid var(--line);color:var(--text);font-weight:700}

    .hero{padding:5.3rem 0 3.6rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1.1rem;align-items:center}
    .eyebrow{display:inline-block;font-size:.76rem;letter-spacing:.14em;text-transform:uppercase;padding:.35rem .68rem;border:1px solid var(--line);border-radius:999px;color:color-mix(in oklab,var(--muted) 75%,var(--text) 25%)}
    h1{margin:.75rem 0 .9rem;font-size:clamp(2.1rem,6vw,4.5rem);line-height:1.04;letter-spacing:-.02em}
    .lead{color:var(--muted);max-width:64ch}
    .cta{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.35rem}

    .showcase{border:1px solid var(--line);border-radius:1rem;background:linear-gradient(180deg,var(--surface-2),var(--surface));padding:1rem;box-shadow:var(--shadow)}
    .showcase-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.7rem}
    .pill{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem;color:var(--muted);background:var(--surface)}
    .wave{height:56px;display:flex;align-items:flex-end;gap:4px;padding:.35rem 0 .6rem}
    .wave i{display:block;width:5px;background:linear-gradient(180deg,var(--a),var(--b));border-radius:999px;animation:wave 1.12s infinite ease-in-out}
    .wave i:nth-child(2){animation-delay:.08s}.wave i:nth-child(3){animation-delay:.16s}.wave i:nth-child(4){animation-delay:.24s}.wave i:nth-child(5){animation-delay:.32s}.wave i:nth-child(6){animation-delay:.4s}.wave i:nth-child(7){animation-delay:.48s}.wave i:nth-child(8){animation-delay:.56s}.wave i:nth-child(9){animation-delay:.64s}.wave i:nth-child(10){animation-delay:.72s}
    @keyframes wave{0%,100%{height:12px}50%{height:52px}}
    .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}
    .stat{background:var(--surface);border:1px solid var(--line);border-radius:.78rem;padding:.7rem}
    .stat b{display:block;font-size:1.13rem}

    .section{padding:4.5rem 0}
    .section h2{margin:0 0 .5rem;font-size:clamp(1.55rem,3.2vw,2.6rem)}
    .muted{color:var(--muted)}
    .cards{display:grid;gap:.88rem}
    .four{grid-template-columns:repeat(4,minmax(0,1fr))}
    .three{grid-template-columns:repeat(3,minmax(0,1fr))}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow)}
    .card h3{margin:.15rem 0 .45rem}.card p{margin:0;color:var(--muted)}
    .feature-icon{font-size:1.08rem;display:inline-grid;place-items:center;width:34px;height:34px;border-radius:.65rem;background:var(--surface);border:1px solid var(--line);margin-bottom:.55rem}

    .steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .step{background:var(--surface);border:1px solid var(--line);border-radius:.9rem;padding:.9rem;box-shadow:var(--shadow)}
    .step b{display:inline-flex;width:30px;height:30px;border-radius:50%;align-items:center;justify-content:center;background:linear-gradient(90deg,var(--a),var(--b));font-size:.82rem;color:#fff}
    .step h4{margin:.65rem 0 .4rem}.step p{margin:0;color:var(--muted)}

    .pricing .price{font-size:1.9rem;font-weight:900;margin:.4rem 0}
    .pricing .tag{display:inline-block;padding:.22rem .5rem;border-radius:999px;border:1px solid var(--line);color:var(--muted);font-size:.75rem}
    .pricing .best{border-color:color-mix(in oklab,var(--b) 60%,var(--line) 40%)}

    .demo-layout{display:grid;grid-template-columns:1fr .95fr;gap:.95rem;margin-top:1rem}
    .form-grid{display:grid;gap:.72rem}.two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{font-size:.9rem;color:color-mix(in oklab,var(--text) 85%,var(--muted) 15%)}
    input,select,textarea{width:100%;border-radius:.72rem;border:1px solid var(--line);background:var(--surface);color:var(--text);padding:.72rem}
    .result{white-space:pre-wrap;background:var(--surface);border:1px solid var(--line);border-radius:.8rem;padding:.8rem;min-height:250px;color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.86rem}
    .preview-wrap{margin-top:.82rem;padding:.75rem;border:1px solid var(--line);border-radius:.8rem;background:var(--surface)}
    .preview-wrap h4{margin:.1rem 0 .5rem;font-size:.93rem}.preview-wrap audio{width:100%;max-width:420px;height:34px}
    .preview-note{margin-top:.45rem;color:var(--muted);font-size:.85rem}

    .footer{border-top:1px solid var(--line);padding:1.2rem 0 2.2rem;color:var(--muted)}
    .reveal{opacity:0;transform:translateY(14px);transition:.55s ease}.reveal.show{opacity:1;transform:translateY(0)}

    @media (max-width:1040px){.hero-grid,.demo-layout,.steps,.four,.three,.two,.stats{grid-template-columns:1fr}.links a{display:none}}
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
      <nav class="links">
        <a href="#features">Features</a>
        <a href="#flow">Flow</a>
        <a href="#pricing">Pricing</a>
        <a href="#demo">Demo</a>
      </nav>
      <div style="display:flex;gap:.5rem;align-items:center">
        <button type="button" class="btn-theme" id="themeToggle">Theme</button>
        @auth
          <a class="btn btn-primary" href="{{ route('dashboard') }}">Dashboard</a>
        @else
          <a class="btn btn-primary" href="{{ route('login') }}">Get Started</a>
        @endauth
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container hero-grid">
        <div class="reveal">
          <span class="eyebrow">SaaS-ready voice platform</span>
          <h1>Convert text into premium speech at scale.</h1>
          <p class="lead">Listen is built for production: subscriptions, usage controls, reliable queued generation, and bilingual Arabic/English output with polished user experience.</p>
          <div class="cta">
            <a class="btn btn-primary" href="#demo">Try demo</a>
            @auth
              <a class="btn btn-ghost" href="{{ route('dashboard') }}">Open dashboard</a>
            @else
              <a class="btn btn-ghost" href="{{ route('register') }}">Create account</a>
            @endauth
          </div>
        </div>

        <aside class="showcase reveal">
          <div class="showcase-head">
            <h3 style="margin:0">Voice quality engine</h3>
            <span class="pill">● operational</span>
          </div>
          <div class="wave" aria-hidden="true">
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
          </div>
          <div class="stats">
            <div class="stat"><b>Arabic + English</b><small class="muted">Bilingual playback</small></div>
            <div class="stat"><b>Queue-backed</b><small class="muted">Reliable async generation</small></div>
            <div class="stat"><b>Plan limits</b><small class="muted">Usage-ready billing model</small></div>
          </div>
        </aside>
      </div>
    </section>

    <section id="features" class="section container">
      <div class="reveal">
        <h2>Built for SaaS from day one</h2>
        <p class="muted">Everything needed to grow from first customers to serious volume.</p>
      </div>
      <div class="cards four" style="margin-top:1rem">
        <article class="card reveal"><div class="feature-icon">🎙️</div><h3>Premium speech output</h3><p>Natural sounding synthesis for customer-facing listening experiences.</p></article>
        <article class="card reveal"><div class="feature-icon">🈯</div><h3>Translation-first listening</h3><p>Generate speech in user language with optional translation before synthesis.</p></article>
        <article class="card reveal"><div class="feature-icon">📦</div><h3>Reliable queue processing</h3><p>Background jobs with status tracking for stable and scalable audio delivery.</p></article>
        <article class="card reveal"><div class="feature-icon">💳</div><h3>Subscription-ready controls</h3><p>Monthly usage limits, plan enforcement, and smooth upgrade path.</p></article>
      </div>
    </section>

    <section id="flow" class="section container">
      <div class="reveal">
        <h2>How Listen works</h2>
        <p class="muted">Clean and predictable flow for product teams and enterprise buyers.</p>
      </div>
      <div class="steps">
        <article class="step reveal"><b>01</b><h4>Capture</h4><p>Input text from messages, files, and business workflows.</p></article>
        <article class="step reveal"><b>02</b><h4>Transform</h4><p>Detect language and optionally translate to the listener's target locale.</p></article>
        <article class="step reveal"><b>03</b><h4>Synthesize</h4><p>Queue voice generation and produce high-quality audio asynchronously.</p></article>
        <article class="step reveal"><b>04</b><h4>Deliver</h4><p>Return playable output and job status to your users instantly.</p></article>
      </div>
    </section>

    <section id="pricing" class="section container pricing">
      <div class="reveal">
        <h2>Simple plans for growth</h2>
        <p class="muted">Start small, scale smoothly, and keep your unit economics healthy.</p>
      </div>
      <div class="cards three" style="margin-top:1rem">
        <article class="card reveal"><span class="tag">Starter</span><h3>Free</h3><div class="price">$0</div><p>10 minutes/month · core playback</p></article>
        <article class="card best reveal"><span class="tag">Most Popular</span><h3>Pro</h3><div class="price">$12</div><p>250 minutes/month · translation + priority handling</p></article>
        <article class="card reveal"><span class="tag">Scale</span><h3>Business</h3><div class="price">$39</div><p>1,000 minutes/month · high-volume operations</p></article>
      </div>
    </section>

    <section id="demo" class="section container">
      <div class="reveal">
        <h2>Interactive product demo</h2>
        <p class="muted">Try the full text-to-voice flow directly from the landing page.</p>
      </div>
      <div class="demo-layout">
        <section class="card reveal">
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">Text input</label>
              <textarea id="text" name="text" rows="8" placeholder="Paste your content here..."></textarea>
            </div>
            <div class="two">
              <div>
                <label for="target_language">Target language</label>
                <select id="target_language" name="target_language">
                  <option value="ar">Arabic</option>
                  <option value="en">English</option>
                </select>
              </div>
              <div>
                <label for="translate">Translate first</label>
                <select id="translate" name="translate">
                  <option value="1">Yes</option>
                  <option value="0">No</option>
                </select>
              </div>
            </div>
            <div style="display:flex;gap:.65rem;flex-wrap:wrap">
              <button class="btn btn-primary" type="submit">Run check</button>
              <button class="btn btn-ghost" type="button" id="previewBtn">Generate voice</button>
            </div>

            <div class="preview-wrap" id="previewWrap" style="display:none">
              <h4>Voice preview</h4>
              <audio id="previewAudio" controls preload="none"></audio>
              <div class="preview-note" id="previewMeta"></div>
            </div>
          </form>
        </section>

        <section class="card reveal">
          <h3 style="margin-top:0">Result</h3>
          <div id="result" class="result">Waiting...</div>
        </section>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">© {{ date('Y') }} Listen — production-ready voice SaaS.</div>
  </footer>

  <script>
    const form = document.getElementById('demoForm');
    const result = document.getElementById('result');
    const previewBtn = document.getElementById('previewBtn');
    const previewWrap = document.getElementById('previewWrap');
    const previewAudio = document.getElementById('previewAudio');
    const previewMeta = document.getElementById('previewMeta');
    const themeToggle = document.getElementById('themeToggle');

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
    };

    themeToggle?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });

    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      result.textContent = 'Processing...';

      try {
        const fd = new FormData(form);
        const res = await fetch('{{ route('demo') }}', {
          method: 'POST',
          headers: csrfHeaders,
          body: fd,
        });

        const data = await res.json();
        result.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        result.textContent = 'Error: ' + (err?.message || 'Unknown error');
      }
    });

    previewBtn?.addEventListener('click', async () => {
      if (!form) return;

      const fd = new FormData(form);
      previewWrap.style.display = 'block';
      previewMeta.textContent = 'Generating voice...';
      previewAudio.removeAttribute('src');

      try {
        const res = await fetch('{{ route('preview') }}', {
          method: 'POST',
          headers: csrfHeaders,
          body: fd,
        });

        const data = await res.json();
        if (!data?.ok || !data?.audio_url) {
          throw new Error(data?.message || 'Preview generation failed');
        }

        previewAudio.src = data.audio_url;
        previewAudio.load();
        previewMeta.textContent = `Provider: ${data.provider || 'google-cloud-tts'} · Duration: ${data.duration_seconds || '?'}s`;
      } catch (err) {
        previewMeta.textContent = 'Error: ' + (err?.message || 'Unknown error');
      }
    });

    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) entry.target.classList.add('show');
      }
    }, { threshold: 0.12 });

    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
  </script>
</body>
</html>
