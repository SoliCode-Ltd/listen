@include('components.page-head', ['pageTitle' => __('ui.seo.dashboard_title'), 'page' => 'dashboard'])
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
    .studio-panel {
      margin-top: .6rem;
      padding: .85rem;
      border: 1px solid color-mix(in oklab, var(--a) 25%, var(--line));
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--a) 5%, var(--surface));
    }
    .studio-label {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .88rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--a) 60%, var(--text));
      margin-bottom: .6rem;
    }
    .studio-section {
      padding: .65rem .75rem;
      border-radius: calc(var(--radius) - 2px);
      background: color-mix(in oklab, var(--a) 3%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 12%, var(--line));
    }
    .studio-section-label {
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .82rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--a) 50%, var(--text));
      margin-bottom: .45rem;
      letter-spacing: .01em;
    }
    .studio-panel input[type="range"] {
      -webkit-appearance: none;
      appearance: none;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--line);
      outline: none;
    }
    .studio-panel input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      cursor: pointer;
      border: 2px solid var(--surface);
      box-shadow: 0 0 4px rgba(0,0,0,.2);
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
    input[dir="auto"], textarea[dir="auto"] {
      unicode-bidi: plaintext;
      text-align: start;
    }
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--a);
      box-shadow: 0 0 0 3px var(--brand-accent-glow);
    }
    select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239fb0d4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.1em;
      padding-right: 2.8rem;
      cursor: pointer;
    }
    select:hover {
      border-color: color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .char-counter {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: .25rem;
      margin-top: .4rem;
      padding: .25rem .5rem;
      font-size: .8rem;
      color: var(--muted);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 50%, transparent);
    }
    .char-counter.warning {
      color: var(--warning, #f59e0b);
    }
    .char-counter.danger {
      color: var(--danger);
      font-weight: 600;
    }
    html[data-theme="light"] select {
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235b6c89' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
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
      padding: .85rem .65rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 65%, transparent);
      text-align: left;
      vertical-align: middle;
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
    .info-list {
      display: flex;
      flex-direction: column;
      gap: 0;
      margin-bottom: 1.25rem;
      background: color-mix(in oklab, var(--surface2) 30%, var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 0 .85rem;
    }
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .8rem 0;
      border-bottom: 1px dashed color-mix(in oklab, var(--line) 60%, transparent);
      font-size: .88rem;
    }
    .info-row:last-child {
      border-bottom: none;
    }
    .info-label {
      color: var(--muted);
    }
    .info-value {
      color: var(--text);
      font-weight: 600;
      text-align: right;
    }
    .sidebar-info hr {
      border: 0;
      border-top: 1px solid var(--line);
      margin: 1.25rem 0;
    }
    .sidebar-actions {
      display: flex;
      gap: .55rem;
      flex-wrap: wrap;
      margin-top: .85rem;
    }
    .sidebar-actions .btn {
      flex: 1;
      text-align: center;
      justify-content: center;
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
    .job-delete-btn {
      min-width: auto;
      height: 1.95rem;
      padding: 0 .7rem;
      font-size: .8rem;
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
      border-color: color-mix(in oklab, var(--danger) 35%, var(--line));
      background: color-mix(in oklab, var(--danger) 8%, var(--surface));
    }
    .job-delete-btn:hover:not(:disabled) {
      color: color-mix(in oklab, var(--danger) 92%, var(--text));
      border-color: color-mix(in oklab, var(--danger) 55%, var(--line));
      background: color-mix(in oklab, var(--danger) 12%, var(--surface));
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

    @keyframes rowSlideIn {
      from { opacity: 0; transform: translateY(-15px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowHighlight {
      0% { background-color: color-mix(in oklab, var(--a) 20%, transparent); }
      100% { background-color: transparent; }
    }
    .new-job-row {
      animation: rowSlideIn 1.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .new-job-row td {
      animation: rowHighlight 3.5s ease-out forwards;
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
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      return match ((string) $slug) {
        'free' => __('ui.pricing.plan_name_free'),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        default => (string) ($fallback ?: '-'),
      };
    };
    $statusLabelByKey = static function (?string $status): string {
      return match (strtolower((string) $status)) {
        'queued' => __('ui.dashboard.status_queued'),
        'processing' => __('ui.dashboard.status_processing'),
        'done' => __('ui.dashboard.status_done'),
        'failed' => __('ui.dashboard.status_failed'),
        default => strtoupper((string) $status),
      };
    };
    $languageLabelByCode = static function (?string $code): string {
      return match ((string) $code) {
        'ar' => __('ui.dashboard.arabic'),
        'en' => __('ui.dashboard.english'),
        default => (string) ($code ?: '-'),
      };
    };
    $processingPriorityLabel = static function (?string $value): string {
      return strtolower((string) $value) === 'high'
        ? __('ui.dashboard.priority_high')
        : __('ui.dashboard.priority_standard');
    };
    $cleanSpeakerLabel = static function (?string $value): string {
      return ltrim(trim((string) $value), '+');
    };
    $formatTierLabel = static function (?string $label): string {
      $text = trim((string) $label);
      if ($text !== '' && preg_match('/[A-Za-z0-9+]/', $text) && !preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return "\u{2066}{$text}\u{2069}";
      }
      return $text;
    };
    $activePlanDisplayName = $planNameBySlug($activePlan?->slug ?? 'free', $activePlan?->name ?? __('ui.dashboard.free_plan'));
    $speakerDisplayLabel = static function (?string $voiceName) use ($voiceNameMap, $cleanSpeakerLabel): string {
      $entry = $voiceNameMap[$voiceName] ?? null;
      if (! $entry) return $cleanSpeakerLabel(__('ui.dashboard.default_speaker'));
      $label = app()->getLocale() === 'ar'
        ? ($entry['label_ar'] ?? $entry['label'] ?? __('ui.dashboard.default_speaker'))
        : ($entry['label'] ?? __('ui.dashboard.default_speaker'));
      return $cleanSpeakerLabel($label);
    };
  @endphp
  @include('components.header')

  <main class="container main-content">
    <section>
      <div class="hero-grid">
        <article class="page-card reveal">
          <h1>{{ __('ui.dashboard.command_center') }}</h1>
          <p style="color: var(--muted); margin: 0;">{{ __('ui.dashboard.command_center_subtitle') }}</p>

          <div class="meter">
            <div class="meter-header">
              <small>{{ __('ui.dashboard.characters_usage', ['plan' => $activePlanDisplayName]) }}</small>
              <small id="usageCharsText">{{ number_format($usage->total_chars) }} / {{ number_format($planChars) }} {{ __('ui.dashboard.chars') }}</small>
            </div>
            <div class="meter-track"><div class="meter-fill" id="usageCharsMeter" style="width:{{ $usageCharsPercent }}%"></div></div>
          </div>

          <div class="meter">
            <div class="meter-header">
              <small>{{ __('ui.dashboard.requests_usage') }}</small>
              <small id="usageRequestsText">
                {{ $usage->total_requests }} /
                {{ $requestsUnlimited ? __('ui.dashboard.unlimited') : $planRequests }}
                {{ __('ui.dashboard.requests') }}
              </small>
            </div>
            <div class="meter-track"><div class="meter-fill requests" id="usageRequestsMeter" style="width:{{ $usageRequestsPercent }}%"></div></div>
          </div>

          <div class="kpis">
            <div class="kpi"><small>{{ __('ui.dashboard.requests') }}</small><b id="kpiRequests">{{ $usage->total_requests }}</b></div>
            <div class="kpi"><small>{{ __('ui.dashboard.characters') }}</small><b id="kpiChars">{{ number_format($usage->total_chars) }}</b></div>
            <div class="kpi"><small>{{ __('ui.dashboard.total_jobs') }}</small><b id="kpiTotal">{{ $stats['total'] }}</b></div>
          </div>
        </article>

        <aside class="page-card reveal">
          <h2>{{ __('ui.dashboard.system_health') }}</h2>
          <div class="mini">
            <div class="box"><small>{{ __('ui.dashboard.processing') }}</small><div><b id="statProcessing">{{ $stats['processing'] }}</b></div></div>
            <div class="box"><small>{{ __('ui.dashboard.done') }}</small><div><b id="statDone">{{ $stats['done'] }}</b></div></div>
            <div class="box"><small>{{ __('ui.dashboard.failed') }}</small><div><b id="statFailed">{{ $stats['failed'] }}</b></div></div>
          </div>
          <p style="color: var(--muted); margin-top: .85rem;">{{ __('ui.dashboard.voice_quality_on_account') }}: <strong>{{ $formatTierLabel($planTierLabel) }}</strong>.</p>
        </aside>
      </div>
    </section>

    <section class="main-grid" id="queue">
      <article class="page-card reveal">
        <h2>{{ __('ui.dashboard.generate_new_voice') }}</h2>
        @if(session('ok'))<div class="note">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        <div id="formMessage"></div>

        <form id="voiceForm" method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div>
            <label>{{ __('ui.dashboard.title_optional') }}</label>
            <input name="title" dir="auto" value="{{ old('title') }}" placeholder="{{ __('ui.dashboard.notification_summary') }}" />
          </div>

          <div>
            <label>{{ __('ui.dashboard.text_content') }}</label>
            <textarea name="content" id="contentTextarea" dir="auto" rows="8" required placeholder="{{ __('ui.dashboard.paste_text') }}">{{ old('content') }}</textarea>
            <div class="char-counter" id="charCounter">
              <span id="charCount">0</span> / <span id="charLimit">{{ number_format($maxCharsPerRequest) }}</span> {{ __('ui.dashboard.characters') }}
            </div>
          </div>

          <div class="two-col">
            <div>
              <label>{{ __('ui.dashboard.voice_language') }}</label>
              <select name="target_language" id="target_language">
                <option value="ar" {{ old('target_language', 'ar') === 'ar' ? 'selected' : '' }}>{{ __('ui.dashboard.arabic') }}</option>
                <option value="en" {{ old('target_language', 'ar') === 'en' ? 'selected' : '' }}>{{ __('ui.dashboard.english') }}</option>
              </select>
            </div>
            <div>
              <label>{{ __('ui.dashboard.speaker') }}</label>
              <select name="voice_key" id="voice_key"></select>
            </div>
          </div>

          {{-- Performance Controls --}}
          @if(($planTier ?? 'standard') === 'hd')
          <div class="studio-panel" id="studioPanel">
            <div class="studio-label">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              🎛️ {{ __('ui.dashboard.voice_studio', ['default' => 'Performance Controls']) }}
            </div>
            {{-- Performance Style Section --}}
            <div class="studio-section">
              <div class="studio-section-label">🎭 {{ __('ui.dashboard.performance_style', ['default' => 'Performance Style']) }}</div>
              <select name="performance_style" id="performance_style">
                <option value="">-- {{ __('ui.dashboard.choose_performance_style', ['default' => 'Choose Performance Style']) }} --</option>
                <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                <option value="educational">{{ __('ui.dashboard.style_educational', ['default' => 'Educational']) }}</option>
                <option value="commercial">{{ __('ui.dashboard.style_commercial', ['default' => 'Commercial']) }}</option>
                <option value="promo">{{ __('ui.dashboard.style_promo', ['default' => 'Promotional']) }}</option>
                <option value="podcast">{{ __('ui.dashboard.style_podcast', ['default' => 'Podcast']) }}</option>
                <option value="assistant">{{ __('ui.dashboard.style_assistant', ['default' => 'Virtual Assistant']) }}</option>
                <option value="narrative">{{ __('ui.dashboard.style_narrative', ['default' => 'Narrative']) }}</option>
                <option value="comedic">{{ __('ui.dashboard.style_comedic', ['default' => 'Comedic']) }}</option>
                <option value="corporate">{{ __('ui.dashboard.style_corporate', ['default' => 'Corporate']) }}</option>
                <option value="conversational">{{ __('ui.dashboard.style_conversational', ['default' => 'Conversational']) }}</option>
                <option value="inspirational">{{ __('ui.dashboard.style_inspirational', ['default' => 'Inspirational']) }}</option>
                <option value="cinematic">{{ __('ui.dashboard.style_cinematic', ['default' => 'Cinematic']) }}</option>
                <option value="documentary">{{ __('ui.dashboard.style_documentary', ['default' => 'Documentary']) }}</option>
                <option value="storytelling">{{ __('ui.dashboard.emotion_storytelling', ['default' => 'Storytelling']) }}</option>
                <option value="ad_style">{{ __('ui.dashboard.emotion_ad_style', ['default' => 'Ad Style']) }}</option>
                <option value="news">{{ __('ui.dashboard.emotion_news', ['default' => 'News']) }}</option>
              </select>
            </div>

            {{-- Emotion Section --}}
            <div class="studio-section" style="margin-top:.6rem;">
              <div class="studio-section-label">💫 {{ __('ui.dashboard.emotion_tone', ['default' => 'Emotion']) }}</div>
              <select name="emotion" id="emotion">
                <option value="">-- {{ __('ui.dashboard.choose_emotion', ['default' => 'Choose Emotion']) }} --</option>
                <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                <option value="warm">{{ __('ui.dashboard.emotion_warm', ['default' => 'Warm']) }}</option>
                <option value="sad">{{ __('ui.dashboard.emotion_sad', ['default' => 'Sad']) }}</option>
                <option value="excited">{{ __('ui.dashboard.emotion_excited', ['default' => 'Excited']) }}</option>
                <option value="formal">{{ __('ui.dashboard.emotion_formal', ['default' => 'Formal']) }}</option>
                <option value="calm">{{ __('ui.dashboard.emotion_calm', ['default' => 'Calm']) }}</option>
                <option value="angry">{{ __('ui.dashboard.emotion_angry', ['default' => 'Angry']) }}</option>
              </select>
            </div>
            <div style="margin-top: .4rem;">
              <div>
                <label for="dialect">{{ __('ui.dashboard.dialect', ['default' => 'Dialect']) }}</label>
                <select name="dialect" id="dialect">
                  <option value="">{{ __('ui.dashboard.dialect_auto', ['default' => 'Auto-detect']) }}</option>
                </select>
              </div>
            </div>
            <div class="two-col" style="margin-top: .4rem;">
              <div>
                <label for="speed">{{ __('ui.dashboard.speed', ['default' => 'Speed']) }} (<span id="speedVal">1.0</span>x)</label>
                <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="1.0" style="width:100%"
                  oninput="document.getElementById('speedVal').textContent=this.value">
              </div>
              <div>
                <label for="pitch">{{ __('ui.dashboard.pitch', ['default' => 'Pitch']) }} (<span id="pitchVal">0</span>)</label>
                <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="0" style="width:100%"
                  oninput="document.getElementById('pitchVal').textContent=this.value">
              </div>
            </div>
            @if($dialogueEnabled ?? false)
            <div style="margin-top: .5rem; border-top: 1px solid var(--line); padding-top: .5rem;">
              <div class="setting-card" style="margin-bottom: .4rem;">
                <div class="setting-info">
                  <label for="dialogueToggle">{{ __('ui.dashboard.dialogue_mode', ['default' => 'Dialogue Mode']) }}</label>
                  <div class="hint">{{ __('ui.dashboard.dialogue_hint', ['default' => 'Multi-speaker conversation (Pro plans only)']) }}</div>
                </div>
                <label class="toggle-switch" aria-label="{{ __('ui.dashboard.dialogue_mode', ['default' => 'Dialogue Mode']) }}">
                  <input type="checkbox" id="dialogueToggle">
                  <span class="toggle-slider"></span>
                </label>
                <input type="hidden" name="is_dialogue" id="dialogueSelect" value="0">
              </div>
            </div>
            @endif
          </div>
          @endif

          <div>
            <div class="setting-card" style="margin-top: .5rem;">
              <div class="setting-info">
                <label for="translateToggle">{{ __('ui.dashboard.translate_before_speaking') }}</label>
                <div class="hint" id="translateHint">{{ __('ui.dashboard.translate_enabled_hint') }}</div>
              </div>
              <label class="toggle-switch" aria-label="{{ __('ui.dashboard.translate_before_speaking') }}">
                <input type="checkbox" id="translateToggle" {{ old('translate') ? 'checked' : '' }}>
                <span class="toggle-slider"></span>
              </label>
              <input type="hidden" name="translate" id="translateSelect" value="{{ old('translate') ? '1' : '0' }}">
            </div>
          </div>

          <button class="btn btn-primary" id="generateBtn" type="submit">{{ __('ui.dashboard.generate_voice_now') }}</button>
        </form>
      </article>

      <aside class="page-card reveal sidebar-info">
        <h3 style="margin-top:0;">{{ __('ui.dashboard.plan_and_defaults') }}</h3>
        
        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.current_plan') }}</span><span class="info-value"><span class="status done">{{ $activePlanDisplayName }}</span></span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.voice_quality') }}</span><span class="info-value">{{ $formatTierLabel($planTierLabel) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.monthly_chars') }}</span><span class="info-value">{{ number_format($planChars) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.monthly_reqs') }}</span><span class="info-value">{{ $requestsUnlimited ? __('ui.dashboard.unlimited') : $planRequests }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.max_chars_per_req') }}</span><span class="info-value">{{ $activePlan?->max_chars_per_request ?? 600 }}</span></div>
        </div>

        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.pricing.topup_chars') }}</span><span class="info-value">{{ (($activePlan?->topup_char_price_cents ?? null) !== null || ($activePlan?->overage_price_per_million_cents ?? null) !== null) ? "\u{2066}$".number_format(((int) (($activePlan?->topup_char_price_cents ?? $activePlan?->overage_price_per_million_cents) ?? 0)) / 100, 0)."\u{2069}".__('ui.pricing.topup_per_million') : __('ui.upgrade.not_allowed') }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.commercial_use') }}</span><span class="info-value">{{ ($activePlan?->commercial_use_allowed ?? false) ? __('ui.dashboard.yes') : __('ui.dashboard.no') }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.processing') }}</span><span class="info-value">{{ $processingPriorityLabel((string) ($activePlan?->processing_priority ?? 'standard')) }}</span></div>
        </div>

        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.arabic_default') }}</span><span class="info-value">{{ app()->getLocale() === 'ar' ? (($voiceNameMap[$defaultVoiceAr] ?? [])['label_ar'] ?? ($voiceNameMap[$defaultVoiceAr] ?? [])['label'] ?? __('ui.dashboard.default')) : (($voiceNameMap[$defaultVoiceAr] ?? [])['label'] ?? __('ui.dashboard.default')) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.english_default') }}</span><span class="info-value">{{ app()->getLocale() === 'ar' ? (($voiceNameMap[$defaultVoiceEn] ?? [])['label_ar'] ?? ($voiceNameMap[$defaultVoiceEn] ?? [])['label'] ?? __('ui.dashboard.default')) : (($voiceNameMap[$defaultVoiceEn] ?? [])['label'] ?? __('ui.dashboard.default')) }}</span></div>
        </div>

        <div class="sidebar-actions">
          <a class="btn" href="{{ route('pricing') }}">{{ __('ui.dashboard.pricing_and_plans') }}</a>
          <a class="btn btn-primary" href="{{ route('billing.page') }}">{{ __('ui.dashboard.upgrade_plan') }}</a>
        </div>
        <div class="sidebar-actions" style="margin-top: .6rem;">
          <a class="btn" href="{{ route('manage-subscription.page') }}">{{ __('ui.dashboard.manage_subscription') }}</a>
          <a class="btn" href="{{ route('browsers-extension.page') }}">{{ __('ui.dashboard.browser_extension') }}</a>
        </div>
      </aside>
    </section>

    <section id="recentJobsSection" class="page-card table-card reveal">
      <div class="table-header">
        <h2>{{ __('ui.dashboard.recent_voices') }}</h2>
        <span class="jobs-count" id="jobsCount"></span>
      </div>
      <div style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th>{{ __('ui.dashboard.table_id') }}</th><th>{{ __('ui.dashboard.table_title') }}</th><th>{{ __('ui.dashboard.table_status') }}</th><th>{{ __('ui.dashboard.table_flow') }}</th><th>{{ __('ui.dashboard.table_speaker') }}</th><th>{{ __('ui.dashboard.table_translate') }}</th><th>{{ __('ui.dashboard.table_audio') }}</th><th>{{ __('ui.dashboard.table_created') }}</th><th>{{ __('ui.dashboard.table_actions') }}</th>
            </tr>
          </thead>
          <tbody id="jobsTableBody">
            @forelse($jobs as $job)
              <tr data-job-id="{{ $job->id }}" data-job-data="{{ json_encode([
                'id' => $job->id,
                'title' => $job->textItem?->title,
                'text_excerpt' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, ''),
                'status' => $job->status,
                'source_language' => $job->source_language,
                'target_language' => $job->target_language,
	                'voice_label' => $speakerDisplayLabel($job->voice_name),
                'voice_tier' => \App\Support\VoiceTier::label((string) ($job->voice_tier ?: \App\Support\VoiceTier::STANDARD)),
                'translate' => $job->translate,
                'duration_seconds' => $job->duration_seconds,
                'audio_url' => $job->audio_path ? \App\Support\AudioUrl::fromPath($job->audio_path) : null,
                'error_message' => $job->error_message,
                'created_human' => $job->created_at?->diffForHumans(),
              ]) }}">
                <td>#{{ $job->id }}</td>
                <td>{{ $job->textItem?->title ?: '-' }}</td>
                <td><span class="status {{ $job->status }}">{{ $statusLabelByKey($job->status) }}</span></td>
                <td>{{ $languageLabelByCode($job->source_language) }} -> {{ $languageLabelByCode($job->target_language) }}</td>
	                <td>{{ $speakerDisplayLabel($job->voice_name) }}</td>
	                <td>{{ $job->translate ? __('ui.dashboard.yes') : __('ui.dashboard.no') }}</td>
                <td>
                  @if($job->audio_path)
                    <audio
                      controls
                      preload="none"
                      src="{{ \App\Support\AudioUrl::fromPath($job->audio_path) }}"
                      data-download-language="{{ $job->target_language }}"
                      data-download-text="{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, '') }}"
	                      data-download-speaker="{{ $speakerDisplayLabel($job->voice_name) }}"
                      data-download-quality="{{ \App\Support\VoiceTier::label((string) ($job->voice_tier ?: \App\Support\VoiceTier::STANDARD)) }}"
                    ></audio>
                  @elseif($job->status === 'failed')
	                    <small style="color: var(--danger);">{{ $job->error_message ?: __('ui.dashboard.generation_failed') }}</small>
	                  @else
	                    <small style="color: var(--muted);">{{ __('ui.dashboard.processing_ellipsis') }}</small>
	                  @endif
                </td>
                <td>{{ $job->created_at?->diffForHumans() }}</td>
                <td>
                  <button
                    type="button"
                    class="pagination-btn job-delete-btn"
                    data-action="delete-job"
                    data-job-id="{{ $job->id }}"
                  >
	                    {{ __('ui.dashboard.delete') }}
	                  </button>
                </td>
              </tr>
            @empty
	              <tr id="jobsEmptyRow"><td colspan="9" style="color: var(--muted);">{{ __('ui.dashboard.no_jobs_yet') }}</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="pagination" id="jobsPagination"></div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $dashboardI18n = [
      'any' => __('ui.dashboard.any'),
      'no_speakers' => __('ui.dashboard.no_speakers'),
      'translate_enabled_hint' => __('ui.dashboard.translate_enabled_hint'),
      'translate_disabled_hint' => __('ui.dashboard.translate_disabled_hint'),
      'chars' => __('ui.dashboard.chars'),
      'requests' => __('ui.dashboard.requests'),
      'unlimited' => __('ui.dashboard.unlimited'),
      'status_queued' => __('ui.dashboard.status_queued'),
      'status_processing' => __('ui.dashboard.status_processing'),
      'status_done' => __('ui.dashboard.status_done'),
      'status_failed' => __('ui.dashboard.status_failed'),
      'default_speaker' => __('ui.dashboard.default_speaker'),
      'standard' => __('ui.dashboard.standard'),
      'priority_high' => __('ui.dashboard.priority_high'),
      'priority_standard' => __('ui.dashboard.priority_standard'),
      'preview' => __('ui.dashboard.preview'),
      'yes' => __('ui.dashboard.yes'),
      'no' => __('ui.dashboard.no'),
      'just_now' => __('ui.dashboard.just_now'),
      'generation_failed' => __('ui.dashboard.generation_failed'),
      'processing_ellipsis' => __('ui.dashboard.processing_ellipsis'),
      'delete' => __('ui.dashboard.delete'),
      'no_jobs_yet' => __('ui.dashboard.no_jobs_yet'),
      'showing_zero' => __('ui.dashboard.showing_zero'),
      'showing_range' => __('ui.dashboard.showing_range'),
      'voices' => __('ui.dashboard.voices'),
      'prev' => __('ui.dashboard.prev'),
      'next' => __('ui.dashboard.next'),
      'language_ar' => __('ui.dashboard.arabic'),
      'language_en' => __('ui.dashboard.english'),
      'confirm_delete' => __('ui.dashboard.confirm_delete'),
      'unable_delete_voice' => __('ui.dashboard.unable_delete_voice'),
      'voice_generation_failed' => __('ui.dashboard.voice_generation_failed'),
      'generate_voice_now' => __('ui.dashboard.generate_voice_now'),
      'generating' => __('ui.dashboard.generating'),
      'voice_generated_success' => __('ui.dashboard.voice_generated_success'),
    ];
  @endphp
  <script>
    const voiceCatalog = @json($voiceCatalog);
    const oldVoice = @json(old('voice_key'));
    const queueUrl = '{{ route('dashboard.queue') }}';
    const deleteJobBaseUrl = '{{ route('dashboard.jobs.destroy', ['job' => '__JOB_ID__']) }}'.replace('/__JOB_ID__', '');
    const csrfToken = '{{ csrf_token() }}';

    const voiceForm = document.getElementById('voiceForm');
    const formMessage = document.getElementById('formMessage');
    const generateBtn = document.getElementById('generateBtn');
    const targetLanguage = document.getElementById('target_language');
    const voiceSelect = document.getElementById('voice_key');
    const contentInput = document.getElementById('contentTextarea');
    const charCounter = document.getElementById('charCounter');
    const charCountEl = document.getElementById('charCount');
    const maxCharsPerRequest = {{ $maxCharsPerRequest }};
    const translateSelect = document.getElementById('translateSelect');
    const translateToggle = document.getElementById('translateToggle');
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
    const i18n = @json($dashboardI18n);
    const pageLocale = document.documentElement.lang || 'en';

    let preferredTranslateLanguage = targetLanguage?.value === 'en' ? 'en' : 'ar';
    const preferredVoiceKeyByLanguage = { ar: '', en: '' };
    const dialectSelect = document.getElementById('dialect');
    let allJobs = [];

    const dialectsByLanguage = {
      ar: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}' },
        { value: 'ar-XA', label: '{{ __("ui.dialects.msa", ["default" => "Modern Standard Arabic"]) }}' },
        { value: 'ar-SA', label: '{{ __("ui.dialects.saudi", ["default" => "Saudi"]) }}' },
        { value: 'ar-AE', label: '{{ __("ui.dialects.emirati", ["default" => "Emirati"]) }}' },
        { value: 'ar-EG', label: '{{ __("ui.dialects.egyptian", ["default" => "Egyptian"]) }}' },
        { value: 'ar-LB', label: '{{ __("ui.dialects.lebanese", ["default" => "Lebanese"]) }}' },
        { value: 'ar-SY', label: '{{ __("ui.dialects.syrian", ["default" => "Syrian"]) }}' },
        { value: 'ar-JO', label: '{{ __("ui.dialects.jordanian", ["default" => "Jordanian"]) }}' },
        { value: 'ar-IQ', label: '{{ __("ui.dialects.iraqi", ["default" => "Iraqi"]) }}' },
        { value: 'ar-MA', label: '{{ __("ui.dialects.moroccan", ["default" => "Moroccan"]) }}' },
        { value: 'ar-KW', label: '{{ __("ui.dialects.kuwaiti", ["default" => "Kuwaiti"]) }}' },
      ],
      en: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}' },
        { value: 'en-US', label: '{{ __("ui.dialects.american", ["default" => "American"]) }}' },
        { value: 'en-GB', label: '{{ __("ui.dialects.british", ["default" => "British"]) }}' },
        { value: 'en-AU', label: '{{ __("ui.dialects.australian", ["default" => "Australian"]) }}' },
        { value: 'en-IN', label: '{{ __("ui.dialects.indian", ["default" => "Indian"]) }}' },
        { value: 'en-ZA', label: '{{ __("ui.dialects.south_african", ["default" => "South African"]) }}' },
      ],
    };

    function renderDialectOptions() {
      if (!dialectSelect || !targetLanguage) return;
      const lang = normalizeLanguage(targetLanguage.value);
      const dialects = dialectsByLanguage[lang] || dialectsByLanguage['ar'];
      dialectSelect.innerHTML = '';
      for (const d of dialects) {
        const opt = document.createElement('option');
        opt.value = d.value;
        opt.textContent = d.label;
        dialectSelect.appendChild(opt);
      }
    }
    let jobsCurrentPage = 1;
    const jobsPerPage = 10;

    function formatNumber(value) {
      return new Intl.NumberFormat('en-US').format(Number(value || 0));
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

    function languageLabel(code) {
      const normalized = String(code || '').toLowerCase();
      if (normalized === 'ar') return i18n.language_ar;
      if (normalized === 'en') return i18n.language_en;
      return code || '-';
    }

    function statusLabel(status) {
      const normalized = String(status || '').toLowerCase();
      if (normalized === 'queued') return i18n.status_queued;
      if (normalized === 'processing') return i18n.status_processing;
      if (normalized === 'done') return i18n.status_done;
      if (normalized === 'failed') return i18n.status_failed;
      return String(status || '').toUpperCase();
    }

    function isTranslateEnabled() {
      return String(translateSelect?.value || '1') === '1';
    }

    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value)) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
    }

    function cleanSpeakerLabel(value) {
      return String(value || '')
        .replace(/^\++\s*/, '')
        .replace(/\b[a-z]{2}-[A-Z]{2}\b/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
    }

    function voiceDisplayLabel(voice) {
      const label = pageLocale === 'ar' && voice.label_ar ? voice.label_ar : voice.label;
      return cleanSpeakerLabel(label);
    }

    function formatTierLabel(value) {
      const text = String(value || '').trim();
      if (!text) return '';
      return `\u2066${text}\u2069`;
    }

    function sortVoices(list) {
      const tierRank = { standard: 1, hd: 2 };
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
      const lang = normalizeLanguage(targetLanguage?.value || 'ar');
      const raw = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      const voices = sortVoices(raw);
      const remembered = String(preferredVoiceKeyByLanguage[lang] || '').trim();
      const current = String(remembered || '').trim();
      if (!voiceSelect) return;
      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>';
        preferredVoiceKeyByLanguage[lang] = '';
        return;
      }
      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = voiceDisplayLabel(voice);
        voiceSelect.appendChild(option);
      }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
      preferredVoiceKeyByLanguage[lang] = String(voiceSelect.value || '').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !contentInput || isTranslateEnabled()) return false;
      const text = String(contentInput.value || '').trim();
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

    function updateTranslateHint() {
      if (!translateSelect || !translateHint) return;
      translateHint.textContent = translateSelect.value === '1'
        ? i18n.translate_enabled_hint
        : i18n.translate_disabled_hint;
    }

    function updateUsagePanel(usage) {
      if (!usage || typeof usage !== 'object') return;
      const requestsUnlimited = Boolean(usage.requests_unlimited);
      if (usageCharsText) usageCharsText.textContent = `${formatNumber(usage.total_chars)} / ${formatNumber(usage.plan_chars)} ${i18n.chars}`;
      if (usageRequestsText) usageRequestsText.textContent = requestsUnlimited
        ? `${formatNumber(usage.total_requests)} / ${i18n.unlimited} ${i18n.requests}`
        : `${formatNumber(usage.total_requests)} / ${formatNumber(usage.plan_requests)} ${i18n.requests}`;
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
      const statusText = statusLabel(statusClass);
      const title = escapeHtml(job?.title || '-');
      const sourceLanguageCode = String(job?.source_language || '-');
      const targetLanguageCode = String(job?.target_language || '-');
      const sourceLanguage = escapeHtml(languageLabel(sourceLanguageCode));
      const targetLanguageValue = escapeHtml(languageLabel(targetLanguageCode));
      const voiceLabelValue = cleanSpeakerLabel(job?.voice_label || i18n.default_speaker);
      const voiceLabel = escapeHtml(voiceLabelValue);
      const voiceTierValue = String(job?.voice_tier || i18n.standard).trim();
      const voiceTier = escapeHtml(voiceTierValue);
      const textExcerpt = escapeHtml(job?.text_excerpt || job?.title || i18n.preview);
      const translateLabel = job?.translate ? i18n.yes : i18n.no;
      const created = escapeHtml(job?.created_human || i18n.just_now);
      const errorMessage = escapeHtml(job?.error_message || i18n.generation_failed);
      const audioUrl = job?.audio_url ? escapeHtml(job.audio_url) : '';

      let audioCell = '<small style="color: var(--muted);">' + i18n.processing_ellipsis + '</small>';
      if (audioUrl) audioCell = '<audio controls preload="none" src="' + audioUrl + '" data-download-language="' + escapeHtml(targetLanguageCode) + '" data-download-text="' + textExcerpt + '" data-download-speaker="' + voiceLabel + '" data-download-quality="' + voiceTier + '"></audio>';
      else if (statusClass === 'failed') audioCell = '<small style="color: var(--danger);">' + errorMessage + '</small>';

      return '<tr data-job-id="' + Number(job?.id || 0) + '" data-job-data="' + escapeHtml(JSON.stringify(job)) + '">' +
        '<td>#' + Number(job?.id || 0) + '</td>' +
        '<td>' + title + '</td>' +
        '<td><span class="status ' + statusClass + '">' + statusText + '</span></td>' +
        '<td>' + sourceLanguage + ' -> ' + targetLanguageValue + '</td>' +
        '<td>' + voiceLabel + '</td>' +
        '<td>' + translateLabel + '</td>' +
        '<td>' + audioCell + '</td>' +
        '<td>' + created + '</td>' +
        '<td><button type="button" class="pagination-btn job-delete-btn" data-action="delete-job" data-job-id="' + Number(job?.id || 0) + '">' + i18n.delete + '</button></td>' +
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
      const totalLoaded = allJobs.length;
      const totalPages = Math.ceil(totalLoaded / jobsPerPage) || 1;
      const start = (jobsCurrentPage - 1) * jobsPerPage;
      const end = start + jobsPerPage;
      const pageJobs = allJobs.slice(start, end);

      jobsTableBody.innerHTML = '';
      if (pageJobs.length === 0) {
        jobsTableBody.innerHTML = '<tr id="jobsEmptyRow"><td colspan="9" style="color: var(--muted);">' + i18n.no_jobs_yet + '</td></tr>';
      } else {
        pageJobs.forEach(function(job) {
          jobsTableBody.insertAdjacentHTML('beforeend', renderJobRow(job));
        });
      }

      if (jobsCount) {
        if (totalLoaded === 0) {
          jobsCount.textContent = i18n.showing_zero;
        } else {
          const showingFrom = start + 1;
          const showingTo = Math.min(end, totalLoaded);
          jobsCount.textContent = i18n.showing_range
            .replace(':from', String(showingFrom))
            .replace(':to', String(showingTo))
            .replace(':total', formatNumber(totalLoaded))
            .replace(':label', i18n.voices);
        }
      }
      renderPagination(totalPages);
      if (window.initCustomAudioPlayers) window.initCustomAudioPlayers();
    }

    function renderPagination(totalPages) {
      if (!jobsPagination) return;
      if (totalPages <= 1) {
        jobsPagination.innerHTML = '';
        return;
      }

      let html = '';
      html += '<button type="button" class="pagination-btn" data-page="prev"' + (jobsCurrentPage === 1 ? ' disabled' : '') + '>' + i18n.prev + '</button>';

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

      html += '<button type="button" class="pagination-btn" data-page="next"' + (jobsCurrentPage === totalPages ? ' disabled' : '') + '>' + i18n.next + '</button>';
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
      let isNew = false;
      if (existingIndex !== -1) {
        allJobs[existingIndex] = job;
      } else {
        allJobs.unshift(job);
        isNew = true;
      }

      jobsCurrentPage = 1;
      renderJobsPage();

      if (isNew) {
        setTimeout(function() {
          const firstRow = jobsTableBody.querySelector('tr[data-job-id="' + job.id + '"]');
          if (firstRow) {
            firstRow.classList.add('new-job-row');
            firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }, 10);
      }
    }

    async function deleteJob(jobId, triggerBtn) {
      const parsedJobId = Number(jobId || 0);
      if (!parsedJobId) return;

      if (!window.confirm(i18n.confirm_delete)) {
        return;
      }

      if (triggerBtn instanceof HTMLButtonElement) {
        triggerBtn.disabled = true;
      }

      let data = null;
      try {
        const response = await fetch(`${deleteJobBaseUrl}/${parsedJobId}`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        data = await response.json().catch(() => ({}));
        if (!response.ok || data?.ok === false) {
          throw new Error(extractErrorMessage(data, i18n.unable_delete_voice));
        }

        const deletedId = Number(data?.deleted_id || parsedJobId);
        allJobs = allJobs.filter((job) => Number(job?.id || 0) !== deletedId);
        if (data?.stats) updateStats(data.stats);
        const totalPages = Math.ceil(allJobs.length / jobsPerPage) || 1;
        jobsCurrentPage = Math.min(jobsCurrentPage, totalPages);
        renderJobsPage();
        showFormMessage(null, '');
      } catch (error) {
        showFormMessage('error', extractErrorMessage(data, error?.message || i18n.unable_delete_voice));
      } finally {
        if (triggerBtn instanceof HTMLButtonElement) {
          triggerBtn.disabled = false;
        }
      }
    }

    jobsPagination?.addEventListener('click', function(e) {
      const btn = e.target.closest('.pagination-btn');
      if (!btn || btn.disabled) return;
      goToPage(btn.getAttribute('data-page'));
    });
    jobsTableBody?.addEventListener('click', function(e) {
      const deleteBtn = e.target.closest('[data-action="delete-job"]');
      if (!deleteBtn || deleteBtn.disabled) return;
      deleteJob(deleteBtn.getAttribute('data-job-id'), deleteBtn);
    });

    function extractErrorMessage(data, fallback = i18n.voice_generation_failed) {
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
      if (translateToggle && translateSelect) {
        translateSelect.value = translateToggle.checked ? '1' : '0';
      }
      if (!isTranslateEnabled()) {
        syncLanguageToCurrentTextWhenTranslationOff();
      }
      showFormMessage(null, '');
      const defaultText = generateBtn.textContent || i18n.generate_voice_now;
      generateBtn.disabled = true;
      generateBtn.textContent = i18n.generating;
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
        showFormMessage('ok', data?.message || i18n.voice_generated_success);
      } catch (error) {
        showFormMessage('error', extractErrorMessage(data, error?.message || i18n.voice_generation_failed));
      } finally {
        generateBtn.disabled = false;
        generateBtn.textContent = defaultText;
      }
    }

    targetLanguage?.addEventListener('change', function() {
      if (isTranslateEnabled()) {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage.value);
      }
      renderVoiceOptions();
      renderDialectOptions();
    });
    voiceSelect?.addEventListener('change', function() {
      rememberCurrentVoiceSelection();
    });
    translateToggle?.addEventListener('change', function() {
      if (translateSelect) translateSelect.value = translateToggle.checked ? '1' : '0';
      if (translateToggle.checked) {
        restorePreferredTranslationLanguage();
      } else {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage?.value);
        syncLanguageToCurrentTextWhenTranslationOff(oppositeLanguage(preferredTranslateLanguage));
      }
      updateTranslateHint();
    });
    voiceForm?.addEventListener('submit', submitVoiceForm);

    if (oldVoice) {
      preferredVoiceKeyByLanguage[normalizeLanguage(targetLanguage?.value)] = String(oldVoice || '').trim();
    }
    if (translateToggle && translateSelect) {
      translateToggle.checked = translateSelect.value === '1';
    }
    if (!isTranslateEnabled()) {
      preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    }
    renderVoiceOptions();
    renderDialectOptions();
    updateTranslateHint();
    loadJobsFromTable();
    renderJobsPage();

    // Dialogue toggle binding
    const dialogueToggle = document.getElementById('dialogueToggle');
    const dialogueSelect = document.getElementById('dialogueSelect');
    if (dialogueToggle && dialogueSelect) {
      dialogueToggle.addEventListener('change', function() {
        dialogueSelect.value = dialogueToggle.checked ? '1' : '0';
      });
    }

    // Live character counter
    function updateCharCounter() {
      if (!contentInput || !charCountEl || !charCounter) return;
      const length = contentInput.value.length; // includes spaces
      charCountEl.textContent = length.toLocaleString();
      charCounter.classList.remove('warning', 'danger');
      if (length > maxCharsPerRequest) {
        charCounter.classList.add('danger');
      } else if (length > maxCharsPerRequest * 0.9) {
        charCounter.classList.add('warning');
      }
    }
    if (contentInput) {
      contentInput.addEventListener('input', updateCharCounter);
      updateCharCounter(); // initial count for old('content')
    }
  </script>
</body>
</html>
