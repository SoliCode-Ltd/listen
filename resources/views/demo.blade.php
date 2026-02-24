<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Demo', 'page' => 'demo'])
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .25;
      animation: float 10s ease-in-out infinite;
    }
    .bg-orb.a {
      width: 16rem;
      height: 16rem;
      background: color-mix(in oklab, var(--a) 74%, white 26%);
      left: -4rem;
      top: 15vh;
    }
    .bg-orb.b {
      width: 20rem;
      height: 20rem;
      background: color-mix(in oklab, var(--b) 75%, white 25%);
      right: -4rem;
      bottom: 8vh;
      animation-delay: -3s;
    }

    .main-content {
      padding: 2.5rem 0 3rem;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 1.25rem;
      align-items: center;
    }
    .page-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .22s ease, border-color .22s ease;
    }
    .page-card:hover {
      transform: translateY(-3px);
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
    }
    .eyebrow {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .75rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
    }
    .page-card h1 {
      margin: .7rem 0 .7rem;
      font-size: clamp(1.8rem, 4vw, 2.8rem);
      line-height: 1.08;
      letter-spacing: -.02em;
    }
    .hero-list {
      display: grid;
      gap: .5rem;
      margin-top: .85rem;
      color: var(--muted);
      font-size: .95rem;
    }
    .sound-wave {
      margin-top: 1rem;
      display: flex;
      align-items: flex-end;
      gap: 4px;
      height: 36px;
    }
    .sound-wave span {
      display: block;
      width: 4px;
      border-radius: var(--radius-full);
      background: linear-gradient(180deg, var(--a), var(--b));
      animation: wavePulse 1s ease-in-out infinite;
      opacity: .9;
    }
    .sound-wave span:nth-child(2) { animation-delay: .08s; }
    .sound-wave span:nth-child(3) { animation-delay: .16s; }
    .sound-wave span:nth-child(4) { animation-delay: .24s; }
    .sound-wave span:nth-child(5) { animation-delay: .32s; }
    .sound-wave span:nth-child(6) { animation-delay: .40s; }
    .sound-wave span:nth-child(7) { animation-delay: .48s; }
    .sound-wave span:nth-child(8) { animation-delay: .56s; }
    .sound-wave span:nth-child(9) { animation-delay: .64s; }
    .sound-wave span:nth-child(10) { animation-delay: .72s; }
    .sound-wave span:nth-child(11) { animation-delay: .80s; }
    .sound-wave span:nth-child(12) { animation-delay: .88s; }
    .sound-wave.dynamic span { animation-duration: .75s; }
    @keyframes wavePulse {
      0%, 100% { height: 6px; }
      50% { height: 34px; }
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .7rem;
      margin-top: .85rem;
    }
    .stat {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem;
    }
    .stat small {
      color: var(--muted);
      font-size: .8rem;
    }
    .stat b {
      display: block;
      font-size: 1.1rem;
      margin-top: .2rem;
    }

    .lab {
      padding: 1rem 0 2.5rem;
    }
    .lab-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.25rem;
    }
    .form-grid {
      display: grid;
      gap: 1rem;
    }
    .form-grid > div {
      padding-inline: .1rem;
    }
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .8rem;
    }
    .demo-controls {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    label {
      display: block;
      margin: 0 0 .45rem;
      font-size: .92rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 88%, var(--muted));
    }
    input, select, textarea {
      width: 100%;
      padding: .9rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
    }
    textarea {
      min-height: 170px;
      resize: vertical;
    }

    .chips {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .chip {
      padding: .45rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface);
      font-size: .8rem;
      color: var(--muted);
      cursor: pointer;
      transition: all .2s ease;
    }
    .chip:hover {
      transform: translateY(-1px);
      border-color: color-mix(in oklab, var(--b) 45%, var(--line));
    }

    .hint {
        padding:0.5rem 0 0 0;
      font-size: .85rem;
      color: var(--muted);
    }
    .error-box {
      margin-top: .7rem;
      padding: .7rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, #ef4c78 48%, var(--line));
      background: color-mix(in oklab, #ef4c78 13%, var(--surface));
      color: color-mix(in oklab, #ef4c78 78%, var(--text));
      font-size: .9rem;
    }
    .quota {
      margin: .6rem .1rem .9rem;
      padding: .85rem .95rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: color-mix(in oklab, var(--surface) 84%, transparent);
    }
    .quota-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .7rem;
      margin-bottom: .5rem;
    }
    .quota-label {
      font-size: .85rem;
      color: var(--muted);
    }
    .quota-value {
      font-size: .9rem;
      font-weight: 700;
    }
    .quota-track {
      height: 10px;
      border-radius: var(--radius-full);
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
      background: color-mix(in oklab, var(--surface2) 85%, transparent);
      overflow: hidden;
    }
    .quota-fill {
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, var(--a), var(--b));
      transition: width .25s ease;
    }
    .quota-note {
      margin-top: .5rem;
      font-size: .82rem;
      color: var(--muted);
    }
    .quota-upgrade {
      display: none;
      margin-top: .7rem;
      padding: .85rem .95rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line));
      background: color-mix(in oklab, var(--a) 11%, var(--surface));
    }
    .quota-upgrade p {
      margin: 0 0 .6rem;
      color: var(--muted);
      font-size: .88rem;
    }

    .audio-box {
      margin-top: .8rem;
      padding: .8rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--surface);
    }
    .audio-box audio {
      width: 100%;
      height: 34px;
    }

    .reveal {
      opacity: 0;
      transform: translateY(12px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }

    @media (max-width: 960px) {
      .hero-grid,
      .lab-grid,
      .two-col,
      .demo-controls,
      .stats {
        grid-template-columns: 1fr;
      }
      .main-content {
        padding: 1.5rem 0 1rem;
      }
      .lab {
        padding: .6rem 0 1.8rem;
      }
      .page-card {
        padding: 1.1rem;
      }
      .sound-wave {
        margin-top: .8rem;
      }
    }
  </style>
  <script>
    (() => {
      const saved = localStorage.getItem('listen_theme');
      document.documentElement.setAttribute('data-theme', saved || document.documentElement.getAttribute('data-theme') || 'dark');
    })();
  </script>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container main-content">
    <section>
      <div class="hero-grid">
        <article class="page-card reveal">
          <!-- <span class="eyebrow">Live voice test studio</span> -->
          <h1>Choose speaker, voice language, and translation - then preview instantly.</h1>
          <p style="color: var(--muted); margin: 0;">This page is optimized for quick product validation. No clutter, only the controls that matter.</p>
          <div class="hero-list">
            <div>- Branded voice personas only</div>
            <div>- Clear translation behavior</div>
            <div>- Quality switch (Standard / HD / HD+)</div>
            <div>- Instant audio preview</div>
          </div>
          <div class="sound-wave" id="heroWave" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span><span></span>
          </div>
        </article>

        <aside class="page-card reveal">
          <h3 style="margin: .1rem 0 .5rem; font-size: 1.1rem;">Voice coverage</h3>
          <div class="stats">
            <div class="stat"><small>Arabic voices</small><b>{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['ar'] ?? [])) }}</b></div>
            <div class="stat"><small>English voices</small><b>{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['en'] ?? [])) }}</b></div>
            <div class="stat"><small>Arabic default</small><b>{{ $defaultVoiceAr['label'] ?? 'Sara' }}</b></div>
            <div class="stat"><small>English default</small><b>{{ $defaultVoiceEn['label'] ?? 'Emma' }}</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="lab">
      <div class="lab-grid">
        <section class="page-card reveal">
          <h3 style="margin: .1rem 0 .7rem; font-size: 1.1rem;">Try your best demo flow</h3>
          <p class="hint" style="margin: -.2rem 0 .5rem;">Guest demo includes one-time starter credit. Create a free account for full starter capacity.</p>
          <div class="quota" id="quotaBox">
            <div class="quota-head">
              <span class="quota-label">Guest starter allowance</span>
              <strong class="quota-value" id="quotaValue">
                {{ number_format((int) ($demoQuota['remaining_chars'] ?? $guestAllowanceChars)) }} / {{ number_format((int) ($demoQuota['limit_chars'] ?? $guestAllowanceChars)) }} chars left
              </strong>
            </div>
            <div class="quota-track">
              <div class="quota-fill" id="quotaFill"></div>
            </div>
            <div class="quota-note">One-time guest credit. Create a free account to unlock {{ number_format((int) $starterPlanChars) }} characters.</div>
          </div>
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">Text input</label>
              <textarea id="text" name="text" placeholder="Paste content to test..." required></textarea>
            </div>

            <div class="chips">
              <button class="chip" type="button" data-sample="Welcome to our platform. We build professional software with world-class quality.">English sample</button>
              <button class="chip" type="button" data-sample="مرحبًا بك في منصتنا. نحن نبني برمجيات احترافية بجودة عالمية.">Arabic sample</button>
            </div>

            <div class="two-col demo-controls">
              <div>
                <label for="voice_tier">Voice quality</label>
                <select id="voice_tier" name="voice_tier">
                  @foreach($demoTiers as $tier)
                    <option value="{{ $tier['key'] }}" {{ $tier['key'] === $defaultTier ? 'selected' : '' }}>
                      {{ $tier['label'] }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="target_language">Voice Language</label>
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
              <label for="voice_key">Speaker</label>
              <select id="voice_key" name="voice_key"></select>
            </div>

            <div>
              <div class="setting-card" style="margin-top: .5rem;">
                <div class="setting-info">
                  <label for="translateToggle">Translate before speaking</label>
                  <div class="hint" id="translateHint">Enabled: text is translated first, then spoken in the selected voice language.</div>
                </div>
                <label class="toggle-switch" aria-label="Translate before speaking">
                  <input type="checkbox" id="translateToggle" checked>
                  <span class="toggle-slider"></span>
                </label>
                <input type="hidden" name="translate" id="translate" value="1">
              </div>
            </div>

            <div style="display: flex; gap: .6rem; flex-wrap: wrap;">
              <button class="btn btn-primary" type="button" id="previewBtn">Generate Voice</button>
            </div>
          </form>

          <div id="errorBox" class="error-box" style="display: none;"></div>
          <div class="quota-upgrade" id="upgradeBox">
            <p id="upgradeText">Guest demo quota reached. Create a free account to unlock {{ number_format((int) $starterPlanChars) }} characters and continue instantly.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Create Free Account</a>
          </div>

          <div class="audio-box" id="audioWrap" style="display: none;">
            <audio id="previewAudio" controls preload="none"></audio>
            <div class="hint" id="audioMeta" style="margin-top: .55rem;"></div>
          </div>
        </section>
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script>
    const voiceCatalogByTier = @json($voiceCatalog);
    const form = document.getElementById('demoForm');
    const voiceTier = document.getElementById('voice_tier');
    const targetLanguage = document.getElementById('target_language');
    const voiceGender = document.getElementById('voice_gender');
    const voiceSelect = document.getElementById('voice_key');
    const previewBtn = document.getElementById('previewBtn');
    const audioWrap = document.getElementById('audioWrap');
    const previewAudio = document.getElementById('previewAudio');
    const audioMeta = document.getElementById('audioMeta');
    const errorBox = document.getElementById('errorBox');
    const quotaBox = document.getElementById('quotaBox');
    const quotaValue = document.getElementById('quotaValue');
    const quotaFill = document.getElementById('quotaFill');
    const upgradeBox = document.getElementById('upgradeBox');
    const upgradeText = document.getElementById('upgradeText');
    const translateSelect = document.getElementById('translate');
    const translateToggle = document.getElementById('translateToggle');
    const translateHint = document.getElementById('translateHint');
    const heroWave = document.getElementById('heroWave');
    const textInput = document.getElementById('text');
    const demoQuota = @json($demoQuota ?? null);
    const starterPlanChars = Number(@json((int) $starterPlanChars));

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    let currentQuota = {
      limit_chars: Number(demoQuota?.limit_chars || 300),
      remaining_chars: Number(demoQuota?.remaining_chars || 300),
      used_chars: Number(demoQuota?.used_chars || 0),
      is_exhausted: Boolean(demoQuota?.is_exhausted),
    };
    let preferredTranslateLanguage = targetLanguage?.value === 'en' ? 'en' : 'ar';
    const preferredVoiceKeyByLanguage = { ar: '', en: '' };
    let clientFingerprint = '';
    let fingerprintPromise = null;

    function formatNumber(value) {
      return new Intl.NumberFormat().format(Number(value || 0));
    }

    function countNonSpaceChars(value) {
      return String(value || '').replace(/\s+/g, '').length;
    }

    function normalizeLanguage(value) {
      return value === 'en' ? 'en' : 'ar';
    }

    function oppositeLanguage(value) {
      return normalizeLanguage(value) === 'en' ? 'ar' : 'en';
    }

    function detectSourceLanguage(value) {
      return /[\u0600-\u06FF]/u.test(String(value || '')) ? 'ar' : 'en';
    }

    function isTranslateEnabled() {
      return String(translateSelect?.value || '1') === '1';
    }

    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value)) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
    }

    function hideAudioPreview() {
      audioWrap.style.display = 'none';
      previewAudio.pause();
      previewAudio.removeAttribute('src');
      previewAudio.load();
      audioMeta.textContent = '';
    }

    function setError(message = '') {
      if (!message) {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
        return;
      }
      errorBox.style.display = 'block';
      errorBox.textContent = message;
    }

    function setUpgradeVisible(show, customMessage = '') {
      if (!upgradeBox) return;
      upgradeBox.style.display = show ? 'block' : 'none';
      if (show && upgradeText) {
        const fallback = `Guest demo quota reached. Create a free account to unlock ${formatNumber(starterPlanChars)} characters and continue instantly.`;
        upgradeText.textContent = customMessage || fallback;
      }
    }

    function renderQuota(quota) {
      if (!quota || typeof quota !== 'object') return;
      currentQuota = { ...currentQuota, ...quota };
      if (quotaValue) {
        quotaValue.textContent = `${formatNumber(currentQuota.remaining_chars)} / ${formatNumber(currentQuota.limit_chars)} chars left`;
      }
      if (quotaFill) {
        const remaining = Math.max(0, Number(currentQuota.remaining_chars || 0));
        const limit = Math.max(1, Number(currentQuota.limit_chars || 1));
        const percent = Math.max(0, Math.min(100, Math.round((remaining / limit) * 100)));
        quotaFill.style.width = `${percent}%`;
      }
      if (quotaBox) {
        const pending = countNonSpaceChars(textInput?.value || '');
        if (pending > 0) quotaBox.title = `${formatNumber(pending)} chars selected`;
      }
      setUpgradeVisible(Boolean(currentQuota.is_exhausted), `Guest demo quota reached (${formatNumber(currentQuota.limit_chars)} chars total). Create a free account to unlock ${formatNumber(starterPlanChars)} characters to start.`);
    }

    async function buildFingerprint() {
      const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
      const raw = [
        navigator.userAgent || '',
        navigator.language || '',
        Array.isArray(navigator.languages) ? navigator.languages.join(',') : '',
        navigator.platform || '',
        String(navigator.hardwareConcurrency || ''),
        String(navigator.maxTouchPoints || ''),
        timezone,
        `${screen.width}x${screen.height}`,
        String(screen.colorDepth || ''),
        String(window.devicePixelRatio || ''),
      ].join('|');
      if (!window.crypto?.subtle || !window.TextEncoder) return raw;
      const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
      return Array.from(new Uint8Array(digest)).map((v) => v.toString(16).padStart(2, '0')).join('');
    }

    async function ensureFingerprint() {
      if (clientFingerprint) return clientFingerprint;
      if (!fingerprintPromise) {
        fingerprintPromise = buildFingerprint()
          .then((fp) => {
            clientFingerprint = String(fp || '').slice(0, 256);
            if (clientFingerprint) {
              document.cookie = `listen_demo_fp=${encodeURIComponent(clientFingerprint)}; Max-Age=31536000; Path=/; SameSite=Lax`;
            }
            return clientFingerprint;
          })
          .catch(() => {
            clientFingerprint = '';
            return '';
          });
      }

      return fingerprintPromise;
    }

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1'
        ? 'Enabled: text is translated first, then spoken in the selected voice language.'
        : 'Disabled: original text is spoken directly without translation.';
    }

    function getFilteredVoices() {
      const tier = voiceTier?.value || 'standard';
      const lang = targetLanguage.value;
      const gender = voiceGender.value;
      const byTier = voiceCatalogByTier?.[tier] || {};
      const list = Array.isArray(byTier?.[lang]) ? byTier[lang] : [];
      const filtered = list.filter((voice) => gender === 'all' ? true : voice.gender === gender);
      return sortVoices(filtered);
    }

    function formatGender(value) {
      const text = String(value || '').trim().toLowerCase();
      if (!text) return 'Any';
      return text.charAt(0).toUpperCase() + text.slice(1);
    }

    function sortVoices(list) {
      const tierRank = { standard: 1, hd: 2, hd_plus: 3 };
      return [...list].sort((a, b) => {
        const aRank = tierRank[String(a?.tier || 'standard')] ?? 99;
        const bRank = tierRank[String(b?.tier || 'standard')] ?? 99;
        if (aRank !== bRank) return aRank - bRank;

        const byLabel = String(a?.label || '').localeCompare(String(b?.label || ''), undefined, { sensitivity: 'base' });
        if (byLabel !== 0) return byLabel;

        return String(a?.key || '').localeCompare(String(b?.key || ''), undefined, { sensitivity: 'base' });
      });
    }

    function renderVoiceOptions() {
      if (!voiceSelect || !targetLanguage) return;
      const voices = getFilteredVoices();
      const lang = normalizeLanguage(targetLanguage.value);
      const remembered = String(preferredVoiceKeyByLanguage[lang] || '').trim();
      const current = String(remembered || '').trim();
      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">No speakers available</option>';
        preferredVoiceKeyByLanguage[lang] = '';
        return;
      }
      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = `${voice.label} - ${formatGender(voice.gender)}`;
        voiceSelect.appendChild(option);
      }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
      preferredVoiceKeyByLanguage[lang] = String(voiceSelect.value || '').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !textInput || isTranslateEnabled()) return false;
      const text = String(textInput.value || '').trim();
      if (!text) {
        const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null;
        if (!fallback) return false;
        const currentLanguage = normalizeLanguage(targetLanguage.value);
        if (currentLanguage === fallback) return false;
        rememberCurrentVoiceSelection(currentLanguage);
        targetLanguage.value = fallback;
        renderVoiceOptions();
        return true;
      }
      const sourceLanguage = detectSourceLanguage(text);
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      if (sourceLanguage === currentLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = sourceLanguage;
      renderVoiceOptions();
      return true;
    }

    function restorePreferredTranslationLanguage() {
      if (!targetLanguage || !isTranslateEnabled()) return false;
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      const desiredLanguage = normalizeLanguage(preferredTranslateLanguage);
      if (currentLanguage === desiredLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = desiredLanguage;
      renderVoiceOptions();
      return true;
    }

    async function callEndpoint(endpoint) {
      setError('');
      setUpgradeVisible(false);
      const fd = new FormData(form);
      const headers = { ...csrfHeaders };
      await ensureFingerprint();
      if (clientFingerprint) headers['X-Listen-Fingerprint'] = clientFingerprint;
      const res = await fetch(endpoint, { method: 'POST', headers, body: fd });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok === false) {
        const firstError = data?.errors && typeof data.errors === 'object' ? Object.values(data.errors)[0] : null;
        const firstErrorText = Array.isArray(firstError) ? String(firstError[0] || '') : '';
        const error = new Error(String(data?.message || firstErrorText || 'Request failed'));
        error.payload = data;
        throw error;
      }
      return data;
    }

    previewBtn?.addEventListener('click', async () => {
      heroWave?.classList.add('dynamic');
      try {
        if (!isTranslateEnabled()) {
          syncLanguageToCurrentTextWhenTranslationOff();
        }

        const rawText = String(textInput?.value || '');
        const trimmed = rawText.trim();
        const selectedChars = countNonSpaceChars(trimmed);
        if (!trimmed) {
          hideAudioPreview();
          setError('The text field is required.');
          return;
        }
        if (selectedChars > Number(currentQuota.remaining_chars || 0)) {
          hideAudioPreview();
          const message = `You selected ${formatNumber(selectedChars)} characters, but only ${formatNumber(currentQuota.remaining_chars)} are left in guest demo credit.`;
          setError(message);
          setUpgradeVisible(true, `Create a free account to unlock ${formatNumber(starterPlanChars)} characters and continue.`);
          return;
        }
        previewBtn.disabled = true;
        previewBtn.textContent = 'Generating...';
        hideAudioPreview();
        const data = await callEndpoint('{{ route('demo.preview') }}');
        renderQuota(data?.quota || null);
        if (!data?.audio_url) {
          hideAudioPreview();
          setError('Voice generation did not return audio. Please try again.');
          return;
        }
        audioWrap.style.display = 'block';
        previewAudio.src = data.audio_url;
        previewAudio.load();
        const label = data?.voice?.label || 'Selected speaker';
        const gender = data?.voice?.gender ? ` (${formatGender(data.voice.gender)})` : '';
        const quality = data?.voice_tier_label ? ` - ${data.voice_tier_label}` : '';
        audioMeta.textContent = `Speaker: ${label}${gender}${quality} - ${data.translate_note}`;
      } catch (err) {
        hideAudioPreview();
        const message = String(err?.message || 'Preview failed. Try another text or voice.');
        if (err?.payload?.quota) {
          renderQuota(err.payload.quota);
        }
        setError(message);
        if (message.toLowerCase().includes('quota') || message.toLowerCase().includes('limit')) {
          setUpgradeVisible(true, `Create a free account to unlock ${formatNumber(starterPlanChars)} characters and continue.`);
          renderQuota({ ...currentQuota, is_exhausted: true, remaining_chars: 0 });
        }
      } finally {
        previewBtn.disabled = false;
        previewBtn.textContent = 'Generate Voice';
        setTimeout(() => heroWave?.classList.remove('dynamic'), 900);
      }
    });

    document.querySelectorAll('[data-sample]').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('text').value = btn.getAttribute('data-sample') || '';
      });
    });

    voiceTier?.addEventListener('change', renderVoiceOptions);
    targetLanguage?.addEventListener('change', () => {
      if (isTranslateEnabled()) {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage.value);
      }
      renderVoiceOptions();
    });
    voiceGender?.addEventListener('change', renderVoiceOptions);
    voiceSelect?.addEventListener('change', () => {
      rememberCurrentVoiceSelection();
    });
    translateToggle?.addEventListener('change', () => {
      if (translateSelect) translateSelect.value = translateToggle.checked ? '1' : '0';
      if (translateToggle.checked) {
        restorePreferredTranslationLanguage();
      } else {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage?.value);
        syncLanguageToCurrentTextWhenTranslationOff(oppositeLanguage(preferredTranslateLanguage));
      }
      updateTranslateHint();
    });
    textInput?.addEventListener('input', () => {
      if (currentQuota.remaining_chars <= 0) {
        setUpgradeVisible(true, `Create a free account to unlock ${formatNumber(starterPlanChars)} characters and continue.`);
      }
    });
    if (translateToggle && translateSelect) {
      translateToggle.checked = translateSelect.value === '1';
    }
    if (!isTranslateEnabled()) {
      preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    }
    renderVoiceOptions();
    updateTranslateHint();
    renderQuota(currentQuota);

    ensureFingerprint().catch(() => {});
  </script>
</body>
</html>
