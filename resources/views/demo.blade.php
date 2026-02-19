<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Demo Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#11182a;--surface2:#15213a;--line:#2b3a5d;--text:#f4f7ff;--muted:#9fb0d4;--a:#fb8308;--b:#5906b2;--shadow:0 16px 46px rgba(0,0,0,.30)}
    html[data-theme="light"]{--bg:#eff3ff;--surface:#fff;--surface2:#f7f9ff;--line:#d8e2f1;--text:#121a2d;--muted:#5b6c89;--shadow:0 14px 34px rgba(35,56,97,.13)}
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;background:radial-gradient(1200px 500px at 92% -20%,color-mix(in oklab,var(--b) 25%,transparent),transparent 60%),radial-gradient(900px 420px at -5% 0%,color-mix(in oklab,var(--a) 18%,transparent),transparent 58%),var(--bg);color:var(--text)}
    .container{width:min(1240px,calc(100% - 1.35rem));margin:auto}

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

    .hero{padding:2.4rem 0 1rem}
    .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem}
    .card{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1.1rem;box-shadow:var(--shadow);transition:transform .22s ease,border-color .22s ease}
    .card:hover{transform:translateY(-3px);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .eyebrow{display:inline-block;padding:.3rem .62rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
    h1{margin:.65rem 0 .65rem;font-size:clamp(2rem,5vw,3.6rem);line-height:1.04;letter-spacing:-.03em}
    .hero-list{display:grid;gap:.45rem;margin-top:.75rem;color:var(--muted)}
    .sound-wave{margin-top:.9rem;display:flex;align-items:flex-end;gap:4px;height:36px}
    .sound-wave span{display:block;width:4px;border-radius:999px;background:linear-gradient(180deg,var(--a),var(--b));animation:wavePulse 1s ease-in-out infinite;opacity:.9}
    .sound-wave span:nth-child(2){animation-delay:.08s}.sound-wave span:nth-child(3){animation-delay:.16s}.sound-wave span:nth-child(4){animation-delay:.24s}.sound-wave span:nth-child(5){animation-delay:.32s}.sound-wave span:nth-child(6){animation-delay:.40s}.sound-wave span:nth-child(7){animation-delay:.48s}.sound-wave span:nth-child(8){animation-delay:.56s}.sound-wave span:nth-child(9){animation-delay:.64s}.sound-wave span:nth-child(10){animation-delay:.72s}.sound-wave span:nth-child(11){animation-delay:.80s}.sound-wave span:nth-child(12){animation-delay:.88s}
    .sound-wave.dynamic span{animation-duration:.75s}
    @keyframes wavePulse{0%,100%{height:6px}50%{height:34px}}

    .stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;margin-top:.7rem}
    .stat{background:var(--surface);border:1px solid var(--line);border-radius:.75rem;padding:.62rem}
    .stat b{display:block;font-size:1.03rem}

    .lab{padding:.85rem 0 2.4rem}
    .lab-grid{display:grid;grid-template-columns:1fr;gap:1rem}
    .form-grid{display:grid;gap:.9rem}
    .form-grid > div{padding-inline:.1rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{display:block;margin:0 0 .42rem;font-size:.9rem;font-weight:600;color:color-mix(in oklab,var(--text) 88%,var(--muted) 12%)}
    input,select,textarea{width:100%;padding:.84rem .9rem;border-radius:.8rem;border:1px solid var(--line);background:var(--surface);color:var(--text)}
    textarea{min-height:170px;resize:vertical}

    .chips{display:flex;gap:.42rem;flex-wrap:wrap}
    .chip{padding:.4rem .62rem;border-radius:999px;border:1px solid var(--line);background:var(--surface);font-size:.77rem;color:var(--muted);cursor:pointer;transition:all .2s ease}
    .chip:hover{transform:translateY(-1px);border-color:color-mix(in oklab,var(--b) 45%,var(--line) 55%)}

    .hint{font-size:.83rem;color:var(--muted)}
    .error{margin-top:.62rem;padding:.65rem;border-radius:.68rem;border:1px solid color-mix(in oklab,#ef4c78 48%,var(--line) 52%);background:color-mix(in oklab,#ef4c78 13%,var(--surface) 87%);color:color-mix(in oklab,#ef4c78 78%,var(--text) 22%)}

    .audio{margin-top:.7rem;padding:.72rem;border:1px solid var(--line);border-radius:.75rem;background:var(--surface)}
    .audio audio{width:100%;height:34px}


    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}

    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
    @media (max-width:960px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .hero{padding:1.45rem 0 .65rem}
      .lab{padding:.55rem 0 1.7rem}
      .hero-grid,.lab-grid,.two,.stats{grid-template-columns:1fr}
      .card{padding:.95rem}
      .sound-wave{margin-top:.7rem}
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
    <section class="hero">
      <div class="hero-grid">
        <article class="card reveal">
          <span class="eyebrow">Live voice test studio</span>
          <h1>Choose voice, language, and translation behavior — then preview instantly.</h1>
          <p class="muted">This page is optimized for quick product validation. No clutter, only the controls that matter.</p>
          <div class="hero-list">
            <div>• Branded voice personas only</div>
            <div>• Clear translation behavior</div>
            <div>• Instant audio preview</div>
          </div>
          <div class="sound-wave" id="heroWave" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span><span></span>
          </div>
        </article>

        <aside class="card reveal">
          <h3 style="margin:.1rem 0 .4rem">Voice coverage</h3>
          <div class="stats">
            <div class="stat"><small class="muted">Arabic voices</small><b>{{ count($voiceCatalog['ar'] ?? []) }}</b></div>
            <div class="stat"><small class="muted">English voices</small><b>{{ count($voiceCatalog['en'] ?? []) }}</b></div>
            <div class="stat"><small class="muted">Arabic default</small><b>{{ $defaultVoiceAr['label'] ?? 'Soli Noor' }}</b></div>
            <div class="stat"><small class="muted">English default</small><b>{{ $defaultVoiceEn['label'] ?? 'Soli Nova' }}</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="lab">
      <div class="lab-grid">
        <section class="card reveal">
          <h3 style="margin:.1rem 0 .65rem">Try your best demo flow</h3>
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">Text input</label>
              <textarea id="text" name="text" placeholder="Paste content to test..." required></textarea>
            </div>

            <div class="chips">
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
              <label for="voice_key">Voice</label>
              <select id="voice_key" name="voice_key"></select>
            </div>

            <div>
              <label for="translate">Translate before speaking</label>
              <select id="translate" name="translate">
                <option value="1">Yes — translate text first</option>
                <option value="0">No — keep text as-is</option>
              </select>
              <div class="hint" id="translateHint">Enabled: text is translated first, then spoken in the selected target language.</div>
            </div>

            <div style="display:flex;gap:.55rem;flex-wrap:wrap">
              <button class="btn primary" type="button" id="previewBtn">Generate Voice</button>
            </div>
          </form>

          <div id="errorBox" class="error" style="display:none"></div>

          <div class="audio" id="audioWrap" style="display:none">
            <audio id="previewAudio" controls preload="none"></audio>
            <div class="hint" id="audioMeta" style="margin-top:.5rem"></div>
          </div>
        </section>

      </div>
    </section>
  </main>

  <footer class="foot">
    <div class="container">© {{ date('Y') }} Listen · Built by SoliCode.</div>
  </footer>

  <script>
    const voiceCatalog = @json($voiceCatalog);
    const form = document.getElementById('demoForm');
    const targetLanguage = document.getElementById('target_language');
    const voiceGender = document.getElementById('voice_gender');
    const voiceSelect = document.getElementById('voice_key');
    const previewBtn = document.getElementById('previewBtn');
    const audioWrap = document.getElementById('audioWrap');
    const previewAudio = document.getElementById('previewAudio');
    const audioMeta = document.getElementById('audioMeta');
    const errorBox = document.getElementById('errorBox');
    const translateSelect = document.getElementById('translate');
    const translateHint = document.getElementById('translateHint');
    const heroWave = document.getElementById('heroWave');

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

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1'
        ? 'Enabled: text is translated first, then spoken in the selected target language.'
        : 'Disabled: original text is spoken directly without translation.';
    }

    function getFilteredVoices() {
      const lang = targetLanguage.value;
      const gender = voiceGender.value;
      const list = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      return list.filter((voice) => gender === 'all' ? true : voice.gender === gender);
    }

    function formatGender(value) {
      const text = String(value || '').trim().toLowerCase();
      if (!text) return 'Any';
      return text.charAt(0).toUpperCase() + text.slice(1);
    }

    function renderVoiceOptions() {
      const voices = getFilteredVoices();
      const current = voiceSelect.value;

      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">No voices available</option>';
        return;
      }

      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = `${voice.label} · ${formatGender(voice.gender)}`;
        voiceSelect.appendChild(option);
      }

      if (voices.some((v) => v.key === current)) {
        voiceSelect.value = current;
      } else {
        voiceSelect.value = voices[0].key;
      }
    }

    async function callEndpoint(endpoint) {
      setError('');
      const fd = new FormData(form);
      const res = await fetch(endpoint, { method: 'POST', headers: csrfHeaders, body: fd });
      const data = await res.json();

      if (!res.ok || data?.ok === false) {
        throw new Error(data?.message || 'Request failed');
      }
      return data;
    }

    previewBtn?.addEventListener('click', async () => {
      heroWave?.classList.add('dynamic');
      try {
        audioWrap.style.display = 'block';
        previewAudio.removeAttribute('src');
        audioMeta.textContent = 'Generating voice preview...';

        const data = await callEndpoint('{{ route('demo.preview') }}');

        previewAudio.src = data.audio_url;
        previewAudio.load();

        const label = data?.voice?.label || 'Selected voice';
        const gender = data?.voice?.gender ? ` (${formatGender(data.voice.gender)})` : '';
        audioMeta.textContent = `Voice: ${label}${gender} · ${data.translate_note}`;
      } catch (err) {
        setError(err?.message || 'Preview failed');
        audioMeta.textContent = 'Preview failed. Try another text or voice.';
      } finally {
        setTimeout(() => heroWave?.classList.remove('dynamic'), 900);
      }
    });

    document.querySelectorAll('[data-sample]').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('text').value = btn.getAttribute('data-sample') || '';
      });
    });

    targetLanguage?.addEventListener('change', renderVoiceOptions);
    voiceGender?.addEventListener('change', renderVoiceOptions);
    translateSelect?.addEventListener('change', updateTranslateHint);
    renderVoiceOptions();
    updateTranslateHint();

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
  </script>
</body>
</html>
