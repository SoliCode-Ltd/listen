<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Dashboard', 'page' => 'dashboard'])
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
  <style>
    .main-content {
      padding: 1.5rem 0 3rem;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.05fr .95fr;
      gap: 1rem;
    }
    .page-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
    }
    .page-card h1 {
      margin: 0 0 .5rem;
      font-size: 1.5rem;
    }
    .page-card h2 {
      margin: 0 0 .6rem;
      font-size: 1.1rem;
    }
    .page-card h3 {
      margin: .1rem 0 .45rem;
      font-size: 1.05rem;
    }

    .meter {
      margin-top: .8rem;
    }
    .meter-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: .4rem;
    }
    .meter-header small {
      color: var(--muted);
    }
    .meter-track {
      height: 12px;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface);
      overflow: hidden;
    }
    .meter-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--a), var(--b));
    }
    .meter-fill.requests {
      background: linear-gradient(90deg, var(--b), var(--a));
    }

    .kpis {
      margin-top: 1rem;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .65rem;
    }
    .kpi {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem;
    }
    .kpi small {
      color: var(--muted);
      font-size: .8rem;
    }
    .kpi b {
      display: block;
      font-size: 1.15rem;
      margin-top: .2rem;
    }

    .mini {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .65rem;
    }
    .mini .box {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem;
    }
    .mini .box small {
      color: var(--muted);
    }
    .mini .box b {
      display: block;
      margin-top: .2rem;
    }

    .main-grid {
      display: grid;
      grid-template-columns: 1.03fr .97fr;
      gap: 1rem;
      margin-top: 1.5rem;
    }
    .form-grid {
      display: grid;
      gap: .75rem;
    }
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .7rem;
    }
    label {
      display: block;
      font-size: .88rem;
      color: color-mix(in oklab, var(--text) 86%, var(--muted));
      margin-bottom: .35rem;
    }
    input, select, textarea {
      width: 100%;
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .9rem;
    }
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--a);
    }

    .note {
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--ok) 55%, var(--line));
      background: color-mix(in oklab, var(--ok) 14%, var(--surface));
      color: color-mix(in oklab, var(--ok) 80%, var(--text));
      margin: .5rem 0 .8rem;
      font-size: .9rem;
    }
    .error {
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--danger) 55%, var(--line));
      background: color-mix(in oklab, var(--danger) 13%, var(--surface));
      color: color-mix(in oklab, var(--danger) 80%, var(--text));
      margin: .5rem 0 .8rem;
      font-size: .9rem;
    }

     .table-card {
       margin-top: 1.5rem;
     }
     .table-header {
       display: flex;
       align-items: center;
       justify-content: space-between;
       gap: 1rem;
       margin-bottom: 1rem;
       flex-wrap: wrap;
     }
     .table-header h2 {
       margin: 0;
     }
     .jobs-count {
       font-size: .85rem;
       color: var(--muted);
     }
     table {
       width: 100%;
       border-collapse: collapse;
       font-size: .9rem;
     }
    th, td {
      padding: .65rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 65%, transparent);
      text-align: left;
      vertical-align: top;
    }
    th {
      color: color-mix(in oklab, var(--text) 85%, var(--muted));
      font-weight: 700;
    }
    .status {
      display: inline-block;
      padding: .25rem .5rem;
      border-radius: var(--radius-full);
      font-size: .75rem;
      border: 1px solid var(--line);
    }
    .status.queued { background: color-mix(in oklab, var(--a) 12%, var(--surface)); }
    .status.processing { background: color-mix(in oklab, var(--a) 12%, var(--surface)); }
    .status.done { background: color-mix(in oklab, var(--ok) 12%, var(--surface)); }
    .status.failed { background: color-mix(in oklab, var(--danger) 12%, var(--surface)); }
    audio {
      max-width: 230px;
      width: 230px;
      height: 32px;
    }

    .sidebar-info p {
      margin: 0 0 .45rem;
      font-size: .9rem;
      color: var(--muted);
    }
    .sidebar-info hr {
      border: 0;
      border-top: 1px solid var(--line);
      margin: 1rem 0;
    }
    .sidebar-actions {
      display: flex;
      gap: .55rem;
      flex-wrap: wrap;
      margin-top: .85rem;
    }

    .pagination {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .4rem;
      margin-top: 1.25rem;
      flex-wrap: wrap;
    }
    .pagination-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.2rem;
      height: 2.2rem;
      padding: 0 .5rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--muted);
      font-size: .85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s ease;
    }
    .pagination-btn:hover:not(:disabled) {
      color: var(--text);
      border-color: color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .pagination-btn:disabled {
      opacity: .4;
      cursor: not-allowed;
    }
    .pagination-btn.active {
      background: linear-gradient(135deg, var(--a), var(--b));
      border-color: transparent;
      color: #fff;
    }
    .pagination-info {
      font-size: .82rem;
      color: var(--muted);
      padding: 0 .5rem;
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

    @media (max-width: 980px) {
      .hero-grid,
      .main-grid,
      .kpis,
      .two-col,
      .mini {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1rem 0 2rem;
      }
      .page-card {
        padding: 1rem;
      }
      .page-card h1 {
        font-size: 1.3rem;
      }
      .meter-header {
        align-items: flex-start;
        flex-direction: column;
        gap: .2rem;
      }
      audio {
        width: 180px;
        max-width: 180px;
      }
      th, td {
        white-space: nowrap;
      }
      .sidebar-actions .btn {
        flex: 1 1 100%;
        justify-content: center;
      }
      .pagination {
        justify-content: flex-start;
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
  @include('components.header')

  <main class="container main-content">
    <section>
      <div class="hero-grid">
        <article class="page-card reveal">
          <h1>Command Center</h1>
          <p style="color: var(--muted); margin: 0;">Manage voice jobs, monitor usage, and keep delivery smooth.</p>

          <div class="meter">
            <div class="meter-header">
              <small>Characters usage ({{ $activePlan?->name ?? 'Free' }})</small>
              <small id="usageCharsText">{{ number_format($usage->total_chars) }} / {{ number_format($planChars) }} chars</small>
            </div>
            <div class="meter-track"><div class="meter-fill" id="usageCharsMeter" style="width:{{ $usageCharsPercent }}%"></div></div>
          </div>

          <div class="meter">
            <div class="meter-header">
              <small>Requests usage</small>
              <small id="usageRequestsText">
                {{ $usage->total_requests }} /
                {{ $requestsUnlimited ? 'Unlimited' : $planRequests }}
                requests
              </small>
            </div>
            <div class="meter-track"><div class="meter-fill requests" id="usageRequestsMeter" style="width:{{ $usageRequestsPercent }}%"></div></div>
          </div>

          <div class="kpis">
            <div class="kpi"><small>Requests</small><b id="kpiRequests">{{ $usage->total_requests }}</b></div>
            <div class="kpi"><small>Characters</small><b id="kpiChars">{{ number_format($usage->total_chars) }}</b></div>
            <div class="kpi"><small>Total Jobs</small><b id="kpiTotal">{{ $stats['total'] }}</b></div>
          </div>
        </article>

        <aside class="page-card reveal">
          <h2>System health</h2>
          <div class="mini">
            <div class="box"><small>Processing</small><div><b id="statProcessing">{{ $stats['processing'] }}</b></div></div>
            <div class="box"><small>Done</small><div><b id="statDone">{{ $stats['done'] }}</b></div></div>
            <div class="box"><small>Failed</small><div><b id="statFailed">{{ $stats['failed'] }}</b></div></div>
          </div>
          <p style="color: var(--muted); margin-top: .85rem;">Voice quality on this account: <strong>{{ $planTierLabel }}</strong>.</p>
        </aside>
      </div>
    </section>

    <section class="main-grid" id="queue">
      <article class="page-card reveal">
        <h2>Generate a new voice</h2>
        @if(session('ok'))<div class="note">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        <div id="formMessage"></div>

        <form id="voiceForm" method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div>
            <label>Title (optional)</label>
            <input name="title" value="{{ old('title') }}" placeholder="Notification summary" />
          </div>

          <div>
            <label>Text content</label>
            <textarea name="content" rows="8" required placeholder="Paste your text...">{{ old('content') }}</textarea>
          </div>

          <div class="two-col">
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
            <small style="color: var(--muted);" id="translateHint">Enabled: text is translated first, then spoken in target language.</small>
          </div>

          <button class="btn btn-primary" id="generateBtn" type="submit">Generate Voice Now</button>
        </form>
      </article>

      <aside class="page-card reveal sidebar-info">
        <h3>Plan + Voice Defaults</h3>
        <p>Current plan: {{ $activePlan?->name ?? 'Free' }}</p>
        <p>Voice quality: {{ $planTierLabel }}</p>
        <p>Monthly characters: {{ number_format($planChars) }}</p>
        <p>Monthly requests: {{ $requestsUnlimited ? 'Unlimited' : $planRequests }}</p>
        <p>Overage: {{ ($activePlan?->allow_overage ?? false) ? ('$'.number_format(((int) ($activePlan?->overage_price_per_million_cents ?? 0)) / 100, 2).' per 1,000,000 chars') : 'Not allowed' }}</p>
        <p>Commercial use: {{ ($activePlan?->commercial_use_allowed ?? false) ? 'Allowed' : 'Not allowed' }}</p>
        <p>Processing: {{ ucfirst((string) ($activePlan?->processing_priority ?? 'standard')) }} priority</p>
        <p>Max chars/request: {{ $activePlan?->max_chars_per_request ?? 600 }}</p>
        <hr>
        <p>Arabic default: {{ $voiceNameMap[$defaultVoiceAr]['label'] ?? 'Default' }}</p>
        <p>English default: {{ $voiceNameMap[$defaultVoiceEn]['label'] ?? 'Default' }}</p>
        <div class="sidebar-actions">
          <a class="btn" href="{{ route('pricing') }}">Pricing & Plans</a>
          <a class="btn" href="{{ route('billing.page') }}">Upgrade Plan</a>
          <a class="btn" href="{{ route('manage-subscription.page') }}">Manage Subscription</a>
          <a class="btn" href="{{ route('browsers-extension.page') }}">Browser Extension</a>
        </div>
      </aside>
    </section>

    <section class="page-card table-card reveal">
      <div class="table-header">
        <h2>Recent jobs</h2>
        <span class="jobs-count" id="jobsCount"></span>
      </div>
      <div style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Status</th><th>Flow</th><th>Voice</th><th>Translate</th><th>Est.</th><th>Audio</th><th>Created</th>
            </tr>
          </thead>
          <tbody id="jobsTableBody">
            @forelse($jobs as $job)
              <tr data-job-id="{{ $job->id }}" data-job-data="{{ json_encode([
                'id' => $job->id,
                'status' => $job->status,
                'source_language' => $job->source_language,
                'target_language' => $job->target_language,
                'voice_label' => $voiceNameMap[$job->voice_name]['label'] ?? 'Default voice',
                'translate' => $job->translate,
                'duration_seconds' => $job->duration_seconds,
                'audio_url' => $job->audio_path ? \App\Support\AudioUrl::fromPath($job->audio_path) : null,
                'error_message' => $job->error_message,
                'created_human' => $job->created_at?->diffForHumans(),
              ]) }}">
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
                    <small style="color: var(--danger);">{{ $job->error_message ?: 'Generation failed' }}</small>
                  @else
                    <small style="color: var(--muted);">Processing...</small>
                  @endif
                </td>
                <td>{{ $job->created_at?->diffForHumans() }}</td>
              </tr>
            @empty
              <tr id="jobsEmptyRow"><td colspan="8" style="color: var(--muted);">No jobs yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="pagination" id="jobsPagination"></div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
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
    const kpiTotal = document.getElementById('kpiTotal');
    const statProcessing = document.getElementById('statProcessing');
    const statDone = document.getElementById('statDone');
    const statFailed = document.getElementById('statFailed');
    const jobsCount = document.getElementById('jobsCount');
    const jobsPagination = document.getElementById('jobsPagination');

    let preferredVoiceKey = oldVoice || '';
    let allJobs = [];
    let jobsCurrentPage = 1;
    const jobsPerPage = 10;

    function formatNumber(value) {
      return new Intl.NumberFormat().format(Number(value || 0));
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
      if (!message) { formMessage.innerHTML = ''; return; }
      const cls = type === 'error' ? 'error' : 'note';
      formMessage.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;
    }

    function renderVoiceOptions() {
      const lang = targetLanguage?.value || 'ar';
      const raw = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      const voices = sortVoices(raw);
      const current = voiceSelect?.value || preferredVoiceKey || '';
      if (!voiceSelect) return;
      voiceSelect.innerHTML = '';
      if (!voices.length) { voiceSelect.innerHTML = '<option value="">No voices available</option>'; return; }
      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        const quality = String(voice?.tier_label || '').trim();
        option.textContent = quality !== ''
          ? `${voice.label} - ${formatGender(voice.gender)} - ${quality}`
          : `${voice.label} - ${formatGender(voice.gender)}`;
        voiceSelect.appendChild(option);
      }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
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
      if (usageCharsText) usageCharsText.textContent = `${formatNumber(usage.total_chars)} / ${formatNumber(usage.plan_chars)} chars`;
      if (usageRequestsText) usageRequestsText.textContent = requestsUnlimited
        ? `${formatNumber(usage.total_requests)} / Unlimited requests`
        : `${formatNumber(usage.total_requests)} / ${formatNumber(usage.plan_requests)} requests`;
      if (usageCharsMeter) usageCharsMeter.style.width = `${Number(usage.chars_percent || 0)}%`;
      if (usageRequestsMeter) usageRequestsMeter.style.width = `${Number(usage.requests_percent || 0)}%`;
      if (kpiRequests) kpiRequests.textContent = formatNumber(usage.total_requests);
      if (kpiChars) kpiChars.textContent = formatNumber(usage.total_chars);
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

      let audioCell = '<small style="color: var(--muted);">Processing...</small>';
      if (audioUrl) audioCell = '<audio controls preload="none" src="' + audioUrl + '"></audio>';
      else if (statusClass === 'failed') audioCell = '<small style="color: var(--danger);">' + errorMessage + '</small>';

      return '<tr data-job-id="' + Number(job?.id || 0) + '" data-job-data="' + escapeHtml(JSON.stringify(job)) + '">' +
        '<td>#' + Number(job?.id || 0) + '</td>' +
        '<td><span class="status ' + statusClass + '">' + statusLabel + '</span></td>' +
        '<td>' + sourceLanguage + ' -> ' + targetLanguageValue + '</td>' +
        '<td>' + voiceLabel + '</td>' +
        '<td>' + translateLabel + '</td>' +
        '<td>' + duration + '</td>' +
        '<td>' + audioCell + '</td>' +
        '<td>' + created + '</td>' +
      '</tr>';
    }

    function loadJobsFromTable() {
      const rows = jobsTableBody.querySelectorAll('tr[data-job-data]');
      allJobs = [];
      rows.forEach(function(row) {
        try {
          const data = JSON.parse(row.getAttribute('data-job-data'));
          if (data && data.id) allJobs.push(data);
        } catch (e) {}
      });
    }

    function renderJobsPage() {
      if (!jobsTableBody) return;
      const total = allJobs.length;
      const totalPages = Math.ceil(total / jobsPerPage) || 1;
      const start = (jobsCurrentPage - 1) * jobsPerPage;
      const end = start + jobsPerPage;
      const pageJobs = allJobs.slice(start, end);

      jobsTableBody.innerHTML = '';
      if (pageJobs.length === 0) {
        jobsTableBody.innerHTML = '<tr id="jobsEmptyRow"><td colspan="8" style="color: var(--muted);">No jobs yet.</td></tr>';
      } else {
        pageJobs.forEach(function(job) {
          jobsTableBody.insertAdjacentHTML('beforeend', renderJobRow(job));
        });
      }

      if (jobsCount) {
        jobsCount.textContent = 'Showing ' + (start + 1) + '-' + Math.min(end, total) + ' of ' + total + ' jobs';
      }
      renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
      if (!jobsPagination) return;
      if (totalPages <= 1) {
        jobsPagination.innerHTML = '';
        return;
      }

      let html = '';
      html += '<button type="button" class="pagination-btn" data-page="prev"' + (jobsCurrentPage === 1 ? ' disabled' : '') + '>Prev</button>';

      for (let i = 1; i <= totalPages; i++) {
        if (totalPages > 7) {
          if (i === 1 || i === totalPages || (i >= jobsCurrentPage - 1 && i <= jobsCurrentPage + 1)) {
            html += '<button type="button" class="pagination-btn' + (i === jobsCurrentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
          } else if (i === jobsCurrentPage - 2 || i === jobsCurrentPage + 2) {
            html += '<span class="pagination-info">...</span>';
          }
        } else {
          html += '<button type="button" class="pagination-btn' + (i === jobsCurrentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
      }

      html += '<button type="button" class="pagination-btn" data-page="next"' + (jobsCurrentPage === totalPages ? ' disabled' : '') + '>Next</button>';
      jobsPagination.innerHTML = html;
    }

    function goToPage(page) {
      const totalPages = Math.ceil(allJobs.length / jobsPerPage) || 1;
      if (page === 'prev') page = jobsCurrentPage - 1;
      if (page === 'next') page = jobsCurrentPage + 1;
      page = Number(page);
      if (isNaN(page) || page < 1 || page > totalPages || page === jobsCurrentPage) return;
      jobsCurrentPage = page;
      renderJobsPage();
    }

    function upsertJobRow(job) {
      if (!job || !job.id) return;
      const emptyRow = document.getElementById('jobsEmptyRow');
      if (emptyRow) emptyRow.remove();

      const existingIndex = allJobs.findIndex(function(j) { return j.id === job.id; });
      if (existingIndex !== -1) {
        allJobs[existingIndex] = job;
      } else {
        allJobs.unshift(job);
      }

      jobsCurrentPage = 1;
      renderJobsPage();
    }

    jobsPagination?.addEventListener('click', function(e) {
      const btn = e.target.closest('.pagination-btn');
      if (!btn || btn.disabled) return;
      goToPage(btn.getAttribute('data-page'));
    });

    function extractErrorMessage(data, fallback = 'Voice generation failed. Please try again.') {
      if (data?.message) return String(data.message);
      if (data?.error_message) return String(data.error_message);
      const errors = data?.errors;
      if (errors && typeof errors === 'object') {
        const keys = Object.keys(errors);
        if (keys.length > 0 && Array.isArray(errors[keys[0]]) && errors[keys[0]][0]) return String(errors[keys[0]][0]);
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
          headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(voiceForm),
        });
        data = await response.json().catch(() => ({}));
        if (data?.job) upsertJobRow(data.job);
        if (data?.usage) updateUsagePanel(data.usage);
        if (data?.stats) updateStats(data.stats);
        if (!response.ok || data?.ok === false) throw new Error(extractErrorMessage(data));
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
    voiceSelect?.addEventListener('change', function() { preferredVoiceKey = voiceSelect.value; });
    translateSelect?.addEventListener('change', updateTranslateHint);
    voiceForm?.addEventListener('submit', submitVoiceForm);
    renderVoiceOptions();
    updateTranslateHint();
    loadJobsFromTable();
    renderJobsPage();
  </script>
</body>
</html>
