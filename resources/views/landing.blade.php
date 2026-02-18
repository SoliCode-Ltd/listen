<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen — World-Class TTS Experience for Arabic & English</title>
  <meta name="description" content="Listen converts emails, messages, and files into premium voice with instant translation and smart queueing." />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#070911;
      --surface:#101726;
      --surface-2:#151f34;
      --line:#2a3858;
      --text:#f4f7ff;
      --muted:#a9b6d7;
      --a:#ff8a00;
      --b:#6f19d8;
      --ok:#38d39f;
      --danger:#ff5f86;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
      color:var(--text);
      background:
        radial-gradient(1200px 600px at 95% -18%, rgba(111,25,216,.26), transparent 60%),
        radial-gradient(950px 560px at -10% 3%, rgba(255,138,0,.20), transparent 64%),
        var(--bg);
      line-height:1.62;
    }
    .container{width:min(1180px,92%);margin:0 auto}
    .header{
      position:sticky;top:0;z-index:60;
      backdrop-filter:blur(10px);
      background:rgba(7,9,17,.72);
      border-bottom:1px solid var(--line);
    }
    .nav{min-height:78px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900;letter-spacing:.02em;display:flex;align-items:center;gap:.55rem}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}
    .links a{color:var(--muted);text-decoration:none;margin-left:1.05rem;font-weight:500}
    .links a:hover{color:var(--text)}

    .btn{display:inline-block;text-decoration:none;border-radius:.8rem;padding:.84rem 1.1rem;font-weight:700;border:1px solid transparent;cursor:pointer}
    .btn-primary{background:linear-gradient(90deg,var(--a),var(--b));color:#fff}
    .btn-ghost{border-color:var(--line);color:var(--text)}

    .hero{padding:6rem 0 4rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1.2rem;align-items:center}
    .eyebrow{display:inline-block;font-size:.76rem;letter-spacing:.13em;text-transform:uppercase;padding:.35rem .68rem;border:1px solid var(--line);border-radius:999px;color:#dce4ff}
    h1{margin:.75rem 0 .9rem;font-size:clamp(2.1rem,6.2vw,4.4rem);line-height:1.03;letter-spacing:-.02em}
    .lead{color:var(--muted);max-width:62ch}
    .cta{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.35rem}

    .showcase{
      border:1px solid #313b55;
      border-radius:1.1rem;
      background:linear-gradient(180deg, rgba(255,138,0,.08), rgba(111,25,216,.13));
      padding:1rem;
      position:relative;
      overflow:hidden;
    }
    .showcase::after{
      content:"";
      position:absolute;
      width:220px;height:220px;border-radius:50%;
      right:-80px;top:-80px;
      background:radial-gradient(circle,rgba(255,255,255,.23),transparent 70%);
      pointer-events:none;
    }
    .showcase-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem}
    .pill{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .55rem;border-radius:999px;border:1px solid #3f4968;color:#dbe5ff;font-size:.75rem;background:rgba(7,9,17,.5)}
    .wave{
      height:56px;display:flex;align-items:flex-end;gap:4px;padding:.3rem 0 .55rem
    }
    .wave i{
      display:block;width:5px;background:linear-gradient(180deg,var(--a),var(--b));border-radius:999px;
      animation:wave 1.1s infinite ease-in-out;
    }
    .wave i:nth-child(2){animation-delay:.08s}.wave i:nth-child(3){animation-delay:.16s}.wave i:nth-child(4){animation-delay:.24s}
    .wave i:nth-child(5){animation-delay:.32s}.wave i:nth-child(6){animation-delay:.4s}.wave i:nth-child(7){animation-delay:.48s}
    .wave i:nth-child(8){animation-delay:.56s}.wave i:nth-child(9){animation-delay:.64s}.wave i:nth-child(10){animation-delay:.72s}
    @keyframes wave{0%,100%{height:12px}50%{height:52px}}

    .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}
    .stat{background:#101728;border:1px solid var(--line);border-radius:.8rem;padding:.72rem}
    .stat b{display:block;font-size:1.16rem}

    .strip{padding:1.1rem 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:rgba(10,12,20,.55)}
    .strip-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem}
    .strip-item{background:#0f1423;border:1px solid var(--line);border-radius:.75rem;padding:.62rem .72rem;color:#d4ddf3;font-size:.92rem}

    .section{padding:4.9rem 0}
    .section h2{margin:0 0 .55rem;font-size:clamp(1.55rem,3.2vw,2.5rem)}
    .muted{color:var(--muted)}
    .cards{display:grid;gap:.9rem}
    .four{grid-template-columns:repeat(4,minmax(0,1fr))}
    .three{grid-template-columns:repeat(3,minmax(0,1fr))}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem}
    .card h3{margin:.15rem 0 .45rem}
    .card p{margin:0;color:var(--muted)}

    .feature-icon{font-size:1.1rem;display:inline-grid;place-items:center;width:34px;height:34px;border-radius:.65rem;background:#0d1222;border:1px solid var(--line);margin-bottom:.6rem}

    .steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-top:1rem}
    .step{background:#10182a;border:1px solid var(--line);border-radius:.9rem;padding:.92rem}
    .step b{display:inline-flex;width:30px;height:30px;border-radius:50%;align-items:center;justify-content:center;background:linear-gradient(90deg,var(--a),var(--b));font-size:.82rem}
    .step h4{margin:.65rem 0 .4rem}
    .step p{margin:0;color:var(--muted)}

    .pricing .card{position:relative;overflow:hidden}
    .pricing .price{font-size:1.95rem;font-weight:900;margin:.4rem 0}
    .pricing .tag{display:inline-block;padding:.22rem .5rem;border-radius:999px;border:1px solid #3d4e7a;color:#dce3f6;font-size:.75rem}
    .pricing .best{border-color:#6252d1;box-shadow:0 0 0 1px rgba(111,25,216,.4) inset}

    .demo-layout{display:grid;grid-template-columns:1fr .9fr;gap:.95rem;margin-top:1rem}
    .form-grid{display:grid;gap:.72rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{font-size:.9rem;color:#dbe4fb}
    input,select,textarea{width:100%;border-radius:.72rem;border:1px solid var(--line);background:#0b1121;color:var(--text);padding:.72rem}
    .result{white-space:pre-wrap;background:#0a1020;border:1px solid var(--line);border-radius:.8rem;padding:.8rem;min-height:240px;color:#dce4fb;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.86rem}
    .action-row{display:flex;gap:.65rem;flex-wrap:wrap}
    .preview-wrap{margin-top:.85rem;padding:.75rem;border:1px solid var(--line);border-radius:.8rem;background:#0a1120}
    .preview-wrap h4{margin:.1rem 0 .55rem;font-size:.92rem}
    .preview-wrap audio{width:100%;max-width:420px;height:34px}
    .preview-note{margin-top:.45rem;color:var(--muted);font-size:.86rem}

    .api-grid{display:grid;grid-template-columns:1fr 1fr;gap:.95rem;margin-top:.95rem}
    pre{margin:0;background:#0a101f;border:1px solid var(--line);border-radius:.8rem;padding:.85rem;overflow:auto;color:#dbe4fb;font-size:.84rem}

    .reveal{opacity:0;transform:translateY(14px);transition:.55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .footer{border-top:1px solid var(--line);padding:1.2rem 0 2.3rem;color:var(--muted)}

    @media (max-width:1050px){
      .hero-grid,.demo-layout,.api-grid,.steps,.strip-grid,.four,.three,.two,.stats{grid-template-columns:1fr}
      .links{display:none}
    }
  </style>
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
        <a href="{{ route('dashboard') }}">Dashboard</a>
      </nav>
      <a class="btn btn-primary" href="#demo">Try Live Demo</a>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container hero-grid">
        <div class="reveal">
          <span class="eyebrow">ARABIC-FIRST TTS PLATFORM</span>
          <h1>Extraordinary voice experiences for messages, emails, and files.</h1>
          <p class="lead">Listen gives you premium-quality reading with translation support (EN→AR, AR→EN), queue-based generation, usage controls, and app-ready APIs — designed to feel like a world-class TTS product from day one.</p>
          <div class="cta">
            <a class="btn btn-primary" href="#demo">Run Demo Pipeline</a>
            <a class="btn btn-ghost" href="{{ route('dashboard') }}">Open Dashboard</a>
          </div>
        </div>

        <aside class="showcase reveal">
          <div class="showcase-head">
            <h3 style="margin:0">Live Voice Engine</h3>
            <span class="pill">● streaming-ready</span>
          </div>
          <div class="wave" aria-hidden="true">
            <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
          </div>
          <div class="stats">
            <div class="stat"><b>Arabic + English</b><small class="muted">Native playback</small></div>
            <div class="stat"><b>Translate & Speak</b><small class="muted">Email EN → Voice AR</small></div>
            <div class="stat"><b>Queue API</b><small class="muted">/api/reader/queue</small></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="strip">
      <div class="container strip-grid reveal">
        <div class="strip-item">⚡ Fast queue pipeline with status-ready architecture</div>
        <div class="strip-item">🌍 Arabic-first with multilingual expansion path</div>
        <div class="strip-item">🔐 Tier + usage tracking in database</div>
        <div class="strip-item">📱 Built for web, extension, and mobile apps</div>
      </div>
    </section>

    <section id="features" class="section container">
      <div class="reveal">
        <h2>Built to compete with the best TTS experiences</h2>
        <p class="muted">Premium design + practical backend that can scale into a full production platform.</p>
      </div>
      <div class="cards four" style="margin-top:1rem">
        <article class="card reveal">
          <div class="feature-icon">🎙️</div>
          <h3>Natural Playback</h3>
          <p>High-quality voice rendering pipeline for messages, files, and long-form email content.</p>
        </article>
        <article class="card reveal">
          <div class="feature-icon">🈯</div>
          <h3>Translation-First Listening</h3>
          <p>Translate text to user language before speaking, with source/target language handling.</p>
        </article>
        <article class="card reveal">
          <div class="feature-icon">📦</div>
          <h3>Reliable Queueing</h3>
          <p>Jobs are stored and tracked in database for safe async processing and retries.</p>
        </article>
        <article class="card reveal">
          <div class="feature-icon">📊</div>
          <h3>Usage Governance</h3>
          <p>Request count, character count, and minute tracking to enforce clean plan tiers.</p>
        </article>
      </div>
    </section>

    <section id="flow" class="section container">
      <div class="reveal">
        <h2>How the product flow works</h2>
        <p class="muted">Clear four-step architecture for stable delivery and future scaling.</p>
      </div>
      <div class="steps">
        <article class="step reveal"><b>01</b><h4>Ingest</h4><p>Email/message/file text is normalized and tagged with source language.</p></article>
        <article class="step reveal"><b>02</b><h4>Transform</h4><p>Optional translation pipeline maps text to target language with safety controls.</p></article>
        <article class="step reveal"><b>03</b><h4>Generate</h4><p>TTS job is queued, processed by workers, and persisted with playback metadata.</p></article>
        <article class="step reveal"><b>04</b><h4>Deliver</h4><p>Client app receives status and streams final voice output to user device.</p></article>
      </div>
    </section>

    <section id="pricing" class="section container pricing">
      <div class="reveal">
        <h2>Smart pricing tiers</h2>
        <p class="muted">Balanced for growth, cost-control, and abuse protection.</p>
      </div>
      <div class="cards three" style="margin-top:1rem">
        <article class="card reveal">
          <span class="tag">Starter</span>
          <h3>Free</h3>
          <div class="price">$0</div>
          <p>10 min/month · 600 chars/request</p>
        </article>
        <article class="card best reveal">
          <span class="tag">Most Popular</span>
          <h3>Pro</h3>
          <div class="price">$12</div>
          <p>250 min/month · 4,000 chars/request</p>
        </article>
        <article class="card reveal">
          <span class="tag">Scale</span>
          <h3>Business</h3>
          <div class="price">$39</div>
          <p>1,000 min/month · 10,000 chars/request</p>
        </article>
      </div>
    </section>

    <section id="demo" class="section container">
      <div class="reveal">
        <h2>Interactive pipeline demo</h2>
        <p class="muted">Paste text, choose target language, and validate processing timings instantly.</p>
      </div>
      <div class="demo-layout">
        <section class="card reveal">
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">Text input</label>
              <textarea id="text" name="text" rows="8" placeholder="Paste email/message/file content..."></textarea>
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
            <div>
              <label for="fast_mode">Preview speed mode</label>
              <select id="fast_mode" name="fast_mode">
                <option value="1">Lightning (faster preview)</option>
                <option value="0">High quality (slower preview)</option>
              </select>
            </div>
            <div class="action-row">
              <button class="btn btn-primary" type="submit">Run pipeline</button>
              <button class="btn btn-ghost" type="button" id="previewBtn">Preview Voice</button>
            </div>

            <div class="preview-wrap" id="previewWrap" style="display:none">
              <h4>Instant voice preview</h4>
              <audio id="previewAudio" controls preload="none"></audio>
              <div class="preview-note" id="previewMeta"></div>
            </div>
          </form>
        </section>

        <section id="api" class="card reveal">
          <h3 style="margin-top:0">Result</h3>
          <div id="result" class="result">Waiting...</div>
        </section>
      </div>

      <div class="api-grid">
        <section class="card reveal">
          <h3 style="margin-top:0">Queue endpoint</h3>
<pre>POST /listen/api/reader/queue
{
  "source_type": "email",
  "content": "Your email body...",
  "target_language": "ar",
  "translate": true
}</pre>
        </section>
        <section class="card reveal">
          <h3 style="margin-top:0">Jobs endpoint</h3>
<pre>GET /listen/api/reader/jobs?limit=25

Response:
{
  "ok": true,
  "count": 25,
  "jobs": [ ... ]
}</pre>
        </section>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">© {{ date('Y') }} Listen — premium Laravel foundation for next-gen TTS products.</div>
  </footer>

  <script>
    const form = document.getElementById('demoForm');
    const result = document.getElementById('result');
    const previewBtn = document.getElementById('previewBtn');
    const previewWrap = document.getElementById('previewWrap');
    const previewAudio = document.getElementById('previewAudio');
    const previewMeta = document.getElementById('previewMeta');

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
    };

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
      previewMeta.textContent = 'Generating voice preview... this can take 15-60s on CPU.';
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
        const modeLabel = data.fast_mode ? 'Lightning' : 'High quality';
        previewMeta.textContent = `Mode: ${modeLabel} · Provider: ${data.provider || 'unknown'} · Duration: ${data.duration_seconds || '?'}s`;
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
