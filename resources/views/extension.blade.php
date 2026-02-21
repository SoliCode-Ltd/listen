<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Chrome Extension</title>
  <meta name="description" content="Install and manage your Listen Chrome Extension access." />
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#11182a;--surface2:#15213a;--line:#2b3a5d;--text:#f4f7ff;--muted:#9fb0d4;--a:#fb8308;--b:#5906b2;--ok:#32c58d;--danger:#ee4a77;--shadow:0 16px 46px rgba(0,0,0,.30)}
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
    .desktop-nav a.active{color:var(--text);background:color-mix(in oklab,var(--surface) 85%,transparent)}

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.68rem .9rem;border-radius:.72rem;border:1px solid var(--line);background:var(--surface);color:var(--text);font-weight:700;text-decoration:none;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
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

    .main{padding:1.2rem 0 1.2rem;display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
    .card{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow)}
    h1{margin:.1rem 0 .5rem;font-size:1.45rem}
    h2{margin:.1rem 0 .45rem;font-size:1.12rem}
    .muted{color:var(--muted)}
    .actions{display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.7rem}
    .status-line{margin:.4rem 0 0}
    .badge{display:inline-block;padding:.24rem .48rem;border-radius:999px;border:1px solid var(--line);font-size:.74rem}
    .badge.on{background:color-mix(in oklab,var(--ok) 14%,var(--surface) 86%)}
    .badge.off{background:color-mix(in oklab,var(--danger) 14%,var(--surface) 86%)}

    .keybox{margin-top:.7rem;padding:.75rem;border:1px solid color-mix(in oklab,var(--a) 40%,var(--line) 60%);border-radius:.75rem;background:color-mix(in oklab,var(--a) 10%,var(--surface) 90%)}
    .keybox code{display:block;word-break:break-all;margin-top:.45rem;padding:.55rem;border-radius:.55rem;border:1px dashed var(--line);background:var(--surface);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .msg{margin-top:.65rem;padding:.6rem .7rem;border:1px solid var(--line);border-radius:.62rem;background:var(--surface)}
    .msg.ok{border-color:color-mix(in oklab,var(--ok) 55%,var(--line) 45%);color:color-mix(in oklab,var(--ok) 84%,var(--text) 16%)}
    .msg.err{border-color:color-mix(in oklab,var(--danger) 55%,var(--line) 45%);color:color-mix(in oklab,var(--danger) 84%,var(--text) 16%)}

    .steps{margin:.35rem 0 0;padding-left:1rem;display:grid;gap:.35rem}
    .code{margin-top:.55rem;padding:.58rem .65rem;border:1px solid var(--line);border-radius:.62rem;background:var(--surface);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.83rem;word-break:break-all}
    .showcase{grid-column:1 / -1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}
    .feature{padding:1rem;border-radius:.85rem;border:1px solid var(--line);background:var(--surface)}
    .feature h3{margin:.1rem 0 .35rem;font-size:1rem}
    .feature p{margin:0;color:var(--muted);font-size:.9rem}

    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}
    .foot{border-top:1px solid var(--line);padding:1rem 0 1.8rem;color:var(--muted)}

    @media (max-width:980px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .main{grid-template-columns:1fr}
      .showcase{grid-template-columns:1fr}
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
        <a href="{{ route('pricing') }}">Pricing</a>
        <a href="{{ route('dashboard') }}">Dashboard</a>
        <a href="{{ route('extension.page') }}" class="active">Chrome Extension</a>
      </nav>

      <div class="desktop-actions">
        <button type="button" class="btn theme-btn" id="themeToggleDesktop" aria-label="Toggle theme">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
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
        <form method="POST" action="{{ route('logout') }}" style="margin:0">@csrf<button class="btn" type="submit">Logout</button></form>
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
      <div class="mobile-user">Logged in as {{ auth()->user()->name }}</div>
      <a href="{{ route('home') }}">Home</a>
      <a href="{{ route('pricing') }}">Pricing</a>
      <a href="{{ route('dashboard') }}">Dashboard</a>
      <a href="{{ route('extension.page') }}">Chrome Extension</a>
      <form method="POST" action="{{ route('logout') }}" style="margin-top:.4rem">@csrf<button class="btn" type="submit">Logout</button></form>
    </div>
  </header>

  <main class="container main">
    <section class="card reveal">
      <h1>Chrome Extension</h1>
      <p class="muted">Turn any text on the web into natural voice in one click.</p>

      <div class="actions">
        <a class="btn primary" href="{{ route('extension.download', ['v' => $extensionVersion]) }}" id="downloadBtn">Download for Chrome</a>
        <button class="btn" id="generateBtn" type="button">Generate API Key</button>
        <button class="btn" id="revokeBtn" type="button">Revoke API Key</button>
      </div>

      <div id="messageBox"></div>

      <div class="status-line">
        <span id="keyBadge" class="badge">No key</span>
      </div>

      <div class="keybox">
        <strong>Active key</strong>
        <div class="muted" id="keyMask" style="margin-top:.35rem">Not generated yet.</div>
        <div class="muted" id="keyCreated" style="margin-top:.28rem"></div>
      </div>

      <div class="keybox" id="newKeyBox" style="display:none">
        <strong>New key (copy now)</strong>
        <code id="newKeyValue"></code>
        <div class="actions" style="margin-top:.45rem">
          <button class="btn" id="copyKeyBtn" type="button">Copy API Key</button>
        </div>
      </div>
    </section>

    <section class="card reveal">
      <h2>How to install</h2>
      <ol class="steps">
        <li>Download the extension package using the button.</li>
        <li>Open <a href="chrome://extensions/" id="extensionsLink" target="_blank" rel="noopener noreferrer"><code>chrome://extensions/</code></a> and enable Developer mode.</li>
        <li>Click <code>Load unpacked</code> and select the extracted folder.</li>
        <li>Open extension popup, paste your API key, then save.</li>
        <li>Select text on any page and use <code>Listen selected text</code>.</li>
      </ol>
      <p class="muted" id="extensionsOpenHint" style="display:none;margin-top:.6rem"></p>

    </section>

    <section class="card showcase reveal">
      <article class="feature">
        <h3>One-click listening</h3>
        <p>Select text in Gmail, docs, and any website, then run "Listen selected text" from the context menu.</p>
      </article>
      <article class="feature">
        <h3>Secure by design</h3>
        <p>Your personal API key stays tied to your account plan and can be rotated instantly without page reload.</p>
      </article>
      <article class="feature">
        <h3>Built for speed</h3>
        <p>Audio is generated immediately and opened in the extension player so your workflow never stops.</p>
      </article>
    </section>
  </main>

  <footer class="foot"><div class="container">&copy; {{ date('Y') }} Listen. Crafted by SoliCode Ltd.</div></footer>

  <script>
    const csrfToken = '{{ csrf_token() }}';
    const routes = {
      generate: '{{ route('extension.key.regenerate') }}',
      revoke: '{{ route('extension.key.revoke') }}',
    };

    const initialState = @json($apiState);
    const initialNewKey = @json($newApiKey);

    const messageBox = document.getElementById('messageBox');
    const keyBadge = document.getElementById('keyBadge');
    const keyMask = document.getElementById('keyMask');
    const keyCreated = document.getElementById('keyCreated');
    const newKeyBox = document.getElementById('newKeyBox');
    const newKeyValue = document.getElementById('newKeyValue');

    const generateBtn = document.getElementById('generateBtn');
    const revokeBtn = document.getElementById('revokeBtn');

    function showMessage(type, message) {
      if (!message) {
        messageBox.innerHTML = '';
        return;
      }

      const className = type === 'error' ? 'msg err' : 'msg ok';
      messageBox.innerHTML = `<div class="${className}">${String(message)}</div>`;
    }

    function renderApiState(state) {
      const hasKey = Boolean(state?.has_api_key);
      if (hasKey) {
        keyBadge.textContent = 'API key active';
        keyBadge.className = 'badge on';
        keyMask.textContent = `lk_live_************************************${state.api_key_last4 || ''}`;
        keyCreated.textContent = state.api_key_created_human ? `Generated ${state.api_key_created_human}` : '';
      } else {
        keyBadge.textContent = 'No API key';
        keyBadge.className = 'badge off';
        keyMask.textContent = 'Not generated yet.';
        keyCreated.textContent = '';
      }

      revokeBtn.disabled = !hasKey;
    }

    function showNewKey(key) {
      if (!key) {
        newKeyBox.style.display = 'none';
        newKeyValue.textContent = '';
        return;
      }

      newKeyValue.textContent = String(key);
      newKeyBox.style.display = 'block';
    }

    async function postJson(url) {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || data?.ok === false) {
        const errors = data?.errors;
        if (errors && typeof errors === 'object') {
          const first = Object.values(errors)[0];
          if (Array.isArray(first) && first[0]) {
            throw new Error(String(first[0]));
          }
        }

        throw new Error(data?.message || 'Request failed.');
      }

      return data;
    }

    async function generateKey() {
      generateBtn.disabled = true;
      showMessage('', '');

      try {
        const data = await postJson(routes.generate);
        renderApiState(data.api_state || {});
        showNewKey(data.new_api_key || '');
        showMessage('ok', data.message || 'API key generated.');
      } catch (error) {
        showMessage('error', error?.message || 'Could not generate API key.');
      } finally {
        generateBtn.disabled = false;
      }
    }

    async function revokeKey() {
      revokeBtn.disabled = true;
      showMessage('', '');

      try {
        const data = await postJson(routes.revoke);
        renderApiState(data.api_state || {});
        showNewKey('');
        showMessage('ok', data.message || 'API key revoked.');
      } catch (error) {
        showMessage('error', error?.message || 'Could not revoke API key.');
      } finally {
        revokeBtn.disabled = false;
      }
    }

    document.getElementById('copyKeyBtn')?.addEventListener('click', async () => {
      const raw = String(newKeyValue.textContent || '').trim();
      if (!raw) return;

      try {
        await navigator.clipboard.writeText(raw);
        showMessage('ok', 'API key copied to clipboard.');
      } catch (_e) {
        showMessage('error', 'Copy failed. Please copy the key manually.');
      }
    });

    generateBtn?.addEventListener('click', generateKey);
    revokeBtn?.addEventListener('click', revokeKey);

    renderApiState(initialState || {});
    showNewKey(initialNewKey || '');

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

    const extensionsLink = document.getElementById('extensionsLink');
    const extensionsOpenHint = document.getElementById('extensionsOpenHint');
    extensionsLink?.addEventListener('click', async (event) => {
      event.preventDefault();
      const target = 'chrome://extensions/';
      const opened = window.open(target, '_blank', 'noopener,noreferrer');

      if (opened) {
        if (extensionsOpenHint) extensionsOpenHint.style.display = 'none';
        return;
      }

      if (!extensionsOpenHint) return;

      try {
        await navigator.clipboard.writeText(target);
        extensionsOpenHint.textContent = 'Chrome blocked direct opening from this page. We copied chrome://extensions/ to your clipboard.';
      } catch (_error) {
        extensionsOpenHint.textContent = 'Chrome blocked direct opening from this page. Copy chrome://extensions/ and paste it in the address bar.';
      }
      extensionsOpenHint.style.display = 'block';
    });
  </script>
</body>
</html>
