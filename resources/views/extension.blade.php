<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Browsers Extension</title>
  <meta name="description" content="Listen browser extensions for Chrome, Firefox, and Safari. Convert text to speech across the web." />
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
  <style>
    .hero {
      padding: 3rem 0 2rem;
      text-align: center;
    }
    .hero h1 {
      margin: 0 0 .75rem;
      font-size: clamp(2rem, 4.5vw, 3rem);
    }
    .hero p {
      color: var(--muted);
      font-size: 1.05rem;
      max-width: 60ch;
      margin: 0 auto;
    }

    .browsers-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.5rem;
      margin-top: 2.5rem;
    }
    .browser-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 2rem 1.5rem;
      text-align: center;
      box-shadow: var(--shadow);
      transition: transform .28s ease, border-color .28s ease;
      position: relative;
      overflow: hidden;
    }
    .browser-card:hover {
      transform: translateY(-4px);
    }
    .browser-card.available:hover {
      border-color: color-mix(in oklab, var(--a) 50%, var(--line));
    }
    .browser-card.coming-soon {
      opacity: .65;
    }
    .browser-card.coming-soon:hover {
      transform: none;
    }

    .browser-icon {
      width: 3.5rem;
      height: 3.5rem;
      margin: 0 auto 1.25rem;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .browser-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
    .browser-icon svg {
      width: 100%;
      height: 100%;
    }

    @keyframes floatIcon {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }
    .browser-card.available .browser-icon img {
      animation: floatIcon 3s ease-in-out infinite;
    }

    .browser-card h3 {
      margin: 0 0 .4rem;
      font-size: 1.2rem;
    }
    .browser-card p {
      margin: 0 0 1.25rem;
      color: var(--muted);
      font-size: .9rem;
    }

    .coming-soon-badge {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--muted) 20%, var(--surface));
      color: var(--muted);
      font-size: .78rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .download-btn {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .65rem 1.2rem;
      border-radius: var(--radius);
      background: linear-gradient(135deg, var(--a), var(--b));
      color: #fff;
      font-weight: 600;
      font-size: .92rem;
      text-decoration: none;
      transition: all .2s ease;
    }
    .download-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px color-mix(in oklab, var(--b) 30%, transparent);
    }
    .download-btn svg {
      width: 1.1rem;
      height: 1.1rem;
    }

    .section {
      margin-top: 3rem;
    }
    .section-title {
      font-size: 1.5rem;
      margin: 0 0 .5rem;
    }
    .section-subtitle {
      color: var(--muted);
      margin: 0 0 1.5rem;
    }

    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
    }
    .page-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.75rem;
      box-shadow: var(--shadow);
    }
    .page-card h2 {
      margin: 0 0 .7rem;
      font-size: 1.2rem;
    }

    .steps {
      margin: 0;
      padding-left: 1.3rem;
      display: grid;
      gap: .6rem;
    }
    .steps li {
      line-height: 1.55;
      color: var(--muted);
    }
    .steps code {
      padding: .18rem .45rem;
      border-radius: .4rem;
      background: var(--surface);
      font-size: .85rem;
      color: var(--text);
    }

    .api-section {
      margin-top: 3rem;
      margin-bottom: 3rem;
    }
    .api-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 2rem;
      box-shadow: var(--shadow);
    }
    .api-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .api-header h2 {
      margin: 0 0 .4rem;
      font-size: 1.4rem;
    }
    .api-header p {
      color: var(--muted);
      margin: 0;
    }

    .api-actions {
      display: flex;
      gap: .6rem;
      flex-wrap: wrap;
    }
    .api-status {
      display: flex;
      align-items: center;
      gap: .6rem;
      margin-top: 1.25rem;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .35rem .6rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .8rem;
      font-weight: 600;
    }
    .badge.active {
      background: color-mix(in oklab, var(--ok) 14%, var(--surface));
      color: color-mix(in oklab, var(--ok) 80%, var(--text));
      border-color: color-mix(in oklab, var(--ok) 40%, var(--line));
    }
    .badge.inactive {
      background: color-mix(in oklab, var(--muted) 12%, var(--surface));
      color: var(--muted);
    }
    .badge-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .key-display {
      margin-top: 1.25rem;
      padding: 1rem 1.25rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--a) 35%, var(--line));
      background: color-mix(in oklab, var(--a) 8%, var(--surface));
    }
    .key-display-label {
      font-size: .8rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: .4rem;
    }
    .key-display-value {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: .9rem;
      word-break: break-all;
    }

    .new-key-box {
      margin-top: 1rem;
      padding: 1.25rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--ok) 45%, var(--line));
      background: color-mix(in oklab, var(--ok) 10%, var(--surface));
      display: none;
    }
    .new-key-box.show {
      display: block;
    }
    .new-key-box-label {
      font-size: .85rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
      margin-bottom: .5rem;
    }
    .new-key-box code {
      display: block;
      word-break: break-all;
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px dashed var(--line);
      background: var(--surface);
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: .88rem;
    }
    .copy-btn-wrap {
      margin-top: .75rem;
    }

    .msg {
      margin-top: 1rem;
      padding: .75rem 1rem;
      border-radius: var(--radius);
      font-size: .9rem;
    }
    .msg.ok {
      background: color-mix(in oklab, var(--ok) 12%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--ok) 35%, var(--line));
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
    }
    .msg.err {
      background: color-mix(in oklab, var(--danger) 12%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--danger) 35%, var(--line));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }

    .features-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
      margin-top: 2.5rem;
    }
    .feature-card {
      padding: 1.25rem;
      border-radius: var(--radius-lg);
      border: 1px solid var(--line);
      background: var(--surface);
      text-align: center;
    }
    .feature-icon {
      width: 2.5rem;
      height: 2.5rem;
      margin: 0 auto .75rem;
      color: var(--a);
    }
    .feature-card h3 {
      margin: 0 0 .4rem;
      font-size: 1rem;
    }
    .feature-card p {
      margin: 0;
      color: var(--muted);
      font-size: .88rem;
      line-height: 1.5;
    }

    .reveal {
      opacity: 0;
      transform: translateY(14px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @media (max-width: 960px) {
      .browsers-grid {
        grid-template-columns: 1fr;
      }
      .two-col {
        grid-template-columns: 1fr;
      }
      .features-row {
        grid-template-columns: 1fr;
      }
      .api-header {
        flex-direction: column;
      }
    }
  </style>
  <script>
    (function() {
      var saved = localStorage.getItem('listen_theme');
      document.documentElement.setAttribute('data-theme', saved || 'dark');
    })();
  </script>
</head>
<body>
  @include('components.header')

  <main class="container">
    <section class="hero reveal">
      <h1>Browsers Extension</h1>
      <p>Turn any text on the web into natural voice. Select, click, and listen across all your favorite websites.</p>
    </section>

    <section class="browsers-grid">
      <article class="browser-card available reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/chrome.png') }}" alt="Chrome" />
        </div>
        <h3>Chrome</h3>
        <p>Full support with context menu integration</p>
        <a class="download-btn" href="{{ route('browsers-extension.download', ['v' => $extensionVersion]) }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Download
        </a>
      </article>

      <article class="browser-card coming-soon reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/firefox.png') }}" alt="Firefox" />
        </div>
        <h3>Mozilla Firefox</h3>
        <p>WebExtension API support</p>
        <span class="coming-soon-badge">Coming Soon</span>
      </article>

      <article class="browser-card coming-soon reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/safari.png') }}" alt="Safari" />
        </div>
        <h3>Apple Safari</h3>
        <p>Native Safari Web Extension</p>
        <span class="coming-soon-badge">Coming Soon</span>
      </article>
    </section>

    <section class="section reveal">
      <div class="two-col">
        <article class="page-card">
          <h2>Installation Guide</h2>
          <ol class="steps">
            <li>Download the extension package using the button above.</li>
            <li>Open <a href="chrome://extensions/" id="extensionsLink" target="_blank" rel="noopener"><code>chrome://extensions/</code></a> in your browser.</li>
            <li>Enable <strong>Developer mode</strong> in the top right corner.</li>
            <li>Click <code>Load unpacked</code> and select the extracted folder.</li>
            <li>Pin the extension to your toolbar for quick access.</li>
          </ol>
          <p id="extensionsOpenHint" style="display: none; margin-top: .75rem; color: var(--muted); font-size: .88rem;"></p>
        </article>

        <article class="page-card">
          <h2>How to Use</h2>
          <ol class="steps">
            <li>Click the extension icon and paste your API key.</li>
            <li>Select any text on a webpage.</li>
            <li>Right-click and choose <strong>Listen Selected Text</strong>.</li>
            <li>Audio opens in the side player on the current tab.</li>
            <li>Adjust voice and language settings anytime.</li>
          </ol>
        </article>
      </div>
    </section>

    <section class="features-row reveal">
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
        </svg>
        <h3>One-Click Listening</h3>
        <p>Select text anywhere and listen instantly with context menu integration.</p>
      </article>
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <h3>Secure by Design</h3>
        <p>Your API key is stored locally and tied to your account with instant rotation.</p>
      </article>
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12,6 12,12 16,14"/>
        </svg>
        <h3>Built for Speed</h3>
        <p>Audio streams immediately with no waiting. Keep your workflow uninterrupted.</p>
      </article>
    </section>

    <section class="api-section reveal">
      <article class="api-card">
        <div class="api-header">
          <div>
            <h2>API Key Management</h2>
            <p>Generate and manage your extension API key. Keep it secure.</p>
          </div>
          <div class="api-actions">
            <button class="btn btn-primary" id="generateBtn" type="button">Generate Key</button>
            <button class="btn" id="revokeBtn" type="button">Revoke Key</button>
          </div>
        </div>

        <div id="messageBox"></div>

        <div class="api-status">
          <span id="keyBadge" class="badge inactive"><span class="badge-dot"></span>No API Key</span>
        </div>

        <div class="key-display" id="keyDisplayBox">
          <div class="key-display-label">Active Key</div>
          <div class="key-display-value" id="keyMask">Not generated yet. Click "Generate Key" to create one.</div>
        </div>

        <div class="new-key-box" id="newKeyBox">
          <div class="new-key-box-label">New Key - Copy Now (shown only once)</div>
          <code id="newKeyValue"></code>
          <div class="copy-btn-wrap">
            <button class="btn" id="copyKeyBtn" type="button">Copy to Clipboard</button>
          </div>
        </div>
      </article>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script>
    var csrfToken = '{{ csrf_token() }}';
    var routes = {
      generate: '{{ route('browsers-extension.key.regenerate') }}',
      revoke: '{{ route('browsers-extension.key.revoke') }}',
    };
    var initialState = @json($apiState);
    var initialNewKey = @json($newApiKey);

    var messageBox = document.getElementById('messageBox');
    var keyBadge = document.getElementById('keyBadge');
    var keyMask = document.getElementById('keyMask');
    var keyDisplayBox = document.getElementById('keyDisplayBox');
    var newKeyBox = document.getElementById('newKeyBox');
    var newKeyValue = document.getElementById('newKeyValue');
    var generateBtn = document.getElementById('generateBtn');
    var revokeBtn = document.getElementById('revokeBtn');

    function showMessage(type, message) {
      if (!message) { messageBox.innerHTML = ''; return; }
      var cls = type === 'error' ? 'msg err' : 'msg ok';
      messageBox.innerHTML = '<div class="' + cls + '">' + String(message) + '</div>';
    }

    function renderApiState(state) {
      var hasKey = Boolean(state && state.has_api_key);
      if (hasKey) {
        keyBadge.className = 'badge active';
        keyBadge.innerHTML = '<span class="badge-dot"></span>API Key Active';
        keyMask.textContent = 'lk_live_************************************' + (state.api_key_last4 || '');
        if (state.api_key_created_human) {
          keyMask.textContent += '  (Created ' + state.api_key_created_human + ')';
        }
        keyDisplayBox.style.borderColor = 'color-mix(in oklab, var(--ok) 35%, var(--line))';
        keyDisplayBox.style.background = 'color-mix(in oklab, var(--ok) 8%, var(--surface))';
      } else {
        keyBadge.className = 'badge inactive';
        keyBadge.innerHTML = '<span class="badge-dot"></span>No API Key';
        keyMask.textContent = 'Not generated yet. Click "Generate Key" to create one.';
        keyDisplayBox.style.borderColor = 'color-mix(in oklab, var(--a) 35%, var(--line))';
        keyDisplayBox.style.background = 'color-mix(in oklab, var(--a) 8%, var(--surface))';
      }
      revokeBtn.disabled = !hasKey;
    }

    function showNewKey(key) {
      if (!key) {
        newKeyBox.classList.remove('show');
        newKeyValue.textContent = '';
        return;
      }
      newKeyValue.textContent = String(key);
      newKeyBox.classList.add('show');
    }

    async function postJson(url) {
      var response = await fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      var data = await response.json().catch(function() { return {}; });
      if (!response.ok || (data && data.ok === false)) {
        var errors = data && data.errors;
        if (errors && typeof errors === 'object') {
          var first = Object.values(errors)[0];
          if (Array.isArray(first) && first[0]) {
            throw new Error(String(first[0]));
          }
        }
        throw new Error((data && data.message) || 'Request failed.');
      }
      return data;
    }

    async function generateKey() {
      generateBtn.disabled = true;
      showMessage('', '');
      try {
        var data = await postJson(routes.generate);
        renderApiState(data.api_state || {});
        showNewKey(data.new_api_key || '');
        showMessage('ok', data.message || 'API key generated successfully.');
      } catch (error) {
        showMessage('error', (error && error.message) || 'Could not generate API key.');
      } finally {
        generateBtn.disabled = false;
      }
    }

    async function revokeKey() {
      revokeBtn.disabled = true;
      showMessage('', '');
      try {
        var data = await postJson(routes.revoke);
        renderApiState(data.api_state || {});
        showNewKey('');
        showMessage('ok', data.message || 'API key revoked successfully.');
      } catch (error) {
        showMessage('error', (error && error.message) || 'Could not revoke API key.');
      } finally {
        revokeBtn.disabled = false;
      }
    }

    document.getElementById('copyKeyBtn').addEventListener('click', async function() {
      var raw = String(newKeyValue.textContent || '').trim();
      if (!raw) return;
      try {
        await navigator.clipboard.writeText(raw);
        showMessage('ok', 'API key copied to clipboard.');
      } catch (e) {
        showMessage('error', 'Copy failed. Please copy the key manually.');
      }
    });

    generateBtn.addEventListener('click', generateKey);
    revokeBtn.addEventListener('click', revokeKey);
    renderApiState(initialState || {});
    showNewKey(initialNewKey || '');

    var extensionsLink = document.getElementById('extensionsLink');
    var extensionsOpenHint = document.getElementById('extensionsOpenHint');
    extensionsLink.addEventListener('click', async function(event) {
      event.preventDefault();
      var target = 'chrome://extensions/';
      var opened = window.open(target, '_blank', 'noopener,noreferrer');
      if (opened) {
        if (extensionsOpenHint) extensionsOpenHint.style.display = 'none';
        return;
      }
      if (!extensionsOpenHint) return;
      try {
        await navigator.clipboard.writeText(target);
        extensionsOpenHint.textContent = 'Chrome blocked direct opening. We copied chrome://extensions/ to your clipboard.';
      } catch (err) {
        extensionsOpenHint.textContent = 'Chrome blocked direct opening. Copy chrome://extensions/ and paste it in the address bar.';
      }
      extensionsOpenHint.style.display = 'block';
    });
  </script>
</body>
</html>
