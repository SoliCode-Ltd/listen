<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Dashboard</title>
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

    .hero{padding:1.1rem 0}
    .hero-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:.8rem}
    .card{background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow);transition:transform .22s ease,border-color .22s ease}
    .card:hover{transform:translateY(-2px);border-color:color-mix(in oklab,var(--a) 40%,var(--line) 60%)}
    h1{margin:.2rem 0 .45rem;font-size:1.4rem}
    h2{margin:.2rem 0 .6rem;font-size:1.08rem}
    .muted{color:var(--muted)}

    .meter{margin-top:.65rem}
    .meter-track{height:12px;border-radius:999px;border:1px solid var(--line);background:var(--surface);overflow:hidden}
    .meter-fill{height:100%;background:linear-gradient(90deg,var(--a),var(--b))}
    .meter-fill.requests{background:linear-gradient(90deg,var(--b),var(--a))}

    .kpis{margin-top:.8rem;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.55rem}
    .kpi{background:var(--surface);border:1px solid var(--line);border-radius:.75rem;padding:.62rem}
    .kpi b{display:block;font-size:1.1rem}

    .mini{display:grid;grid-template-columns:1fr 1fr;gap:.55rem}
    .mini .box{background:var(--surface);border:1px solid var(--line);border-radius:.75rem;padding:.62rem}

    .main{display:grid;grid-template-columns:1.03fr .97fr;gap:.8rem;padding-bottom:1rem}
    .form-grid{display:grid;gap:.62rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
    label{font-size:.86rem;color:color-mix(in oklab,var(--text) 86%,var(--muted) 14%)}
    input,select,textarea{width:100%;padding:.7rem;border-radius:.68rem;border:1px solid var(--line);background:var(--surface);color:var(--text)}

    .note{padding:.62rem;border-radius:.68rem;border:1px solid color-mix(in oklab,var(--ok) 55%,var(--line) 45%);background:color-mix(in oklab,var(--ok) 14%,var(--surface) 86%);color:color-mix(in oklab,var(--ok) 80%,var(--text) 20%);margin:.45rem 0 .72rem}
    .error{padding:.62rem;border-radius:.68rem;border:1px solid color-mix(in oklab,var(--danger) 55%,var(--line) 45%);background:color-mix(in oklab,var(--danger) 13%,var(--surface) 87%);color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%);margin:.45rem 0 .72rem}

    .table-card{margin-bottom:1rem}
    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th,td{padding:.58rem;border-bottom:1px solid color-mix(in oklab,var(--line) 65%,transparent);text-align:left;vertical-align:top}
    th{color:color-mix(in oklab,var(--text) 85%,var(--muted) 15%);font-weight:700}
    .status{display:inline-block;padding:.2rem .46rem;border-radius:999px;font-size:.74rem;border:1px solid var(--line)}
    .status.queued{background:color-mix(in oklab,var(--a) 12%,var(--surface) 88%)}
    .status.processing{background:color-mix(in oklab,var(--a) 12%,var(--surface) 88%)}
    .status.done{background:color-mix(in oklab,var(--ok) 12%,var(--surface) 88%)}
    .status.failed{background:color-mix(in oklab,var(--danger) 12%,var(--surface) 88%)}
    audio{max-width:230px;width:230px;height:32px}

    .reveal{opacity:0;transform:translateY(12px);transition:opacity .55s ease, transform .55s ease}
    .reveal.show{opacity:1;transform:translateY(0)}

    .foot{border-top:1px solid var(--line);padding:1rem 0 1.8rem;color:var(--muted)}

    @media (max-width:980px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .hero-grid,.main,.kpis,.two,.mini{grid-template-columns:1fr}
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
        <a href="{{ route('extension.page') }}">Chrome Extension</a>
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
      <a href="{{ route('extension.page') }}">Chrome Extension</a>
      <form method="POST" action="{{ route('logout') }}" style="margin-top:.4rem">@csrf<button class="btn" type="submit">Logout</button></form>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <article class="card reveal">
          <h1>Command Center</h1>
          <p class="muted">Manage voice jobs, monitor usage, and keep delivery smooth.</p>

          <div class="meter">
            <div style="display:flex;justify-content:space-between;margin-bottom:.35rem">
              <small class="muted">Characters usage ({{ $activePlan?->name ?? 'Free' }})</small>
              <small id="usageCharsText">{{ number_format($usage->total_chars) }} / {{ number_format($planChars) }} chars</small>
            </div>
            <div class="meter-track"><div class="meter-fill" id="usageCharsMeter" style="width:{{ $usageCharsPercent }}%"></div></div>
          </div>

          <div class="meter">
            <div style="display:flex;justify-content:space-between;margin-bottom:.35rem">
              <small class="muted">Requests usage</small>
              <small id="usageRequestsText">
                {{ $usage->total_requests }} /
                {{ $requestsUnlimited ? 'Unlimited' : $planRequests }}
                requests
              </small>
            </div>
            <div class="meter-track"><div class="meter-fill requests" id="usageRequestsMeter" style="width:{{ $usageRequestsPercent }}%"></div></div>
          </div>

          <div class="kpis">
            <div class="kpi"><small class="muted">Requests</small><b id="kpiRequests">{{ $usage->total_requests }}</b></div>
            <div class="kpi"><small class="muted">Characters</small><b id="kpiChars">{{ number_format($usage->total_chars) }}</b></div>
            <div class="kpi"><small class="muted">Minutes</small><b id="kpiMinutes">{{ $usage->total_minutes }}</b></div>
            <div class="kpi"><small class="muted">Total Jobs</small><b id="kpiTotal">{{ $stats['total'] }}</b></div>
          </div>
        </article>

        <aside class="card reveal">
          <h2>System health</h2>
          <div class="mini">
            <div class="box"><small class="muted">Processing</small><div><b id="statProcessing">{{ $stats['processing'] }}</b></div></div>
            <div class="box"><small class="muted">Done</small><div><b id="statDone">{{ $stats['done'] }}</b></div></div>
            <div class="box"><small class="muted">Failed</small><div><b id="statFailed">{{ $stats['failed'] }}</b></div></div>
            <div class="box"><small class="muted">Voice Set</small><div><b>WaveNet Only</b></div></div>
          </div>
          <p class="muted" style="margin-top:.7rem">Arabic and English voices with male/female options are enabled.</p>
        </aside>
      </div>
    </section>

    <section class="main" id="queue">
      <article class="card reveal">
        <h2>Generate a new voice</h2>
        @if(session('ok'))<div class="note">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        <div id="formMessage"></div>

        <form id="voiceForm" method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div class="two">
            <div>
              <label>Title (optional)</label>
              <input name="title" value="{{ old('title') }}" placeholder="Notification summary" />
            </div>
            <div>
              <label>Source type</label>
              <select name="source_type">
                <option value="email">Email</option>
                <option value="message">Message</option>
                <option value="file">File</option>
              </select>
            </div>
          </div>

          <div>
            <label>Text content</label>
            <textarea name="content" rows="8" required placeholder="Paste your text...">{{ old('content') }}</textarea>
          </div>

          <div class="two">
            <div>
              <label>Target language</label>
              <select name="target_language" id="target_language">
                <option value="ar">Arabic</option>
                <option value="en">English</option>
              </select>
            </div>
            <div>
              <label>Voice</label>
              <select name="voice_key" id="voice_key"></select>
            </div>
          </div>

          <div>
            <label>Translate before speaking</label>
            <select name="translate" id="translateSelect">
              <option value="1">Yes - translate text first</option>
              <option value="0">No - keep text as-is</option>
            </select>
            <small class="muted" id="translateHint">Enabled: text is translated first, then spoken in target language.</small>
          </div>

          <button class="btn primary" id="generateBtn" type="submit">Generate Voice Now</button>
        </form>
      </article>

      <aside class="card reveal">
        <h3 style="margin:.1rem 0 .45rem">Plan + Voice Defaults</h3>
        <p class="muted">Current plan: {{ $activePlan?->name ?? 'Free' }}</p>
        <p class="muted">Monthly characters: {{ number_format($planChars) }}</p>
        <p class="muted">Monthly requests: {{ $requestsUnlimited ? 'Unlimited' : $planRequests }}</p>
        <p class="muted">Overage: {{ ($activePlan?->allow_overage ?? false) ? ('$'.number_format(((int) ($activePlan?->overage_price_per_million_cents ?? 0)) / 100, 2).' per 1,000,000 chars') : 'Not allowed' }}</p>
        <p class="muted">Commercial use: {{ ($activePlan?->commercial_use_allowed ?? false) ? 'Allowed' : 'Not allowed' }}</p>
        <p class="muted">Processing: {{ ucfirst((string) ($activePlan?->processing_priority ?? 'standard')) }} priority</p>
        <p class="muted">Max chars/request: {{ $activePlan?->max_chars_per_request ?? 600 }}</p>
        <hr style="border:0;border-top:1px solid var(--line);margin:.8rem 0">
        <p class="muted">Arabic default: {{ $voiceNameMap[$defaultVoiceAr]['label'] ?? 'Default' }}</p>
        <p class="muted">English default: {{ $voiceNameMap[$defaultVoiceEn]['label'] ?? 'Default' }}</p>
        <div style="margin-top:.7rem;display:flex;gap:.5rem;flex-wrap:wrap">
          <a class="btn" href="{{ route('extension.page') }}">Chrome Extension</a>
          <a class="btn" href="{{ route('pricing') }}">View Pricing</a>
        </div>
      </aside>
    </section>

    <section class="card table-card reveal">
      <h2>Recent jobs</h2>
      <div style="overflow:auto">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Status</th><th>Flow</th><th>Voice</th><th>Translate</th><th>Est.</th><th>Audio</th><th>Created</th>
            </tr>
          </thead>
          <tbody id="jobsTableBody">
            @forelse($jobs as $job)
              <tr data-job-id="{{ $job->id }}">
                <td>#{{ $job->id }}</td>
                <td><span class="status {{ $job->status }}">{{ strtoupper($job->status) }}</span></td>
                <td>{{ $job->source_language ?? '-' }} -> {{ $job->target_language }}</td>
                <td>{{ $voiceNameMap[$job->voice_name]['label'] ?? 'Default voice' }}</td>
                <td>{{ $job->translate ? 'Yes' : 'No' }}</td>
                <td>{{ $job->duration_seconds ? $job->duration_seconds.'s' : '-' }}</td>
                <td>
                  @if($job->audio_path)
                    <audio controls preload="none" src="{{ \App\Support\AudioUrl::fromPath($job->audio_path) }}"></audio>
                  @elseif($job->status === 'failed')
                    <small style="color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%)">{{ $job->error_message ?: 'Generation failed' }}</small>
                  @else
                    <small class="muted">Processing...</small>
                  @endif
                </td>
                <td>{{ $job->created_at?->diffForHumans() }}</td>
              </tr>
            @empty
              <tr id="jobsEmptyRow"><td colspan="8" class="muted">No jobs yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <footer class="foot"><div class="container">&copy; {{ date('Y') }} Listen. Crafted by SoliCode Ltd.</div></footer>

    <script>
    const voiceCatalog = @json($voiceCatalog);
    const oldVoice = @json(old('voice_key'));

    const queueUrl = '{{ route('dashboard.queue') }}';
    const csrfToken = '{{ csrf_token() }}';

    const voiceForm = document.getElementById('voiceForm');
    const formMessage = document.getElementById('formMessage');
    const generateBtn = document.getElementById('generateBtn');
    const targetLanguage = document.getElementById('target_language');
    const voiceSelect = document.getElementById('voice_key');
    const translateSelect = document.getElementById('translateSelect');
    const translateHint = document.getElementById('translateHint');
    const jobsTableBody = document.getElementById('jobsTableBody');

    const usageCharsText = document.getElementById('usageCharsText');
    const usageRequestsText = document.getElementById('usageRequestsText');
    const usageCharsMeter = document.getElementById('usageCharsMeter');
    const usageRequestsMeter = document.getElementById('usageRequestsMeter');

    const kpiRequests = document.getElementById('kpiRequests');
    const kpiChars = document.getElementById('kpiChars');
    const kpiMinutes = document.getElementById('kpiMinutes');
    const kpiTotal = document.getElementById('kpiTotal');

    const statProcessing = document.getElementById('statProcessing');
    const statDone = document.getElementById('statDone');
    const statFailed = document.getElementById('statFailed');

    let preferredVoiceKey = oldVoice || '';

    function formatNumber(value) {
      return new Intl.NumberFormat().format(Number(value || 0));
    }

    function formatGender(value) {
      const text = String(value || '').trim().toLowerCase();
      if (!text) return 'Any';
      return text.charAt(0).toUpperCase() + text.slice(1);
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function showFormMessage(type, message) {
      if (!formMessage) return;
      if (!message) {
        formMessage.innerHTML = '';
        return;
      }

      const cls = type === 'error' ? 'error' : 'note';
      formMessage.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;
    }

    function renderVoiceOptions() {
      const lang = targetLanguage?.value || 'ar';
      const voices = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      const current = voiceSelect?.value || preferredVoiceKey || '';

      if (!voiceSelect) return;
      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">No voices available</option>';
        return;
      }

      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = `${voice.label} - ${formatGender(voice.gender)}`;
        voiceSelect.appendChild(option);
      }

      if (voices.some((v) => v.key === current)) {
        voiceSelect.value = current;
      } else {
        voiceSelect.value = voices[0].key;
      }

      preferredVoiceKey = voiceSelect.value;
    }

    function updateTranslateHint() {
      if (!translateSelect || !translateHint) return;
      translateHint.textContent = translateSelect.value === '1'
        ? 'Enabled: text is translated first, then spoken in target language.'
        : 'Disabled: original text is spoken directly without translation.';
    }

    function updateUsagePanel(usage) {
      if (!usage || typeof usage !== 'object') return;
      const requestsUnlimited = Boolean(usage.requests_unlimited);

      if (usageCharsText) {
        usageCharsText.textContent = `${formatNumber(usage.total_chars)} / ${formatNumber(usage.plan_chars)} chars`;
      }
      if (usageRequestsText) {
        usageRequestsText.textContent = requestsUnlimited
          ? `${formatNumber(usage.total_requests)} / Unlimited requests`
          : `${formatNumber(usage.total_requests)} / ${formatNumber(usage.plan_requests)} requests`;
      }
      if (usageCharsMeter) {
        usageCharsMeter.style.width = `${Number(usage.chars_percent || 0)}%`;
      }
      if (usageRequestsMeter) {
        usageRequestsMeter.style.width = requestsUnlimited
          ? '100%'
          : `${Number(usage.requests_percent || 0)}%`;
      }

      if (kpiRequests) kpiRequests.textContent = formatNumber(usage.total_requests);
      if (kpiChars) kpiChars.textContent = formatNumber(usage.total_chars);
      if (kpiMinutes) kpiMinutes.textContent = formatNumber(usage.total_minutes);
    }

    function updateStats(stats) {
      if (!stats || typeof stats !== 'object') return;
      if (statProcessing) statProcessing.textContent = formatNumber(stats.processing);
      if (statDone) statDone.textContent = formatNumber(stats.done);
      if (statFailed) statFailed.textContent = formatNumber(stats.failed);
      if (kpiTotal) kpiTotal.textContent = formatNumber(stats.total);
    }

    function renderJobRow(job) {
      const status = String(job?.status || 'processing').toLowerCase();
      const statusClass = ['queued', 'processing', 'done', 'failed'].includes(status) ? status : 'processing';
      const statusLabel = statusClass.toUpperCase();
      const sourceLanguage = escapeHtml(job?.source_language || '-');
      const targetLanguageValue = escapeHtml(job?.target_language || '-');
      const voiceLabel = escapeHtml(job?.voice_label || 'Default voice');
      const translateLabel = job?.translate ? 'Yes' : 'No';
      const duration = job?.duration_seconds ? `${Number(job.duration_seconds)}s` : '-';
      const created = escapeHtml(job?.created_human || 'just now');
      const errorMessage = escapeHtml(job?.error_message || 'Generation failed');
      const audioUrl = job?.audio_url ? escapeHtml(job.audio_url) : '';

      let audioCell = '<small class="muted">Processing...</small>';
      if (audioUrl) {
        audioCell = `<audio controls preload="none" src="${audioUrl}"></audio>`;
      } else if (statusClass === 'failed') {
        audioCell = `<small style="color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%)">${errorMessage}</small>`;
      }

      return `
        <tr data-job-id="${Number(job?.id || 0)}">
          <td>#${Number(job?.id || 0)}</td>
          <td><span class="status ${statusClass}">${statusLabel}</span></td>
          <td>${sourceLanguage} -> ${targetLanguageValue}</td>
          <td>${voiceLabel}</td>
          <td>${translateLabel}</td>
          <td>${duration}</td>
          <td>${audioCell}</td>
          <td>${created}</td>
        </tr>
      `;
    }

    function upsertJobRow(job) {
      if (!jobsTableBody || !job || !job.id) return;

      const emptyRow = document.getElementById('jobsEmptyRow');
      if (emptyRow) emptyRow.remove();

      const rowHtml = renderJobRow(job);
      const existing = jobsTableBody.querySelector(`tr[data-job-id="${Number(job.id)}"]`);
      if (existing) {
        existing.outerHTML = rowHtml;
      } else {
        jobsTableBody.insertAdjacentHTML('afterbegin', rowHtml);
      }
    }

    function extractErrorMessage(data, fallback = 'Voice generation failed. Please try again.') {
      if (data?.message) return String(data.message);
      if (data?.error_message) return String(data.error_message);

      const errors = data?.errors;
      if (errors && typeof errors === 'object') {
        const keys = Object.keys(errors);
        if (keys.length > 0 && Array.isArray(errors[keys[0]]) && errors[keys[0]][0]) {
          return String(errors[keys[0]][0]);
        }
      }

      return fallback;
    }

    async function submitVoiceForm(event) {
      event.preventDefault();
      if (!voiceForm || !generateBtn) return;

      showFormMessage(null, '');
      const defaultText = generateBtn.textContent || 'Generate Voice Now';
      generateBtn.disabled = true;
      generateBtn.textContent = 'Generating...';

      let data = null;

      try {
        const response = await fetch(queueUrl, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: new FormData(voiceForm),
        });

        data = await response.json().catch(() => ({}));

        if (data?.job) {
          upsertJobRow(data.job);
        }
        if (data?.usage) {
          updateUsagePanel(data.usage);
        }
        if (data?.stats) {
          updateStats(data.stats);
        }

        if (!response.ok || data?.ok === false) {
          throw new Error(extractErrorMessage(data));
        }

        showFormMessage('ok', data?.message || 'Voice generated successfully.');

        const titleField = voiceForm.querySelector('input[name="title"]');
        const contentField = voiceForm.querySelector('textarea[name="content"]');
        if (titleField) titleField.value = '';
        if (contentField) contentField.value = '';
      } catch (error) {
        showFormMessage('error', extractErrorMessage(data, error?.message || 'Voice generation failed.'));
      } finally {
        generateBtn.disabled = false;
        generateBtn.textContent = defaultText;
      }
    }

    targetLanguage?.addEventListener('change', renderVoiceOptions);
    voiceSelect?.addEventListener('change', () => {
      preferredVoiceKey = voiceSelect.value;
    });
    translateSelect?.addEventListener('change', updateTranslateHint);
    voiceForm?.addEventListener('submit', submitVoiceForm);

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

