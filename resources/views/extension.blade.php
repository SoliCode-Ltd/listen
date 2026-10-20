@include('components.page-head', ['pageTitle' => __('ui.seo.extension_title'), 'page' => 'extension'])
  <style>
    .hero {
      padding: 3rem 0 2rem;
      text-align: center;
    }
    .hero h1 {
      margin: 0 0 .75rem;
      font-size: var(--page-title-size);
      font-weight: 600;
      letter-spacing: -.03em;
    }
    html[dir="rtl"] .hero h1 { letter-spacing: 0; }
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
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      padding: 2rem 1.5rem;
      text-align: center;
      box-shadow: var(--card-shadow);
      transition: border-color .2s ease;
      position: relative;
      overflow: hidden;
    }
    .browser-card.available:hover {
      border-color: color-mix(in oklab, var(--text) 18%, var(--line));
    }
    .browser-card.coming-soon {
      opacity: .65;
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
      font-size: .95rem;
    }

    .coming-soon-badge {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--muted) 20%, var(--surface));
      color: var(--muted);
      font-size: .78rem;
      font-weight: 600;
    }
    .download-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 1.25rem;
      border-radius: var(--btn-radius);
      background: var(--brand-accent);
      box-shadow: var(--lift);
      color: #fff;
      font-weight: 600;
      font-size: .92rem;
      text-decoration: none;
      transition: background-color .2s ease;
      white-space: nowrap;
    }
    .download-btn:hover {
      background: var(--brand-hover);
    }
    .download-actions {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .55rem;
      flex-wrap: wrap;
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
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      padding: 1.75rem;
      box-shadow: var(--card-shadow);
    }
    .page-card h2 {
      margin: 0 0 .7rem;
      font-size: 1.2rem;
    }

    .steps {
      margin: 0;
      /* padding-inline-start: 1.3rem; */
      /* padding-left: 0; */
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
      background: var(--surface2);
      font-size: .85rem;
      color: var(--text);
      direction: ltr;
      unicode-bidi: isolate;
      display: inline-block;
    }

    .api-section {
      margin-top: 3rem;
      margin-bottom: 3rem;
    }
    .api-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      padding: 2rem;
      box-shadow: var(--card-shadow);
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
      border-radius: .75rem;
      background: var(--surface2);
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
      font-size: .95rem;
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
      border-radius: var(--card-radius);
      border: 1px solid var(--line);
      background: var(--surface);
      box-shadow: var(--card-shadow);
      text-align: center;
    }
    .feature-icon {
      width: 2.5rem;
      height: 2.5rem;
      margin: 0 auto .75rem;
      color: var(--brand-accent);
    }
    .feature-card h3 {
      margin: 0 0 .4rem;
      font-size: 1rem;
    }
    .feature-card p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
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

    html[dir="rtl"] .steps {
      /* padding-inline-start: 0; */
      /* padding-inline-end: 1.3rem; */
    }
    html[dir="rtl"] .download-actions {
      flex-direction: row-reverse;
    }
    html[dir="rtl"] .key-display-value,
    html[dir="rtl"] .new-key-box code {
      direction: ltr;
      unicode-bidi: isolate;
      text-align: left;
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
      document.documentElement.setAttribute('data-theme', saved || document.documentElement.getAttribute('data-theme') || 'dark');
    })();
  </script>
</head>
<body>
  @include('components.header')

  <main class="container">
    <section class="hero reveal">
      <h1>{{ $pageTitle }}</h1>
      <p>{{ $pageSubtitle }}</p>
    </section>

    @if(!empty($announcement))
    <div class="msg {{ $announcementType === 'warning' ? 'err' : 'ok' }}" style="margin-bottom: 1.5rem; text-align: center;">
      {{ $announcement }}
    </div>
    @endif

    <section class="browsers-grid">
      <article class="browser-card {{ $chromeEnabled ? 'available' : 'coming-soon' }} reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/chrome.png') }}" alt="{{ __('ui.extension.chrome_title') }}" />
        </div>
        <h3>{{ $chromeCardTitle }}</h3>
        <p>{{ $chromeCardSubtitle }}</p>
        @php
          $storeUrl = trim((string) ($chromeStoreUrl ?? ''));
          $showZipButton = $chromeEnabled && $downloadEnabled;
          $showStoreButton = $chromeEnabled && $chromeStoreEnabled && $storeUrl !== '';
        @endphp
        @if($showZipButton || $showStoreButton)
        <div class="download-actions">
          @if($showZipButton)
          <a class="download-btn" href="{{ route('browsers-extension.download', ['v' => $extensionVersion]) }}">
            {{ trim((string) ($chromeZipButtonText ?? '')) !== '' ? $chromeZipButtonText : __('ui.extension.zip_button') }}
          </a>
          @endif
          @if($showStoreButton)
          <a class="download-btn" href="{{ $storeUrl }}" target="_blank" rel="noopener noreferrer">
            {{ trim((string) ($chromeStoreButtonText ?? '')) !== '' ? $chromeStoreButtonText : __('ui.extension.store_button') }}
          </a>
          @endif
        </div>
        @else
        <span class="coming-soon-badge">{{ __('ui.extension.coming_soon') }}</span>
        @endif
      </article>

      <article class="browser-card {{ $firefoxEnabled ? 'available' : 'coming-soon' }} reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/firefox.png') }}" alt="{{ __('ui.extension.firefox_title') }}" />
        </div>
        <h3>{{ $firefoxCardTitle }}</h3>
        <p>{{ $firefoxCardSubtitle }}</p>
        @if($firefoxEnabled)
        <span class="coming-soon-badge">{{ __('ui.extension.available') }}</span>
        @else
        <span class="coming-soon-badge">{{ __('ui.extension.coming_soon') }}</span>
        @endif
      </article>

      <article class="browser-card {{ $safariEnabled ? 'available' : 'coming-soon' }} reveal">
        <div class="browser-icon">
          <img src="{{ asset('icons/safari.png') }}" alt="{{ __('ui.extension.safari_title') }}" />
        </div>
        <h3>{{ $safariCardTitle }}</h3>
        <p>{{ $safariCardSubtitle }}</p>
        @if($safariEnabled)
        <span class="coming-soon-badge">{{ __('ui.extension.available') }}</span>
        @else
        <span class="coming-soon-badge">{{ __('ui.extension.coming_soon') }}</span>
        @endif
      </article>
    </section>

    <section class="section reveal">
      <div class="two-col">
        <article class="page-card">
          <h2>{{ __('ui.extension.installation_guide') }}</h2>
          <ol class="steps">
            <li>{{ $installStep1 }}</li>
            <li>{!! str_contains($installStep2, 'chrome://extensions/') ? e(__('ui.extension.open_extensions')).' <a href="chrome://extensions/" id="extensionsLink" target="_blank" rel="noopener"><code>chrome://extensions/</code></a>' : e($installStep2) !!}</li>
            <li>{{ $installStep3 }}</li>
            <li>{{ $installStep4 }}</li>
            <li>{{ $installStep5 }}</li>
          </ol>
          <p id="extensionsOpenHint" style="display: none; margin-top: .75rem; color: var(--muted); font-size: .95rem;"></p>
        </article>

        <article class="page-card">
          <h2>{{ __('ui.extension.how_to_use') }}</h2>
          @php
            $apiKeyInline = __('ui.extension.api_key_inline');
            $apiKeyReplaced = str_replace(
              ['API key', $apiKeyInline],
              '<a href="#api-management" class="brand-text" style="text-decoration: underline; font-weight: 700;">'.e($apiKeyInline).'</a>',
              e($howToUseStep1),
            );
            $listenSelectedInline = __('ui.extension.listen_selected_text_inline');
            $listenSelectedReplaced = str_replace(
              ['Listen Selected Text', $listenSelectedInline],
              '<strong>'.e($listenSelectedInline).'</strong>',
              e($howToUseStep3),
            );
          @endphp
          <ol class="steps">
            <li>{!! $apiKeyReplaced !!}</li>
            <li>{{ $howToUseStep2 }}</li>
            <li>{!! $listenSelectedReplaced !!}</li>
            <li>{{ $howToUseStep4 }}</li>
            <li>{{ $howToUseStep5 }}</li>
          </ol>
        </article>
      </div>
    </section>

    <section class="features-row reveal">
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
        </svg>
        <h3>{{ $feature1Title }}</h3>
        <p>{{ $feature1Desc }}</p>
      </article>
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <h3>{{ $feature2Title }}</h3>
        <p>{{ $feature2Desc }}</p>
      </article>
      <article class="feature-card">
        <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12,6 12,12 16,14"/>
        </svg>
        <h3>{{ $feature3Title }}</h3>
        <p>{{ $feature3Desc }}</p>
      </article>
    </section>

    <section id="api-management" class="api-section reveal">
      <article class="api-card">
        <div class="api-header">
          <div>
            <h2>{{ __('ui.extension.api_key_management') }}</h2>
            <p>{{ __('ui.extension.api_key_management_subtitle') }}</p>
          </div>
          <div class="api-actions">
            <button class="btn btn-primary" id="generateBtn" type="button">{{ __('ui.extension.generate_key') }}</button>
            <button class="btn" id="revokeBtn" type="button">{{ __('ui.extension.revoke_key') }}</button>
          </div>
        </div>

        <div id="messageBox" role="status"></div>

        <div class="api-status">
          <span id="keyBadge" class="badge inactive"><span class="badge-dot"></span>{{ __('ui.extension.no_api_key') }}</span>
        </div>

        <div class="key-display" id="keyDisplayBox">
          <div class="key-display-label">{{ __('ui.extension.active_key') }}</div>
          <div class="key-display-value" id="keyMask">{{ __('ui.extension.not_generated') }}</div>
        </div>

        <div class="new-key-box" id="newKeyBox">
          <div class="new-key-box-label">{{ __('ui.extension.new_key_box') }}</div>
          <code id="newKeyValue"></code>
          <div class="copy-btn-wrap">
            <button class="btn" id="copyKeyBtn" type="button">{{ __('ui.extension.copy_to_clipboard') }}</button>
          </div>
        </div>
      </article>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.motion')
  @php
    $extensionI18n = [
      'api_key_active' => __('ui.extension.api_key_active'),
      'no_api_key' => __('ui.extension.no_api_key'),
      'not_generated' => __('ui.extension.not_generated'),
      'request_failed' => __('ui.extension.request_failed'),
      'generated_success' => __('ui.extension.generated_success'),
      'generate_failed' => __('ui.extension.generate_failed'),
      'revoked_success' => __('ui.extension.revoked_success'),
      'revoke_failed' => __('ui.extension.revoke_failed'),
      'revoke_confirm' => __('ui.extension.revoke_confirm'),
      'copied' => __('ui.extension.copied'),
      'copy_failed' => __('ui.extension.copy_failed'),
      'created_prefix' => __('ui.extension.created_prefix'),
      'extensions_hint_copied' => __('ui.extension.extensions_hint_copied'),
      'extensions_hint_manual' => __('ui.extension.extensions_hint_manual'),
    ];
  @endphp
  <script>
    var csrfToken = '{{ csrf_token() }}';
    var i18n = @json($extensionI18n);
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
          keyBadge.innerHTML = '<span class="badge-dot"></span>' + i18n.api_key_active;
          keyMask.textContent = 'lk_live_************************************' + (state.api_key_last4 || '');
          if (state.api_key_created_human) {
            keyMask.textContent += '  (' + i18n.created_prefix + ' ' + state.api_key_created_human + ')';
          }
          keyDisplayBox.style.borderColor = 'var(--line)';
          keyDisplayBox.style.background = 'var(--surface2)';
        } else {
          keyBadge.className = 'badge inactive';
          keyBadge.innerHTML = '<span class="badge-dot"></span>' + i18n.no_api_key;
          keyMask.textContent = i18n.not_generated;
          keyDisplayBox.style.borderColor = 'var(--line)';
          keyDisplayBox.style.background = 'var(--surface2)';
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
        throw new Error((data && data.message) || i18n.request_failed);
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
        showMessage('ok', data.message || i18n.generated_success);
      } catch (error) {
        showMessage('error', (error && error.message) || i18n.generate_failed);
      } finally {
        generateBtn.disabled = false;
      }
    }

    async function revokeKey() {
      // The extension stops working at once, so never on a stray click.
      if (!window.confirm(i18n.revoke_confirm)) return;
      revokeBtn.disabled = true;
      showMessage('', '');
      try {
        var data = await postJson(routes.revoke);
        renderApiState(data.api_state || {});
        showNewKey('');
        showMessage('ok', data.message || i18n.revoked_success);
      } catch (error) {
        showMessage('error', (error && error.message) || i18n.revoke_failed);
      } finally {
        revokeBtn.disabled = false;
      }
    }

    document.getElementById('copyKeyBtn').addEventListener('click', async function() {
      var raw = String(newKeyValue.textContent || '').trim();
      if (!raw) return;
      try {
        await navigator.clipboard.writeText(raw);
        showMessage('ok', i18n.copied);
      } catch (e) {
        showMessage('error', i18n.copy_failed);
      }
    });

    generateBtn.addEventListener('click', generateKey);
    revokeBtn.addEventListener('click', revokeKey);
    renderApiState(initialState || {});
    showNewKey(initialNewKey || '');

    var extensionsLink = document.getElementById('extensionsLink');
    var extensionsOpenHint = document.getElementById('extensionsOpenHint');
    if (extensionsLink) {
      extensionsLink.addEventListener('click', async function(event) {
        event.preventDefault();
        var target = 'chrome://extensions/';

        // Browsers block websites from navigating to chrome:// URLs for security.
        // The fallback is to copy it to their clipboard.
        if (extensionsOpenHint) {
          try {
            await navigator.clipboard.writeText(target);
            extensionsOpenHint.innerHTML = i18n.extensions_hint_copied;
          } catch (err) {
            extensionsOpenHint.innerHTML = i18n.extensions_hint_manual;
          }
          extensionsOpenHint.style.display = 'block';
        }
      });
    }
  </script>
</body>
</html>

