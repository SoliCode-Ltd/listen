<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Demo Lab — Voice Playground</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg:#06080f;--surface:#11192b;--surface-2:#17223a;--line:#2a395c;--text:#f5f7ff;--muted:#a8b7da;
      --a:#fb8308;--b:#5906b2;--ok:#35c792;--shadow:0 22px 50px rgba(0,0,0,.35);
    }
    html[data-theme="light"]{
      --bg:#eef2ff;--surface:#ffffff;--surface-2:#f6f9ff;--line:#d9e2f1;--text:#111a2c;--muted:#586988;
      --a:#fb8308;--b:#5906b2;--ok:#1c996e;--shadow:0 14px 34px rgba(31,53,97,.15);
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(1000px 540px at 92% -16%,color-mix(in oklab,var(--b) 28%,transparent),transparent 64%),radial-gradient(900px 380px at 4% -12%,color-mix(in oklab,var(--a) 22%,transparent),transparent 58%),var(--bg)}
    .bg-orb{position:fixed;z-index:-1;filter:blur(30px);pointer-events:none;opacity:.32;border-radius:999px;animation:float 10s ease-in-out infinite}
    .bg-orb.a{width:16rem;height:16rem;background:color-mix(in oklab,var(--a) 72%,white 28%);left:-3rem;top:14vh}
    .bg-orb.b{width:20rem;height:20rem;background:color-mix(in oklab,var(--b) 72%,white 28%);right:-4rem;bottom:10vh;animation-delay:-3s}
    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
    .container{width:min(1240px,92%);margin:0 auto}
    .header{position:sticky;top:0;z-index:50;background:color-mix(in oklab,var(--bg) 84%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
    .nav{min-height:78px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900;display:flex;align-items:center;gap:.55rem}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}
    .nav-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.42rem;padding:.72rem .95rem;border-radius:.76rem;font-weight:700;text-decoration:none;border:1px solid var(--line);background:var(--surface);color:var(--text);cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}
    .btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px color-mix(in oklab,var(--b) 22%,transparent)}
    .btn-primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

    .hero{padding:3rem 0 1.5rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:stretch}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);transition:transform .25s ease,border-color .25s ease}
    .card:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--b) 48%,var(--line) 52%)}
    .eyebrow{display:inline-block;padding:.3rem .65rem;border-radius:999px;border:1px solid var(--line);font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
    h1{margin:.7rem 0 .7rem;font-size:clamp(2rem,5.2vw,3.8rem);line-height:1.04;letter-spacing:-.03em}
    p{margin:0;color:var(--muted)}
    .hero-list{display:grid;gap:.5rem;margin-top:1rem}
    .hero-list div{display:flex;align-items:center;gap:.45rem;color:color-mix(in oklab,var(--text) 88%,var(--muted) 12%)}
    .dot{width:.54rem;height:.54rem;border-radius:999px;background:linear-gradient(90deg,var(--a),var(--b))}

    .status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;margin-top:.8rem}
    .status{border:1px solid var(--line);border-radius:.8rem;background:var(--surface);padding:.68rem}
    .status b{display:block;font-size:1.1rem}

    .lab{padding:1.2rem 0 3rem}
    .lab-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:1rem}
    .form-grid{display:grid;gap:.72rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{font-size:.87rem;color:color-mix(in oklab,var(--text) 86%,var(--muted) 14%)}
    input,select,textarea{width:100%;padding:.72rem;border-radius:.7rem;border:1px solid var(--line);background:var(--surface);color:var(--text)}
    textarea{min-height:180px;resize:vertical}
    .quick{display:flex;gap:.42rem;flex-wrap:wrap}
    .chip{padding:.42rem .62rem;border-radius:999px;border:1px solid var(--line);background:var(--surface);font-size:.78rem;color:var(--muted);cursor:pointer}

    .voice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.56rem;max-height:270px;overflow:auto;padding-right:.25rem}
    .voice-item{border:1px solid var(--line);border-radius:.78rem;background:var(--surface);padding:.58rem;display:flex;gap:.58rem;align-items:center;transition:transform .2s ease,border-color .2s ease}
    .voice-item:hover{transform:translateY(-2px);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .voice-avatar{width:2rem;height:2rem;border-radius:999px;display:grid;place-items:center;background:linear-gradient(135deg,color-mix(in oklab,var(--a) 30%,transparent),color-mix(in oklab,var(--b) 30%,transparent));font-size:1.05rem}
    .voice-meta{display:grid;gap:.08rem}
    .voice-item strong{display:block;font-size:.92rem}
    .voice-item small{color:var(--muted)}
    .rec-badge{display:inline-flex;align-items:center;gap:.2rem;font-size:.66rem;padding:.15rem .42rem;border-radius:999px;border:1px solid color-mix(in oklab,var(--a) 58%,var(--line) 42%);background:color-mix(in oklab,var(--a) 16%,var(--surface) 84%);color:color-mix(in oklab,var(--a) 84%,var(--text) 16%);margin-left:.38rem}

    .result{white-space:pre-wrap;background:var(--surface);border:1px solid var(--line);border-radius:.8rem;padding:.8rem;min-height:180px;color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem}
    .audio-wrap{margin-top:.75rem;border:1px solid var(--line);border-radius:.8rem;background:var(--surface);padding:.72rem}
    .audio-wrap h4{margin:.1rem 0 .55rem}.audio-wrap audio{width:100%;height:34px}

    .hint{margin-top:.55rem;color:var(--muted);font-size:.84rem}
    .error{margin-top:.6rem;padding:.65rem;border-radius:.68rem;border:1px solid color-mix(in oklab,#ef4c78 48%,var(--line) 52%);background:color-mix(in oklab,#ef4c78 13%,var(--surface) 87%);color:color-mix(in oklab,#ef4c78 78%,var(--text) 22%)}

    .footer{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}
    .reveal{opacity:0;transform:translateY(14px);transition:opacity .55s ease,transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    @media (max-width:1080px){.hero-grid,.lab-grid,.two{grid-template-columns:1fr}.voice-grid{grid-template-columns:1fr}}
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
      <div class="brand">🎧 <span>Listen Demo Lab</span></div>
      <div class="nav-actions">
        <button class="btn" type="button" id="themeToggle" aria-label="Toggle theme">🌓</button>
        <a class="btn" href="{{ route('home') }}">Home</a>
        @auth
          <a class="btn" href="{{ route('dashboard') }}">Dashboard</a>
        @else
          <a class="btn" href="{{ route('login') }}">Login</a>
        @endauth
      </div>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <article class="card reveal">
          <span class="eyebrow">Voice experience lab</span>
          <h1>Pick Arabic or English voices, male or female, and hear the difference.</h1>
          <p>Designed for real product validation: test translation behavior, compare voice color, and confirm output quality before shipping to users.</p>
          <div class="hero-list">
            <div><span class="dot"></span> Curated Soli voice collection</div>
            <div><span class="dot"></span> Language-aware voice filtering</div>
            <div><span class="dot"></span> Instant preview and production-safe output</div>
          </div>
        </article>

        <aside class="card reveal">
          <h3 style="margin:.1rem 0 .45rem">Voice inventory</h3>
          <p>All voices below are available in your current Listen voice library.</p>
          <div class="status-grid">
            <div class="status"><small>Arabic</small><b>{{ count($voiceCatalog['ar'] ?? []) }} voices</b></div>
            <div class="status"><small>English</small><b>{{ count($voiceCatalog['en'] ?? []) }} voices</b></div>
            <div class="status"><small>Default Arabic</small><b>{{ $defaultVoiceAr['label'] ?? 'Soli Noor' }}</b></div>
            <div class="status"><small>Default English</small><b>{{ $defaultVoiceEn['label'] ?? 'Soli Nova' }}</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="lab">
      <div class="lab-grid">
        <section class="card reveal">
          <h3 style="margin:.1rem 0 .7rem">Run a perfect demo</h3>
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">Text input</label>
              <textarea id="text" name="text" placeholder="Paste text to synthesize..."></textarea>
            </div>

            <div class="quick">
              <button class="chip" type="button" data-sample="Welcome to SoliCode. We build software that sounds as good as it works.">English sample</button>
              <button class="chip" type="button" data-sample="مرحباً بك في سولي كود. نحن نبني برمجيات احترافية بجودة عالمية.">Arabic sample</button>
              <button class="chip" type="button" data-sample="Hello, this is a bilingual test. مرحباً، هذا اختبار ثنائي اللغة.">Bilingual sample</button>
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
                <label for="voice_gender">Gender</label>
                <select id="voice_gender">
                  <option value="all">Any</option>
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                </select>
              </div>
            </div>

            <div>
              <label for="voice_name">Voice</label>
              <select id="voice_name" name="voice_key"></select>
            </div>

            <div>
              <label for="translate">Translate before speaking</label>
              <select id="translate" name="translate">
                <option value="1">Yes — convert text to target language first</option>
                <option value="0">No — keep original text language</option>
              </select>
              <div class="hint" id="translateHint">If enabled, we first translate your text to the selected target language, then generate the voice from that translated text.</div>
            </div>

            <div style="display:flex;gap:.62rem;flex-wrap:wrap">
              <button class="btn btn-primary" type="submit">Validate setup</button>
              <button class="btn" type="button" id="previewBtn">Generate voice preview</button>
            </div>
          </form>

          <div id="errorBox" class="error" style="display:none"></div>

          <div class="audio-wrap" id="audioWrap" style="display:none">
            <h4>Playback</h4>
            <audio id="previewAudio" controls preload="none"></audio>
            <div class="hint" id="audioMeta"></div>
          </div>
        </section>

        <aside class="card reveal">
          <h3 style="margin:.1rem 0 .7rem">Result payload</h3>
          <div id="result" class="result">Waiting...</div>

          <h3 style="margin:1rem 0 .55rem">Voices for current filters</h3>
          <div id="voiceCards" class="voice-grid"></div>
        </aside>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">© {{ date('Y') }} Listen Demo Lab · Crafted for accurate voice evaluation.</div>
  </footer>

  <script>
    const voiceCatalog = @json($voiceCatalog);
    const form = document.getElementById('demoForm');
    const targetLanguage = document.getElementById('target_language');
    const voiceGender = document.getElementById('voice_gender');
    const voiceSelect = document.getElementById('voice_name');
    const voiceCards = document.getElementById('voiceCards');
    const result = document.getElementById('result');
    const previewBtn = document.getElementById('previewBtn');
    const audioWrap = document.getElementById('audioWrap');
    const previewAudio = document.getElementById('previewAudio');
    const audioMeta = document.getElementById('audioMeta');
    const errorBox = document.getElementById('errorBox');
    const translateSelect = document.getElementById('translate');
    const translateHint = document.getElementById('translateHint');

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
    };

    function setError(message = '') {
      if (!message) {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
        return;
      }

      errorBox.style.display = 'block';
      errorBox.textContent = message;
    }

    function getFilteredVoices() {
      const lang = targetLanguage.value;
      const gender = voiceGender.value;
      const list = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];

      return list.filter((voice) => gender === 'all' ? true : voice.gender === gender);
    }

    function renderVoiceOptions() {
      const voices = getFilteredVoices();
      const previous = voiceSelect.value;

      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">No voices available</option>';
      } else {
        for (const voice of voices) {
          const option = document.createElement('option');
          option.value = voice.key;
          const rec = voice.recommended ? ' ⭐' : '';
          option.textContent = `${voice.avatar || '🎙️'} ${voice.label} · ${voice.gender.toUpperCase()}${rec}`;
          voiceSelect.appendChild(option);
        }
      }

      if (voices.some((v) => v.key === previous)) {
        voiceSelect.value = previous;
      } else {
        const recommended = voices.find((v) => v.recommended);
        if (recommended) voiceSelect.value = recommended.key;
      }

      voiceCards.innerHTML = '';
      for (const voice of voices) {
        const card = document.createElement('div');
        card.className = 'voice-item';
        card.innerHTML = `
          <div class="voice-avatar">${voice.avatar || '🎙️'}</div>
          <div class="voice-meta">
            <strong>${voice.label}${voice.recommended ? '<span class="rec-badge">★ Recommended</span>' : ''}</strong>
            <small>${voice.gender.toUpperCase()}</small>
          </div>
        `;
        voiceCards.appendChild(card);
      }
    }

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1'
        ? 'Enabled: your text is translated first, then converted to voice in the selected target language.'
        : 'Disabled: your text is spoken directly in its original language style.';
    }

    targetLanguage.addEventListener('change', renderVoiceOptions);
    voiceGender.addEventListener('change', renderVoiceOptions);
    translateSelect?.addEventListener('change', updateTranslateHint);
    renderVoiceOptions();
    updateTranslateHint();

    document.querySelectorAll('[data-sample]').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('text').value = btn.getAttribute('data-sample') || '';
      });
    });

    form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      setError('');
      result.textContent = 'Processing...';

      try {
        const fd = new FormData(form);
        const res = await fetch('{{ route('demo.run') }}', {
          method: 'POST',
          headers: csrfHeaders,
          body: fd,
        });

        const data = await res.json();
        if (!res.ok || data?.ok === false) {
          throw new Error(data?.message || data?.error || 'Validation failed');
        }

        result.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        setError(err?.message || 'Unknown error');
        result.textContent = 'Error';
      }
    });

    previewBtn?.addEventListener('click', async () => {
      setError('');
      audioWrap.style.display = 'block';
      audioMeta.textContent = 'Generating...';
      previewAudio.removeAttribute('src');

      try {
        const fd = new FormData(form);
        const res = await fetch('{{ route('demo.preview') }}', {
          method: 'POST',
          headers: csrfHeaders,
          body: fd,
        });

        const data = await res.json();
        if (!res.ok || !data?.ok || !data?.audio_url) {
          throw new Error(data?.message || 'Preview generation failed');
        }

        previewAudio.src = data.audio_url;
        previewAudio.load();
        const voiceLabel = data?.voice?.label || 'Selected voice';
        const voiceGender = data?.voice?.gender ? ` (${String(data.voice.gender).toUpperCase()})` : '';
        const translateNote = data?.translate_note ? ` · ${data.translate_note}` : '';
        audioMeta.textContent = `Voice: ${voiceLabel}${voiceGender} · Duration: ${data.duration_seconds || '?'}s${translateNote}`;
        result.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        setError(err?.message || 'Preview generation failed');
        audioMeta.textContent = 'Failed to generate preview.';
      }
    });

    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) entry.target.classList.add('show');
      }
    }, { threshold: 0.12 });

    document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));

    document.getElementById('themeToggle')?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });
  </script>
</body>
</html>
