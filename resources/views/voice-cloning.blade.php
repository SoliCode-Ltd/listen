@include('components.page-head', ['pageTitle' => __('ui.voice_cloning.seo_title'), 'page' => 'voice-cloning'])
  <style>
    .bg-orb{position:fixed;z-index:-1;pointer-events:none;border-radius:999px;filter:blur(60px);opacity:.18;animation:float 12s ease-in-out infinite}
    .bg-orb.a{width:22rem;height:22rem;background:color-mix(in oklab,var(--a) 60%,transparent);left:-6rem;top:12vh}
    .bg-orb.b{width:26rem;height:26rem;background:color-mix(in oklab,var(--b) 55%,transparent);right:-6rem;bottom:6vh;animation-delay:-4s}
    @keyframes float{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-18px) scale(1.03)}}

    .vc-main{padding:1.5rem 0 3rem}
    .vc-hero{text-align:center;margin-bottom:2rem}
    .vc-hero h1{margin:0 0 .5rem;font-size:clamp(1.8rem,4vw,2.6rem);font-weight:800}
    .vc-hero p{margin:0;color:var(--muted);font-size:1.05rem}

    /* Upgrade state */
    .vc-upgrade{max-width:580px;margin:2rem auto;text-align:center;padding:2.5rem 2rem;background:linear-gradient(170deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow)}
    .vc-upgrade .icon-lock{width:56px;height:56px;margin:0 auto 1rem;color:color-mix(in oklab,var(--a) 70%,var(--muted));opacity:.7}
    .vc-upgrade h2{margin:0 0 .5rem;font-size:1.5rem}
    .vc-upgrade p{color:var(--muted);margin:0 0 1.2rem}
    .vc-upgrade .features-list{list-style:none;padding:0;margin:0 0 1.5rem;text-align:start;display:inline-block}
    .vc-upgrade .features-list li{padding:.35rem 0;display:flex;align-items:center;gap:.5rem;font-size:.93rem}
    .vc-upgrade .features-list li svg{flex-shrink:0;width:18px;height:18px;color:var(--a)}

    /* Cards grid */
    .vc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:2rem}
    .vc-card{position:relative;background:linear-gradient(170deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:var(--radius-lg);padding:1.3rem;box-shadow:var(--shadow);transition:border-color .2s}
    .vc-card:hover{border-color:color-mix(in oklab,var(--a) 40%,var(--line))}
    .vc-card-header{display:flex;justify-content:space-between;align-items:start;gap:.5rem;margin-bottom:.8rem}
    .vc-card-name{font-size:1.1rem;font-weight:700;margin:0}
    .vc-card-meta{font-size:.82rem;color:var(--muted);display:flex;flex-direction:column;gap:.25rem;margin-bottom:.8rem}
    .vc-status{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:var(--radius-full);font-size:.78rem;font-weight:600}
    .vc-status.ready{background:color-mix(in oklab,#22c55e 15%,var(--surface));color:#22c55e;border:1px solid color-mix(in oklab,#22c55e 30%,var(--line))}
    .vc-status.processing,.vc-status.pending,.vc-status.validating{background:color-mix(in oklab,var(--a) 12%,var(--surface));color:var(--a);border:1px solid color-mix(in oklab,var(--a) 30%,var(--line))}
    .vc-status.failed{background:color-mix(in oklab,#ef4444 15%,var(--surface));color:#ef4444;border:1px solid color-mix(in oklab,#ef4444 30%,var(--line))}
    .vc-card-actions{display:flex;gap:.5rem;flex-wrap:wrap}
    .vc-card-actions .btn{font-size:.82rem;padding:.4rem .8rem}
    @keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
    .vc-status .dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:pulse-dot 1.5s ease infinite}

    /* Create form panel */
    .vc-create-panel{max-width:680px;margin:0 auto;background:linear-gradient(170deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:var(--radius-lg);padding:1.8rem;box-shadow:var(--shadow)}
    .vc-create-panel h2{margin:0 0 1.2rem;font-size:1.3rem}
    .vc-form-group{margin-bottom:1.2rem}
    .vc-form-group label{display:block;font-weight:600;font-size:.9rem;margin-bottom:.4rem}
    .vc-form-group input[type=text],.vc-form-group select{width:100%;padding:.65rem .85rem;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);color:var(--text);font-size:.92rem;font-family:inherit;transition:border-color .2s}
    .vc-form-group input[type=text]:focus,.vc-form-group select:focus{outline:none;border-color:var(--a)}

    /* Tabs */
    .vc-tabs{display:flex;gap:.5rem;margin-bottom:1rem}
    .vc-tab{padding:.55rem 1.2rem;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);color:var(--muted);font-weight:600;font-size:.88rem;cursor:pointer;transition:all .2s}
    .vc-tab.active{background:color-mix(in oklab,var(--a) 12%,var(--surface));color:var(--text);border-color:color-mix(in oklab,var(--a) 40%,var(--line))}

    /* Upload zone */
    .vc-upload-zone{border:2px dashed var(--line);border-radius:var(--radius-lg);padding:2rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;position:relative}
    .vc-upload-zone:hover,.vc-upload-zone.dragover{border-color:var(--a);background:color-mix(in oklab,var(--a) 5%,var(--surface))}
    .vc-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
    .vc-upload-zone .upload-icon{width:48px;height:48px;margin:0 auto .8rem;color:var(--muted);opacity:.5}
    .vc-upload-zone p{margin:0;color:var(--muted);font-size:.9rem}
    .vc-upload-zone .formats{font-size:.8rem;margin-top:.4rem;opacity:.7}
    .vc-file-info{padding:.8rem;background:color-mix(in oklab,var(--a) 8%,var(--surface));border:1px solid color-mix(in oklab,var(--a) 25%,var(--line));border-radius:var(--radius);display:flex;justify-content:space-between;align-items:center;margin-top:.6rem;font-size:.88rem}
    .vc-file-info .remove{cursor:pointer;color:var(--muted);font-weight:700;transition:color .2s}
    .vc-file-info .remove:hover{color:#ef4444}

    /* Recorder */
    .vc-recorder{text-align:center;padding:1.5rem;border:1px solid var(--line);border-radius:var(--radius-lg);background:var(--surface)}
    .vc-recorder .rec-btn{width:72px;height:72px;border-radius:50%;border:3px solid var(--line);background:var(--surface);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .25s;position:relative}
    .vc-recorder .rec-btn:hover{border-color:var(--a)}
    .vc-recorder .rec-btn.recording{border-color:#ef4444;animation:rec-pulse 1.5s ease infinite}
    .vc-recorder .rec-btn .inner{width:28px;height:28px;border-radius:50%;background:#ef4444;transition:all .25s}
    .vc-recorder .rec-btn.recording .inner{border-radius:4px;width:24px;height:24px}
    @keyframes rec-pulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.3)}50%{box-shadow:0 0 0 12px rgba(239,68,68,0)}}
    .vc-timer{font-size:1.6rem;font-weight:700;margin:.8rem 0 .4rem;font-variant-numeric:tabular-nums}
    .vc-recorder .hint{font-size:.85rem;color:var(--muted)}
    .vc-recorder .rec-actions{display:flex;justify-content:center;gap:.5rem;margin-top:.8rem}

    /* Guidelines */
    .vc-guidelines{background:color-mix(in oklab,var(--a) 6%,var(--surface));border:1px solid color-mix(in oklab,var(--a) 20%,var(--line));border-radius:var(--radius);padding:1rem 1.2rem;margin-bottom:1.2rem}
    .vc-guidelines h4{margin:0 0 .6rem;font-size:.92rem;color:var(--a)}
    .vc-guidelines ul{list-style:none;padding:0;margin:0;font-size:.86rem;color:var(--muted)}
    .vc-guidelines ul li{padding:.25rem 0;padding-inline-start:1.2rem;position:relative}
    .vc-guidelines ul li::before{content:"";position:absolute;inset-inline-start:0;top:.65rem;width:6px;height:6px;border-radius:50%;background:var(--a);opacity:.5}

    /* Consent */
    .vc-consent{display:flex;align-items:flex-start;gap:.6rem;padding:1rem;border:1px solid var(--line);border-radius:var(--radius);background:color-mix(in oklab,var(--surface2) 60%,var(--surface));margin-bottom:1.2rem}
    .vc-consent input[type=checkbox]{margin-top:.15rem;flex-shrink:0;width:18px;height:18px;accent-color:var(--a)}
    .vc-consent label{font-size:.88rem;line-height:1.5;cursor:pointer}
    .vc-privacy{font-size:.82rem;color:var(--muted);margin-bottom:1.2rem;display:flex;align-items:start;gap:.4rem}
    .vc-privacy svg{flex-shrink:0;width:16px;height:16px;margin-top:.1rem;opacity:.5}

    /* Count badge */
    .vc-count{font-size:.88rem;color:var(--muted);margin-bottom:1rem}

    /* Empty state */
    .vc-empty{text-align:center;padding:3rem 1.5rem;background:linear-gradient(170deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow)}
    .vc-empty .empty-icon{width:64px;height:64px;margin:0 auto 1rem;color:var(--muted);opacity:.35}
    .vc-empty h3{margin:0 0 .5rem}
    .vc-empty p{color:var(--muted);margin:0 0 1.2rem;font-size:.93rem}

    /* Alerts */
    .vc-alert{padding:.8rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.9rem;display:flex;align-items:center;gap:.5rem}
    .vc-alert.success{background:color-mix(in oklab,#22c55e 12%,var(--surface));border:1px solid color-mix(in oklab,#22c55e 30%,var(--line));color:#22c55e}
    .vc-alert.error{background:color-mix(in oklab,#ef4444 12%,var(--surface));border:1px solid color-mix(in oklab,#ef4444 30%,var(--line));color:#ef4444}

    /* Disabled state */
    .vc-disabled-banner{max-width:580px;margin:2rem auto;text-align:center;padding:2rem;background:color-mix(in oklab,var(--surface2) 60%,var(--surface));border:1px solid var(--line);border-radius:var(--radius-lg)}

    @media(max-width:768px){
      .vc-grid{grid-template-columns:1fr}
      .vc-create-panel{padding:1.2rem}
      .vc-hero h1{font-size:1.5rem}
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>
  @include('components.header')

  <main class="container vc-main">
    {{-- Hero --}}
    <section class="vc-hero">
      <h1>{{ __('ui.voice_cloning.page_title') }}</h1>
      <p>{{ __('ui.voice_cloning.page_subtitle') }}</p>
    </section>

    {{-- Success/Error alerts from session --}}
    @if(session('success'))
      <div class="vc-alert success">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        {{ session('success') }}
      </div>
    @endif

    {{-- ========== UPGRADE REQUIRED STATE ========== --}}
    @if($upgradeRequired && !($planAllowsCloning ?? false))
      <section class="vc-upgrade">
        <svg class="icon-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
        <h2>{{ __('ui.voice_cloning.upgrade_title') }}</h2>
        <p>{{ __('ui.voice_cloning.upgrade_subtitle') }}</p>
        <ul class="features-list">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            {{ __('ui.voice_cloning.upgrade_feature_1') }}
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            {{ __('ui.voice_cloning.upgrade_feature_2') }}
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            {{ __('ui.voice_cloning.upgrade_feature_3', ['max' => $maxVoices]) }}
          </li>
        </ul>
        <a href="{{ route('pricing') }}" class="btn btn-primary">{{ __('ui.voice_cloning.upgrade_cta') }}</a>
      </section>

    {{-- ========== FEATURE DISABLED STATE ========== --}}
    @elseif(!$isEnabled)
      <section class="vc-disabled-banner">
        <p>{{ __('ui.voice_cloning.feature_disabled') }}</p>
      </section>

    {{-- ========== MAIN FEATURE UI ========== --}}
    @else
      {{-- Voice count --}}
      <div class="vc-count">{{ __('ui.voice_cloning.voice_count', ['count' => $voiceCount, 'max' => $maxVoices]) }}</div>

      {{-- Alert container for AJAX responses --}}
      <div id="vcAlerts"></div>

      {{-- Existing cloned voices --}}
      @if(count($clonedVoices) > 0)
        <h2 style="font-size:1.15rem;margin-bottom:1rem">{{ __('ui.voice_cloning.your_voices_title') }}</h2>
        <section class="vc-grid" id="vcVoiceGrid">
          @foreach($clonedVoices as $voice)
            <article class="vc-card" data-voice-id="{{ $voice->id }}" data-status="{{ $voice->status }}">
              <div class="vc-card-header">
                <h3 class="vc-card-name">{{ $voice->name }}</h3>
                <span class="vc-status {{ $voice->status }}">
                  @if($voice->isProcessing())<span class="dot"></span>@endif
                  {{ __('ui.voice_cloning.status_' . $voice->status) }}
                </span>
              </div>
              <div class="vc-card-meta">
                <span>{{ __('ui.voice_cloning.created_on') }} {{ $voice->created_at->format('M d, Y') }}</span>
                <span>{{ $voice->language_code }} &middot; {{ $voice->source_type === 'recording' ? __('ui.voice_cloning.record_tab') : __('ui.voice_cloning.upload_tab') }}</span>
              </div>
              @if($voice->isFailed() && $voice->failure_reason)
                <div class="vc-alert error" style="margin-bottom:.6rem;font-size:.82rem">{{ __('ui.voice_cloning.processing_failed_generic') }}</div>
              @endif
              @if($voice->isProcessing())
                <div style="font-size:.83rem;color:var(--muted);margin-bottom:.6rem">{{ __('ui.voice_cloning.processing_hint') }}</div>
              @endif
              <div class="vc-card-actions">
                @if($voice->isReady())
                  <a href="{{ route('dashboard', ['cloned_voice' => $voice->id]) }}" class="btn btn-primary" style="font-size:.82rem;padding:.4rem .8rem">{{ __('ui.voice_cloning.use_in_generator') }}</a>
                @endif
                @if(\App\Services\VoiceCloningService::canUserDelete($voice))
                  <button type="button" class="btn btn-ghost vc-delete-btn" data-voice-id="{{ $voice->id }}" data-confirm="{{ __('ui.voice_cloning.delete_confirm') }}">{{ __('ui.voice_cloning.delete_voice') }}</button>
                @endif
              </div>
            </article>
          @endforeach
        </section>
      @elseif($voiceCount === 0)
        {{-- Empty state --}}
        <section class="vc-empty" id="vcEmptyState">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
          <h3>{{ __('ui.voice_cloning.empty_title') }}</h3>
          <p>{{ __('ui.voice_cloning.empty_subtitle') }}</p>
          <button type="button" class="btn btn-primary" onclick="document.getElementById('vcCreatePanel').scrollIntoView({behavior:'smooth'})">{{ __('ui.voice_cloning.create_first') }}</button>
        </section>
      @endif

      {{-- ========== CREATE NEW VOICE PANEL ========== --}}
      @if($voiceCount < $maxVoices)
      <section class="vc-create-panel" id="vcCreatePanel" style="margin-top:2rem">
        <h2>{{ __('ui.voice_cloning.create_title') }}</h2>

        {{-- Guidelines --}}
        <div class="vc-guidelines">
          <h4>{{ __('ui.voice_cloning.guidelines_title') }}</h4>
          <ul>
            <li>{{ __('ui.voice_cloning.guideline_1', ['min' => $minDuration]) }}</li>
            <li>{{ __('ui.voice_cloning.guideline_2') }}</li>
            <li>{{ __('ui.voice_cloning.guideline_3') }}</li>
            <li>{{ __('ui.voice_cloning.guideline_4') }}</li>
            <li>{{ __('ui.voice_cloning.guideline_5') }}</li>
          </ul>
        </div>

        <form id="vcCreateForm" enctype="multipart/form-data">
          @csrf

          {{-- Voice name --}}
          <div class="vc-form-group">
            <label for="vcName">{{ __('ui.voice_cloning.voice_name_label') }}</label>
            <input type="text" id="vcName" name="name" required minlength="2" maxlength="50" placeholder="{{ __('ui.voice_cloning.voice_name_placeholder') }}">
          </div>

          {{-- Language --}}
          <div class="vc-form-group">
            <label for="vcLanguage">{{ __('ui.voice_cloning.language_label') }}</label>
            <select id="vcLanguage" name="language_code">
              <option value="ar-XA">{{ __('ui.voice_cloning.language_ar') }}</option>
              <option value="en-US">{{ __('ui.voice_cloning.language_en_us') }}</option>
              <option value="en-GB">{{ __('ui.voice_cloning.language_en_gb') }}</option>
              <option value="en-AU">{{ __('ui.voice_cloning.language_en_au') }}</option>
            </select>
          </div>

          {{-- Upload/Record tabs --}}
          <div class="vc-tabs">
            <button type="button" class="vc-tab active" data-tab="upload">{{ __('ui.voice_cloning.upload_tab') }}</button>
            <button type="button" class="vc-tab" data-tab="recording">{{ __('ui.voice_cloning.record_tab') }}</button>
          </div>

          <input type="hidden" name="source_type" id="vcSourceType" value="upload">
          <input type="hidden" name="audio_duration" id="vcAudioDuration" value="0">

          {{-- Upload panel --}}
          <div class="vc-tab-panel" id="vcUploadPanel">
            <div class="vc-upload-zone" id="vcUploadZone">
              <input type="file" name="audio_file" id="vcFileInput" accept=".wav,.mp3,.m4a,.mp4,.ogg,.webm,.flac,audio/*">
              <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <p>{{ __('ui.voice_cloning.upload_hint') }}</p>
              <p class="formats">{{ __('ui.voice_cloning.upload_formats') }} &middot; {{ __('ui.voice_cloning.upload_max_size', ['size' => $maxUploadMb]) }}</p>
            </div>
            <div class="vc-file-info" id="vcFileInfo" style="display:none">
              <span id="vcFileName"></span>
              <span class="remove" id="vcFileRemove">&times;</span>
            </div>
          </div>

          {{-- Record panel --}}
          <div class="vc-tab-panel" id="vcRecordPanel" style="display:none">
            <div class="vc-recorder" id="vcRecorder">
              <button type="button" class="rec-btn" id="vcRecBtn">
                <span class="inner"></span>
              </button>
              <div class="vc-timer" id="vcTimer">00:00</div>
              <p class="hint" id="vcRecHint">{{ __('ui.voice_cloning.record_start') }}</p>
              <p class="hint" style="font-size:.8rem;margin-top:.3rem">{{ __('ui.voice_cloning.min_duration_note', ['min' => $minDuration]) }}</p>
              <div class="rec-actions" id="vcRecActions" style="display:none">
                <button type="button" class="btn btn-ghost" id="vcRecRetry">{{ __('ui.voice_cloning.record_retry') }}</button>
              </div>
            </div>
          </div>

          {{-- Consent --}}
          <div class="vc-consent" style="margin-top:1.2rem">
            <input type="checkbox" id="vcConsent" name="consent_confirmed" value="1" required>
            <label for="vcConsent">{{ __('ui.voice_cloning.consent_label') }}</label>
          </div>

          {{-- Privacy note --}}
          <div class="vc-privacy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            {{ __('ui.voice_cloning.privacy_note') }}
          </div>

          {{-- Submit --}}
          <button type="submit" class="btn btn-primary" id="vcSubmitBtn" style="width:100%">
            <span id="vcSubmitText">{{ __('ui.voice_cloning.submit_button') }}</span>
            <span id="vcSubmitLoading" style="display:none">{{ __('ui.voice_cloning.submitting') }}</span>
          </button>
        </form>
      </section>
      @else
        <div class="vc-alert" style="margin-top:1.5rem;background:color-mix(in oklab,var(--a) 8%,var(--surface));border:1px solid color-mix(in oklab,var(--a) 20%,var(--line));color:var(--text)">
          <p>{{ __('ui.voice_cloning.limit_reached', ['max' => $maxVoices]) }}</p>
          <a href="{{ route('billing.page') }}">{{ __('ui.voice_cloning.upgrade_cta') }}</a>
        </div>
      @endif
    @endif
  </main>

  @include('components.footer')
  @include('components.scripts')

  @if(($planAllowsCloning ?? false) && $isEnabled)
  <script>
  (function() {
    'use strict';

    const MIN_DURATION = {{ $minDuration }};
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    const STORE_URL = '{{ route("voice-cloning.store") }}';
    const STATUS_URL_BASE = '{{ route("voice-cloning.status", ["id" => "__VOICE_ID__"]) }}'.replace('__VOICE_ID__', '');
    const DELETE_URL_BASE = '{{ route("voice-cloning.destroy", ["id" => "__VOICE_ID__"]) }}'.replace('__VOICE_ID__', '');

    /* ─── Tab switching ─── */
    document.querySelectorAll('.vc-tab').forEach(tab => {
      tab.addEventListener('click', function() {
        document.querySelectorAll('.vc-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        const mode = this.dataset.tab;
        document.getElementById('vcSourceType').value = mode;
        document.getElementById('vcUploadPanel').style.display = mode === 'upload' ? '' : 'none';
        document.getElementById('vcRecordPanel').style.display = mode === 'recording' ? '' : 'none';
      });
    });

    /* ─── Upload handling ─── */
    const uploadZone = document.getElementById('vcUploadZone');
    const fileInput = document.getElementById('vcFileInput');
    const fileInfo = document.getElementById('vcFileInfo');
    const fileName = document.getElementById('vcFileName');
    const fileRemove = document.getElementById('vcFileRemove');

    if (uploadZone) {
      ['dragover', 'dragenter'].forEach(evt => {
        uploadZone.addEventListener(evt, e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
      });
      ['dragleave', 'drop'].forEach(evt => {
        uploadZone.addEventListener(evt, e => { e.preventDefault(); uploadZone.classList.remove('dragover'); });
      });
      uploadZone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
          fileInput.files = e.dataTransfer.files;
          handleFileSelect(e.dataTransfer.files[0]);
        }
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', function() {
        if (this.files[0]) handleFileSelect(this.files[0]);
      });
    }

    if (fileRemove) {
      fileRemove.addEventListener('click', function() {
        fileInput.value = '';
        fileInfo.style.display = 'none';
        uploadZone.style.display = '';
        document.getElementById('vcAudioDuration').value = '0';
      });
    }

    function handleFileSelect(file) {
      fileName.textContent = `{{ __('ui.voice_cloning.upload_selected') }} ${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
      fileInfo.style.display = '';
      uploadZone.style.display = 'none';

      // Get audio duration
      const audio = new Audio();
      audio.addEventListener('loadedmetadata', function() {
        const dur = Math.round(audio.duration);
        document.getElementById('vcAudioDuration').value = dur;
        if (dur < MIN_DURATION) {
          showAlert('error', `{{ __('ui.voice_cloning.validation.audio_too_short', ['min' => '']) }}${MIN_DURATION}s`);
        }
      });
      audio.src = URL.createObjectURL(file);
    }

    /* ─── Recording ─── */
    let mediaRecorder = null;
    let audioChunks = [];
    let recordingStart = null;
    let timerInterval = null;
    let recordedBlob = null;

    const recBtn = document.getElementById('vcRecBtn');
    const timer = document.getElementById('vcTimer');
    const recHint = document.getElementById('vcRecHint');
    const recActions = document.getElementById('vcRecActions');
    const recRetry = document.getElementById('vcRecRetry');

    if (recBtn) {
      recBtn.addEventListener('click', async function() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          stopRecording();
        } else {
          await startRecording();
        }
      });
    }

    if (recRetry) {
      recRetry.addEventListener('click', function() {
        recordedBlob = null;
        timer.textContent = '00:00';
        recHint.textContent = '{{ __("ui.voice_cloning.record_start") }}';
        recActions.style.display = 'none';
        recBtn.classList.remove('recording');
        document.getElementById('vcAudioDuration').value = '0';
      });
    }

    async function startRecording() {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream, { mimeType: getSupportedMime() });

        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        mediaRecorder.onstop = () => {
          stream.getTracks().forEach(t => t.stop());
          recordedBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType });
          const dur = Math.round((Date.now() - recordingStart) / 1000);
          document.getElementById('vcAudioDuration').value = dur;

          if (dur < MIN_DURATION) {
            recHint.textContent = `{{ __('ui.voice_cloning.recording_too_short', ['min' => '']) }}${MIN_DURATION}`;
            recHint.style.color = '#ef4444';
          } else {
            recHint.textContent = `${dur}s {{ strtolower(__('ui.voice_cloning.status_ready')) }}`;
            recHint.style.color = '';
          }
          recActions.style.display = '';
        };

        mediaRecorder.start(250);
        recordingStart = Date.now();
        recBtn.classList.add('recording');
        recHint.textContent = '{{ __("ui.voice_cloning.recording_in_progress") }}';
        recHint.style.color = '';
        recActions.style.display = 'none';

        timerInterval = setInterval(() => {
          const elapsed = Math.round((Date.now() - recordingStart) / 1000);
          const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
          const s = String(elapsed % 60).padStart(2, '0');
          timer.textContent = `${m}:${s}`;
        }, 250);
      } catch (err) {
        if (err.name === 'NotAllowedError') {
          showAlert('error', '{{ __("ui.voice_cloning.mic_permission_denied") }}');
        } else {
          showAlert('error', '{{ __("ui.voice_cloning.mic_not_available") }}');
        }
      }
    }

    function stopRecording() {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        recBtn.classList.remove('recording');
        clearInterval(timerInterval);
      }
    }

    function getSupportedMime() {
      const mimes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
      for (const m of mimes) {
        if (MediaRecorder.isTypeSupported(m)) return m;
      }
      return '';
    }

    /* ─── Form submission ─── */
    const form = document.getElementById('vcCreateForm');
    if (form) {
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('vcSubmitBtn');
        const submitText = document.getElementById('vcSubmitText');
        const submitLoading = document.getElementById('vcSubmitLoading');

        submitBtn.disabled = true;
        submitText.style.display = 'none';
        submitLoading.style.display = '';

        try {
          const formData = new FormData(form);
          const sourceType = document.getElementById('vcSourceType').value;
          formData.set('source_type', sourceType);

          // If recording mode, add the recorded blob as the file
          if (sourceType === 'recording' && recordedBlob) {
            const ext = recordedBlob.type.includes('webm') ? 'webm' : (recordedBlob.type.includes('ogg') ? 'ogg' : 'mp4');
            formData.set('audio_file', recordedBlob, `recording.${ext}`);
          }

          const resp = await fetch(STORE_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: formData,
          });

          const data = await resp.json();

          if (resp.ok && data.success) {
            showAlert('success', data.message || '{{ __("ui.voice_cloning.submitted_success") }}');
            // Reload after short delay to show new voice
            setTimeout(() => window.location.reload(), 1500);
          } else {
            const errMsg = data.error || data.message || Object.values(data.errors || {}).flat().join(' ') || 'Error';
            showAlert('error', errMsg);
          }
        } catch (err) {
          showAlert('error', err.message || 'Network error');
        } finally {
          submitBtn.disabled = false;
          submitText.style.display = '';
          submitLoading.style.display = 'none';
        }
      });
    }

    /* ─── Delete voice ─── */
    document.querySelectorAll('.vc-delete-btn').forEach(btn => {
      btn.addEventListener('click', async function() {
        if (!confirm(this.dataset.confirm)) return;
        const voiceId = this.dataset.voiceId;
        try {
          const resp = await fetch(`${DELETE_URL_BASE}${voiceId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          });
          const data = await resp.json();
          if (resp.ok && data.success) {
            this.closest('.vc-card')?.remove();
            showAlert('success', data.message);
          } else {
            showAlert('error', data.error || 'Failed');
          }
        } catch (err) {
          showAlert('error', err.message);
        }
      });
    });

    /* ─── Poll processing voices ─── */
    document.querySelectorAll('.vc-card[data-status="pending"], .vc-card[data-status="validating"], .vc-card[data-status="processing"]').forEach(card => {
      const voiceId = card.dataset.voiceId;
      const poll = setInterval(async () => {
        try {
          const resp = await fetch(`${STATUS_URL_BASE}${voiceId}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          });
          const data = await resp.json();
          if (data.status === 'ready' || data.status === 'failed') {
            clearInterval(poll);
            window.location.reload();
          }
        } catch {}
      }, 5000);
    });

    /* ─── Alert helper ─── */
    function showAlert(type, message) {
      const container = document.getElementById('vcAlerts');
      if (!container) return;
      const alert = document.createElement('div');
      alert.className = `vc-alert ${type}`;
      alert.textContent = message;
      container.prepend(alert);
      setTimeout(() => alert.remove(), 8000);
    }
  })();
  </script>
  @endif
</body>
</html>
