@include('components.page-head', ['pageTitle' => __('ui.seo.demo_title'), 'page' => 'demo'])
  <link rel="stylesheet" href="{{ asset('css/demo-studio.css') }}">
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

  <main class="container">
    {{-- ── Hero ── --}}
    <section class="studio-hero reveal">
      <h1>{{ __('ui.demo.hero_title') }}</h1>
      <p>{{ __('ui.demo.hero_subtitle') }}</p>
      <div class="studio-badge-row">
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
          <span class="num">{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['ar'] ?? [])) }}</span> {{ __('ui.demo.arabic_voices') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
          <span class="num">{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['en'] ?? [])) }}</span> {{ __('ui.demo.english_voices') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          {{ __('ui.demo.hero_point_1') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
          {{ __('ui.demo.hero_point_3') }}
        </span>
      </div>
    </section>

    {{-- ── Studio workspace ── --}}
    <section class="studio-workspace">
      <div class="studio-shell">
        {{-- ── LEFT: Main editor ── --}}
        <div class="studio-main">

          {{-- Quota --}}
          <div class="s-card reveal" id="quotaBox" style="padding:1rem 1.25rem">
            <div class="quota-panel">
              <div class="q-head">
                <span class="q-label">{{ __('ui.demo.guest_starter_allowance') }}</span>
                <strong class="q-value" id="quotaValue">
                  {{ number_format((int) ($demoQuota['remaining_chars'] ?? $guestAllowanceChars)) }} / {{ number_format((int) ($demoQuota['limit_chars'] ?? $guestAllowanceChars)) }} {{ __('ui.demo.chars_left') }}
                </strong>
              </div>
              <div class="quota-track">
                <div class="quota-fill" id="quotaFill"></div>
              </div>
              <div class="quota-note">{{ __('ui.demo.one_time_guest_credit', ['chars' => number_format((int) $starterPlanChars)]) }}</div>
            </div>
          </div>

          {{-- Text editor --}}
          <form id="demoForm" class="studio-main" style="gap:1rem">
            <div class="s-card s-card-editor reveal">
              <div class="editor-label">
                <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                {{ __('ui.demo.text_input') }}
                <span class="char-count" id="charCounter"></span>
              </div>
              <textarea id="text" name="text" dir="auto" placeholder="{{ __('ui.demo.text_placeholder') }}" required></textarea>
              <div class="sample-chips">
                <button class="sample-chip" type="button" data-sample="Welcome to our platform. We build professional software with world-class quality.">{{ __('ui.demo.english_sample') }}</button>
                <button class="sample-chip" type="button" data-sample="{{ __('ui.demo.arabic_sample_text') }}">{{ __('ui.demo.arabic_sample') }}</button>
              </div>
            </div>

            {{-- Voice controls --}}
            <div class="s-card reveal">
              <div class="controls-row">
                <div class="ctrl-group">
                  <label for="voice_tier">{{ __('ui.demo.voice_quality') }}</label>
                  <select id="voice_tier" name="voice_tier">
                    @foreach($demoTiers as $tier)
                      <option value="{{ $tier['key'] }}" {{ $tier['key'] === $defaultTier ? 'selected' : '' }}>{{ $tier['label'] }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="ctrl-group">
                  <label for="target_language">{{ __('ui.demo.voice_language') }}</label>
                  <select id="target_language" name="target_language">
                    <option value="ar">{{ __('ui.demo.arabic') }}</option>
                    <option value="en">{{ __('ui.demo.english') }}</option>
                  </select>
                </div>
                <div class="ctrl-group">
                  <label for="voice_gender">{{ __('ui.demo.gender') }}</label>
                  <select id="voice_gender">
                    <option value="all">{{ __('ui.demo.any') }}</option>
                    <option value="female">{{ __('ui.demo.female') }}</option>
                    <option value="male">{{ __('ui.demo.male') }}</option>
                  </select>
                </div>
                <div class="ctrl-group">
                  <label for="voice_key">{{ __('ui.demo.speaker') }}</label>
                  <select id="voice_key" name="voice_key"></select>
                </div>
              </div>
            </div>

            {{-- Performance Controls (HD only) --}}
            <div class="s-card perf-panel reveal" id="studioPanel" style="display:none;">
              <div class="perf-header">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                {{ __('ui.demo.voice_studio', ['default' => 'Performance Controls']) }} <span class="premium-badge">{{ __('ui.pricing.premium', ['default' => 'Premium']) }}</span>
              </div>
              <div class="perf-row-2">
                <div class="perf-section">
                  <div class="perf-section-label">🎭 {{ __('ui.demo.performance_style', ['default' => 'Performance Style']) }}</div>
                  <select name="performance_style" id="performance_style" required>
                    <option value="">-- {{ __('ui.demo.choose_performance_style', ['default' => 'Choose Performance Style']) }} --</option>
                    <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                    <option value="educational">{{ __('ui.demo.style_educational', ['default' => 'Educational']) }}</option>
                    <option value="commercial">{{ __('ui.demo.style_commercial', ['default' => 'Commercial']) }}</option>
                    <option value="promo">{{ __('ui.demo.style_promo', ['default' => 'Promotional']) }}</option>
                    <option value="podcast">{{ __('ui.demo.style_podcast', ['default' => 'Podcast']) }}</option>
                    <option value="assistant">{{ __('ui.demo.style_assistant', ['default' => 'Virtual Assistant']) }}</option>
                    <option value="narrative">{{ __('ui.demo.style_narrative', ['default' => 'Narrative']) }}</option>
                    <option value="comedic">{{ __('ui.demo.style_comedic', ['default' => 'Comedic']) }}</option>
                    <option value="corporate">{{ __('ui.demo.style_corporate', ['default' => 'Corporate']) }}</option>
                    <option value="conversational">{{ __('ui.demo.style_conversational', ['default' => 'Conversational']) }}</option>
                    <option value="inspirational">{{ __('ui.demo.style_inspirational', ['default' => 'Inspirational']) }}</option>
                    <option value="cinematic">{{ __('ui.demo.style_cinematic', ['default' => 'Cinematic']) }}</option>
                    <option value="documentary">{{ __('ui.demo.style_documentary', ['default' => 'Documentary']) }}</option>
                    <option value="storytelling">{{ __('ui.dashboard.emotion_storytelling', ['default' => 'Storytelling']) }}</option>
                    <option value="ad_style">{{ __('ui.dashboard.emotion_ad_style', ['default' => 'Ad Style']) }}</option>
                    <option value="news">{{ __('ui.dashboard.emotion_news', ['default' => 'News']) }}</option>
                    <option value="sports_commentator">{{ __('ui.dashboard.style_sports_commentator', ['default' => 'Sports Commentator']) }}</option>
                    <option value="meditation">{{ __('ui.dashboard.style_meditation', ['default' => 'Meditation']) }}</option>
                    <option value="audiobook">{{ __('ui.dashboard.style_audiobook', ['default' => 'Audiobook']) }}</option>
                    <option value="children_story">{{ __('ui.dashboard.style_children_story', ['default' => 'Children Story']) }}</option>
                    <option value="radio_host">{{ __('ui.dashboard.style_radio_host', ['default' => 'Radio Host']) }}</option>
                  </select>
                </div>
                <div class="perf-section">
                  <div class="perf-section-label">💫 {{ __('ui.demo.emotion', ['default' => 'Emotion']) }}</div>
                  <select name="emotion" id="emotion" required>
                    <option value="">-- {{ __('ui.demo.choose_emotion', ['default' => 'Choose Emotion']) }} --</option>
                    <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                    <option value="warm">{{ __('ui.dashboard.emotion_warm', ['default' => 'Warm']) }}</option>
                    <option value="sad">{{ __('ui.dashboard.emotion_sad', ['default' => 'Sad']) }}</option>
                    <option value="excited">{{ __('ui.dashboard.emotion_excited', ['default' => 'Excited']) }}</option>
                    <option value="formal">{{ __('ui.dashboard.emotion_formal', ['default' => 'Formal']) }}</option>
                    <option value="calm">{{ __('ui.dashboard.emotion_calm', ['default' => 'Calm']) }}</option>
                    <option value="angry">{{ __('ui.dashboard.emotion_angry', ['default' => 'Angry']) }}</option>
                    <option value="hopeful">{{ __('ui.dashboard.emotion_hopeful', ['default' => 'Hopeful']) }}</option>
                    <option value="serious">{{ __('ui.dashboard.emotion_serious', ['default' => 'Serious']) }}</option>
                    <option value="playful">{{ __('ui.dashboard.emotion_playful', ['default' => 'Playful']) }}</option>
                    <option value="mysterious">{{ __('ui.dashboard.emotion_mysterious', ['default' => 'Mysterious']) }}</option>
                    <option value="confident">{{ __('ui.dashboard.emotion_confident', ['default' => 'Confident']) }}</option>
                  </select>
                </div>
              </div>
              <div class="perf-section" id="stylePromptSection" style="display:none;">
                <div class="perf-section-label">✨ {{ __('ui.demo.style_prompt', ['default' => 'Custom Prompt']) }}</div>
                <textarea name="style_prompt" id="style_prompt" rows="2" maxlength="2000" placeholder="{{ __('ui.demo.style_prompt_placeholder', ['default' => 'Describe how you want the voice to sound…']) }}"></textarea>
              </div>
              <div class="perf-section">
                <div class="perf-section-label">🌍 {{ __('ui.demo.dialect', ['default' => 'Dialect']) }}</div>
                <select name="dialect" id="dialect"><option value="">{{ __('ui.dashboard.dialect_auto', ['default' => 'Auto-detect']) }}</option></select>
              </div>
              <div class="perf-row-2">
                <div class="perf-section">
                  <div class="perf-section-label">
                    ⚡ {{ __('ui.demo.speed', ['default' => 'Speed']) }}
                    <span class="slider-value" id="speedVal">1.0</span>x
                  </div>
                  <div class="slider-wrap">
                    <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="1.0" oninput="document.getElementById('speedVal').textContent=this.value">
                  </div>
                </div>
                <div class="perf-section">
                  <div class="perf-section-label">
                    🎵 {{ __('ui.demo.pitch', ['default' => 'Pitch']) }}
                    <span class="slider-value" id="pitchVal">0</span>
                  </div>
                  <div class="slider-wrap">
                    <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="0" oninput="document.getElementById('pitchVal').textContent=this.value">
                  </div>
                </div>
              </div>
            </div>

            {{-- Translate toggle --}}
            <div class="s-card reveal" style="padding:0">
              <div class="translate-row">
                <div class="translate-info">
                  <label for="translateToggle">{{ __('ui.demo.translate_before_speaking') }}</label>
                  <div class="hint" id="translateHint">{{ __('ui.demo.translate_disabled_hint') }}</div>
                </div>
                <label class="toggle-switch" aria-label="{{ __('ui.demo.translate_before_speaking') }}">
                  <input type="checkbox" id="translateToggle">
                  <span class="toggle-slider"></span>
                </label>
                <input type="hidden" name="translate" id="translate" value="0">
              </div>
            </div>

            {{-- Generate row with waveform --}}
            <div class="generate-row reveal">
              <button class="btn-generate" type="button" id="previewBtn">
                <span>
                  <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  {{ __('ui.demo.generate_voice') }}
                </span>
              </button>
              <div class="wave-bar" id="heroWave" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
              </div>
            </div>
            <div id="errorBox" class="s-card error-box" style="display:none;"></div>
          </form>
        </div>

        {{-- ── RIGHT: Sidebar ── --}}
        <aside class="studio-sidebar">

          <div class="s-card upgrade-box" id="upgradeBox" style="display:none;">
            <p id="upgradeText">{{ __('ui.demo.upgrade_text', ['chars' => number_format((int) $starterPlanChars)]) }}</p>
            <a class="btn btn-primary" href="{{ route('register') }}" style="width:100%;justify-content:center">{{ __('ui.demo.create_free_account') }}</a>
          </div>

          <div class="s-card output-panel" id="audioWrap" style="display:none;">
            <div class="panel-title">
              <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
              {{ __('ui.demo.hero_point_4') }}
            </div>
            <audio id="previewAudio" controls preload="none"></audio>
            <div class="audio-meta" id="audioMeta"></div>
          </div>

          <div class="s-card output-panel reveal" id="outputPlaceholder">
            <div class="output-placeholder">
              <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
              {{ __('ui.demo.hero_point_4') }}
            </div>
          </div>
        </aside>
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $demoI18n = [
      'upgrade_text' => __('ui.demo.upgrade_text_raw'),
      'chars_left' => __('ui.demo.chars_left'),
      'quota_total_start' => __('ui.demo.quota_total_start'),
      'translate_enabled_hint' => __('ui.demo.translate_enabled_hint'),
      'translate_disabled_hint' => __('ui.demo.translate_disabled_hint'),
      'any' => __('ui.demo.any'),
      'no_speakers' => __('ui.demo.no_speakers'),
      'request_failed' => __('ui.demo.request_failed'),
      'text_required' => __('ui.demo.text_required'),
      'selected_chars_exceed' => __('ui.demo.selected_chars_exceed'),
      'generating' => __('ui.demo.generating'),
      'no_audio_returned' => __('ui.demo.no_audio_returned'),
      'selected_speaker' => __('ui.demo.selected_speaker'),
      'preview_failed' => __('ui.demo.preview_failed'),
      'server_overload' => __('ui.dashboard.server_overload'),
      'generate_voice' => __('ui.demo.generate_voice'),
      'create_account_unlock' => __('ui.demo.create_account_unlock'),
      'speaker_prefix' => __('ui.demo.speaker_prefix'),
      'performance_style_required' => __('ui.backend.performance_style_required'),
      'emotion_required' => __('ui.backend.emotion_required'),
    ];
  @endphp
  <script>
    const voiceCatalogByTier = @json($voiceCatalog);
    const form = document.getElementById('demoForm');
    const voiceTier = document.getElementById('voice_tier');
    const targetLanguage = document.getElementById('target_language');
    const voiceGender = document.getElementById('voice_gender');
    const voiceSelect = document.getElementById('voice_key');
    const dialectSelect = document.getElementById('dialect');
    const performanceStyleSelect = document.getElementById('performance_style');
    const emotionSelect = document.getElementById('emotion');
    const speedInput = document.getElementById('speed');
    const pitchInput = document.getElementById('pitch');
    const speedValue = document.getElementById('speedVal');
    const pitchValue = document.getElementById('pitchVal');
    const studioPanel = document.getElementById('studioPanel');
    const stylePromptSection = document.getElementById('stylePromptSection');
    const stylePromptInput = document.getElementById('style_prompt');
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
    let feedbackScrollFrame = null;
    const heroWave = document.getElementById('heroWave');
    const textInput = document.getElementById('text');
    const outputPlaceholder = document.getElementById('outputPlaceholder');

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
        { value: 'ar-TN', label: '{{ __("ui.dialects.tunisian", ["default" => "Tunisian"]) }}' },
        { value: 'ar-OM', label: '{{ __("ui.dialects.omani", ["default" => "Omani"]) }}' },
      ],
      en: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}' },
        { value: 'en-US', label: '{{ __("ui.dialects.american", ["default" => "American"]) }}' },
        { value: 'en-GB', label: '{{ __("ui.dialects.british", ["default" => "British"]) }}' },
        { value: 'en-AU', label: '{{ __("ui.dialects.australian", ["default" => "Australian"]) }}' },
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

    function normalizeTier(value) {
      const tier = String(value || 'standard').trim().toLowerCase();
      return ['standard', 'hd'].includes(tier) ? tier : 'standard';
    }

    function resetStudioControls() {
      if (performanceStyleSelect) performanceStyleSelect.value = 'neutral';
      if (emotionSelect) emotionSelect.value = 'neutral';
      if (speedInput) speedInput.value = '1.0';
      if (pitchInput) pitchInput.value = '0';
      if (speedValue) speedValue.textContent = '1.0';
      if (pitchValue) pitchValue.textContent = '0';
      if (dialectSelect) dialectSelect.value = '';
    }

    function applyStudioTierState() {
      const tier = voiceTier?.value || 'standard';
      const isHd = (tier === 'hd');
      if (studioPanel) studioPanel.style.display = isHd ? '' : 'none';
      if (stylePromptSection) stylePromptSection.style.display = isHd ? '' : 'none';
      [performanceStyleSelect, emotionSelect, dialectSelect, speedInput, pitchInput, stylePromptInput].forEach((el) => {
        if (!el) return;
        el.disabled = !isHd;
        if (el === performanceStyleSelect || el === emotionSelect) {
          el.required = isHd;
        }
      });
      if (!isHd) {
        if (performanceStyleSelect) performanceStyleSelect.value = '';
        if (emotionSelect) emotionSelect.value = '';
        if (dialectSelect) dialectSelect.value = '';
        if (speedInput) { speedInput.value = '1.0'; }
        if (pitchInput) { pitchInput.value = '0'; }
        if (stylePromptInput) stylePromptInput.value = '';
        const sv = document.getElementById('speedVal');
        const pv = document.getElementById('pitchVal');
        if (sv) sv.textContent = '1.0';
        if (pv) pv.textContent = '0';
      }
    }
    const demoQuota = @json($demoQuota ?? null);
    const starterPlanChars = Number(@json((int) $starterPlanChars));
    const i18n = @json($demoI18n);
    const pageLocale = document.documentElement.lang || 'en';

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

    function formatNumber(value) { return new Intl.NumberFormat('en-US').format(Number(value || 0)); }
    function countBillableChars(value) {
      const text = String(value || '');
      try { return (text.match(new RegExp('[\\p{L}\\p{N}]', 'gu')) || []).length; }
      catch (_error) { return (text.match(/[A-Za-z0-9]/g) || []).length; }
    }
    function normalizeLanguage(value) { return value === 'en' ? 'en' : 'ar'; }
    function oppositeLanguage(value) { return normalizeLanguage(value) === 'en' ? 'ar' : 'en'; }
    function detectSourceLanguage(value) { return /[\u0600-\u06FF]/u.test(String(value || '')) ? 'ar' : 'en'; }
    function isTranslateEnabled() { return String(translateSelect?.value || '1') === '1'; }
    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value)) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
    }

    function hideAudioPreview() {
      audioWrap.style.display = 'none';
      if (outputPlaceholder) outputPlaceholder.style.display = '';
      previewAudio.pause();
      previewAudio.removeAttribute('src');
      delete previewAudio.dataset.downloadLanguage;
      delete previewAudio.dataset.downloadText;
      delete previewAudio.dataset.downloadSpeaker;
      delete previewAudio.dataset.downloadQuality;
      previewAudio.load();
      audioMeta.textContent = '';
    }

    function setError(message = '') {
      if (!message) { errorBox.style.display = 'none'; errorBox.textContent = ''; return; }
      // Sanitize technical error messages
      const technicalPatterns = [
        /maximum execution time/i,
        /timeout/i,
        /memory.*exhausted/i,
        /fatal error/i,
        /internal server error/i,
        /500/,
        /502/,
        /503/,
        /504/,
        /gateway/i,
        /service unavailable/i,
      ];
      if (technicalPatterns.some(p => p.test(message))) {
        message = i18n.server_overload || i18n.preview_failed;
      }
      errorBox.style.display = 'block';
      errorBox.textContent = message;
      scrollFeedbackIntoView(errorBox);
    }

    function scrollFeedbackIntoView(element) {
      if (!element) return;
      if (feedbackScrollFrame) {
        cancelAnimationFrame(feedbackScrollFrame);
      }
      feedbackScrollFrame = requestAnimationFrame(() => {
        feedbackScrollFrame = null;
        const rect = element.getBoundingClientRect();
        const topOffset = 108;
        const bottomOffset = 24;
        const isVisible = rect.top >= topOffset && rect.bottom <= (window.innerHeight - bottomOffset);
        if (isVisible) return;
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView({
          behavior: reduceMotion ? 'auto' : 'smooth',
          block: 'nearest',
          inline: 'nearest',
        });
      });
    }

    function setUpgradeVisible(show, customMessage = '') {
      if (!upgradeBox) return;
      upgradeBox.style.display = show ? 'block' : 'none';
      if (show && upgradeText) {
        const fallback = i18n.upgrade_text.replace(':chars', formatNumber(starterPlanChars));
        upgradeText.textContent = customMessage || fallback;
      }
    }

    function renderQuota(quota) {
      if (!quota || typeof quota !== 'object') return;
      currentQuota = { ...currentQuota, ...quota };
      if (quotaValue) quotaValue.textContent = `${formatNumber(currentQuota.remaining_chars)} / ${formatNumber(currentQuota.limit_chars)} ${i18n.chars_left}`;
      if (quotaFill) {
        const remaining = Math.max(0, Number(currentQuota.remaining_chars || 0));
        const limit = Math.max(1, Number(currentQuota.limit_chars || 1));
        const percent = Math.max(0, Math.min(100, Math.round((remaining / limit) * 100)));
        quotaFill.style.width = `${percent}%`;
      }
      if (quotaBox) {
        const pending = countBillableChars(textInput?.value || '');
        if (pending > 0) quotaBox.title = `${formatNumber(pending)} billable chars selected`;
      }
      setUpgradeVisible(Boolean(currentQuota.is_exhausted), i18n.quota_total_start.replace(':total', formatNumber(currentQuota.limit_chars)).replace(':chars', formatNumber(starterPlanChars)));
    }

    // Enhanced fingerprinting system
    const fingerprintComponents = {};

    async function sha256(str) {
      if (!window.crypto?.subtle || !window.TextEncoder) return str;
      const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
      return Array.from(new Uint8Array(digest)).map((v) => v.toString(16).padStart(2, '0')).join('');
    }

    function getCanvasFingerprint() {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = 200; canvas.height = 50;
        const ctx = canvas.getContext('2d');
        if (!ctx) return '';
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillStyle = '#f60';
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.fillText('Listen Demo FP 🎵', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
        ctx.fillText('Listen Demo FP 🎵', 4, 17);
        ctx.strokeStyle = '#2a4';
        ctx.arc(50, 25, 20, 0, Math.PI * 2, true);
        ctx.stroke();
        return canvas.toDataURL();
      } catch (e) { return ''; }
    }

    function getWebGLFingerprint() {
      try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (!gl) return '';
        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        const vendor = debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : '';
        const renderer = debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : '';
        const version = gl.getParameter(gl.VERSION) || '';
        const shadingVersion = gl.getParameter(gl.SHADING_LANGUAGE_VERSION) || '';
        const extensions = (gl.getSupportedExtensions() || []).sort().join(',');
        return [vendor, renderer, version, shadingVersion, extensions].join('|');
      } catch (e) { return ''; }
    }

    function getAudioFingerprint() {
      return new Promise((resolve) => {
        try {
          const AudioContext = window.OfflineAudioContext || window.webkitOfflineAudioContext;
          if (!AudioContext) { resolve(''); return; }
          const ctx = new AudioContext(1, 44100, 44100);
          const oscillator = ctx.createOscillator();
          const compressor = ctx.createDynamicsCompressor();
          oscillator.type = 'triangle';
          oscillator.frequency.setValueAtTime(10000, ctx.currentTime);
          compressor.threshold.setValueAtTime(-50, ctx.currentTime);
          compressor.knee.setValueAtTime(40, ctx.currentTime);
          compressor.ratio.setValueAtTime(12, ctx.currentTime);
          compressor.attack.setValueAtTime(0, ctx.currentTime);
          compressor.release.setValueAtTime(0.25, ctx.currentTime);
          oscillator.connect(compressor);
          compressor.connect(ctx.destination);
          oscillator.start(0);
          ctx.startRendering();
          ctx.oncomplete = (event) => {
            try {
              const data = event.renderedBuffer.getChannelData(0);
              let sum = 0;
              for (let i = 4500; i < 5000; i++) sum += Math.abs(data[i]);
              resolve(sum.toString());
            } catch (e) { resolve(''); }
          };
          setTimeout(() => resolve(''), 1000);
        } catch (e) { resolve(''); }
      });
    }

    function getFontsFingerprint() {
      const testFonts = ['Arial', 'Arial Black', 'Calibri', 'Cambria', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Lucida Console', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Wingdings'];
      const testString = 'mmmmmmmmmmlli';
      const testSize = '72px';
      const baseFonts = ['monospace', 'sans-serif', 'serif'];
      const span = document.createElement('span');
      span.style.cssText = 'position:absolute;left:-9999px;font-size:' + testSize;
      span.textContent = testString;
      document.body.appendChild(span);
      const baseWidths = {};
      for (const base of baseFonts) {
        span.style.fontFamily = base;
        baseWidths[base] = span.offsetWidth;
      }
      const detected = [];
      for (const font of testFonts) {
        let found = false;
        for (const base of baseFonts) {
          span.style.fontFamily = `'${font}',${base}`;
          if (span.offsetWidth !== baseWidths[base]) { found = true; break; }
        }
        if (found) detected.push(font);
      }
      document.body.removeChild(span);
      return detected.join(',');
    }

    function getHardwareFingerprint() {
      return [
        String(navigator.hardwareConcurrency || ''),
        String(navigator.deviceMemory || ''),
        String(navigator.maxTouchPoints || ''),
        navigator.platform || '',
        String(screen.width) + 'x' + String(screen.height),
        String(screen.colorDepth || ''),
        String(window.devicePixelRatio || ''),
        String(screen.availWidth) + 'x' + String(screen.availHeight),
        String(window.outerWidth) + 'x' + String(window.outerHeight),
      ].join('|');
    }

    function getScreenFingerprint() {
      return [
        String(screen.width) + 'x' + String(screen.height),
        String(screen.availWidth) + 'x' + String(screen.availHeight),
        String(screen.colorDepth || ''),
        String(screen.pixelDepth || ''),
        String(window.devicePixelRatio || ''),
        window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
        window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'reduced' : 'normal',
      ].join('|');
    }

    function getTimezoneFingerprint() {
      const tz = Intl.DateTimeFormat().resolvedOptions();
      return [
        tz.timeZone || '',
        String(new Date().getTimezoneOffset()),
        tz.locale || '',
      ].join('|');
    }

    function detectWebDriver() {
      return !!(navigator.webdriver || window.callPhantom || window._phantom || window.__nightmare || document.__selenium_evaluate || document.__webdriver_evaluate || document.__driver_evaluate);
    }

    async function buildFingerprint() {
      // Collect all fingerprint components
      const timezone = getTimezoneFingerprint();
      const hardware = getHardwareFingerprint();
      const screenFp = getScreenFingerprint();
      const canvas = getCanvasFingerprint();
      const webgl = getWebGLFingerprint();
      const fonts = getFontsFingerprint();
      let audio = '';
      try { audio = await getAudioFingerprint(); } catch (e) {}

      // Store components for sending to server
      fingerprintComponents.timezone = timezone;
      fingerprintComponents.hardware = hardware;
      fingerprintComponents.screen = screenFp;
      fingerprintComponents.canvas = await sha256(canvas);
      fingerprintComponents.webgl = await sha256(webgl);
      fingerprintComponents.fonts = await sha256(fonts);
      fingerprintComponents.audio = await sha256(audio);
      fingerprintComponents.webdriver = detectWebDriver() ? 'true' : 'false';

      // Build primary fingerprint (combination of most stable signals)
      const raw = [
        fingerprintComponents.canvas,
        fingerprintComponents.webgl,
        fingerprintComponents.audio,
        fingerprintComponents.fonts,
        timezone,
        hardware,
        navigator.userAgent || '',
        navigator.language || '',
        Array.isArray(navigator.languages) ? navigator.languages.join(',') : '',
      ].join('|');

      return await sha256(raw);
    }

    async function ensureFingerprint() {
      if (clientFingerprint) return clientFingerprint;
      if (!fingerprintPromise) {
        fingerprintPromise = buildFingerprint()
          .then((fp) => {
            clientFingerprint = String(fp || '').slice(0, 256);
            if (clientFingerprint) {
              // Store full fingerprint data as base64 JSON
              const fpData = btoa(JSON.stringify(fingerprintComponents));
              document.cookie = `listen_demo_fp=${encodeURIComponent(fpData)}; Max-Age=31536000; Path=/; SameSite=Lax`;
              // Also store in localStorage as backup
              try { localStorage.setItem('listen_demo_fp', fpData); } catch (e) {}
              // Also store in IndexedDB as backup
              try {
                const req = indexedDB.open('listen_demo', 1);
                req.onupgradeneeded = () => req.result.createObjectStore('fp');
                req.onsuccess = () => { try { req.result.transaction('fp', 'readwrite').objectStore('fp').put(fpData, 'fingerprint'); } catch (e) {} };
              } catch (e) {}
            }
            return clientFingerprint;
          })
          .catch(() => { clientFingerprint = ''; return ''; });
      }
      return fingerprintPromise;
    }

    // Restore fingerprint from storage on load
    (function restoreFingerprint() {
      try {
        let stored = '';
        // Try localStorage first
        try { stored = localStorage.getItem('listen_demo_fp') || ''; } catch (e) {}
        // Try cookie
        if (!stored) {
          const match = document.cookie.match(/listen_demo_fp=([^;]+)/);
          if (match) stored = decodeURIComponent(match[1]);
        }
        // Parse and restore components
        if (stored) {
          try {
            const data = JSON.parse(atob(stored));
            if (data && typeof data === 'object') {
              Object.assign(fingerprintComponents, data);
            }
          } catch (e) {}
        }
      } catch (e) {}
    })();

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1' ? i18n.translate_enabled_hint : i18n.translate_disabled_hint;
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

    function cleanSpeakerLabel(value) { return String(value||'').replace(/^\++\s*/,'').replace(/\b[a-z]{2}-[A-Z]{2}\b/g,'').replace(/\s{2,}/g,' ').trim(); }
    function voiceDisplayLabel(voice) { const label = pageLocale === 'ar' && voice.label_ar ? voice.label_ar : voice.label; return cleanSpeakerLabel(label); }
    function formatTierLabel(value) { const text = String(value||'').trim(); if (!text) return ''; return `\u2066${text}\u2069`; }

    function sortVoices(list) {
      const tierRank = { standard: 1, hd: 2 };
      return [...list].sort((a, b) => {
        const aRank = tierRank[String(a?.tier||'standard')]??99;
        const bRank = tierRank[String(b?.tier||'standard')]??99;
        if (aRank !== bRank) return aRank - bRank;
        const byLabel = String(a?.label||'').localeCompare(String(b?.label||''),undefined,{sensitivity:'base'});
        if (byLabel !== 0) return byLabel;
        return String(a?.key||'').localeCompare(String(b?.key||''),undefined,{sensitivity:'base'});
      });
    }

    function renderVoiceOptions() {
      if (!voiceSelect || !targetLanguage) return;
      const voices = getFilteredVoices();
      const lang = normalizeLanguage(targetLanguage.value);
      const remembered = String(preferredVoiceKeyByLanguage[lang]||'').trim();
      const current = String(remembered||'').trim();
      voiceSelect.innerHTML = '';
      if (!voices.length) { voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>'; preferredVoiceKeyByLanguage[lang] = ''; return; }
      for (const voice of voices) { const option = document.createElement('option'); option.value = voice.key; option.textContent = voiceDisplayLabel(voice); voiceSelect.appendChild(option); }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
      preferredVoiceKeyByLanguage[lang] = String(voiceSelect.value||'').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !textInput || isTranslateEnabled()) return false;
      const text = String(textInput.value||'').trim();
      if (!text) { const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null; if (!fallback) return false; const currentLanguage = normalizeLanguage(targetLanguage.value); if (currentLanguage === fallback) return false; rememberCurrentVoiceSelection(currentLanguage); targetLanguage.value = fallback; renderVoiceOptions(); return true; }
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

      // Add fingerprint components to form data for enhanced tracking
      await ensureFingerprint();
      if (fingerprintComponents.canvas) fd.append('fp_canvas', fingerprintComponents.canvas);
      if (fingerprintComponents.webgl) fd.append('fp_webgl', fingerprintComponents.webgl);
      if (fingerprintComponents.audio) fd.append('fp_audio', fingerprintComponents.audio);
      if (fingerprintComponents.fonts) fd.append('fp_fonts', fingerprintComponents.fonts);
      if (fingerprintComponents.screen) fd.append('fp_screen', fingerprintComponents.screen);
      if (fingerprintComponents.hardware) fd.append('fp_hardware', fingerprintComponents.hardware);
      if (fingerprintComponents.timezone) fd.append('fp_timezone', fingerprintComponents.timezone);
      if (fingerprintComponents.webdriver) fd.append('fp_webdriver', fingerprintComponents.webdriver);

      const headers = { ...csrfHeaders };
      if (clientFingerprint) {
        // Send full fingerprint data as base64 JSON header
        headers['X-Listen-Fingerprint'] = btoa(JSON.stringify(fingerprintComponents));
      }

      const res = await fetch(endpoint, { method: 'POST', headers, body: fd });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok === false) {
        const firstError = data?.errors && typeof data.errors === 'object' ? Object.values(data.errors)[0] : null;
        const firstErrorText = Array.isArray(firstError) ? String(firstError[0]||'') : '';
        const error = new Error(String(data?.message || firstErrorText || i18n.request_failed));
        error.payload = data;
        throw error;
      }
      return data;
    }

    previewBtn?.addEventListener('click', async () => {
      heroWave?.classList.add('active');
      try {
        if (!isTranslateEnabled()) syncLanguageToCurrentTextWhenTranslationOff();
        const rawText = String(textInput?.value || '');
        const trimmed = rawText.trim();
        const selectedChars = countBillableChars(trimmed);
        if (!trimmed) { hideAudioPreview(); setError(i18n.text_required); return; }
        if ((voiceTier?.value || 'standard') === 'hd' && !String(performanceStyleSelect?.value || '').trim()) {
          hideAudioPreview();
          setError(i18n.performance_style_required);
          performanceStyleSelect?.focus();
          return;
        }
        if ((voiceTier?.value || 'standard') === 'hd' && !String(emotionSelect?.value || '').trim()) {
          hideAudioPreview();
          setError(i18n.emotion_required);
          emotionSelect?.focus();
          return;
        }
        if (selectedChars > Number(currentQuota.remaining_chars || 0)) {
          hideAudioPreview();
          const message = i18n.selected_chars_exceed.replace(':selected', formatNumber(selectedChars)).replace(':remaining', formatNumber(currentQuota.remaining_chars));
          setError(message);
          setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
          return;
        }
        previewBtn.disabled = true;
        previewBtn.querySelector('span').innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" style="animation:spin .8s linear infinite;fill:currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z" opacity=".3"/><path d="M20 12h2A10 10 0 0 0 12 2v2a8 8 0 0 1 8 8z"/></svg>' + i18n.generating;
        hideAudioPreview();
        const data = await callEndpoint('{{ route("demo.preview") }}');
        renderQuota(data?.quota || null);
        if (!data?.audio_url) { hideAudioPreview(); setError(i18n.no_audio_returned); return; }
        if (outputPlaceholder) outputPlaceholder.style.display = 'none';
        audioWrap.style.display = 'block';
        if (window.initCustomAudioPlayers) window.initCustomAudioPlayers();
        previewAudio.dataset.downloadLanguage = String(data?.target_language || normalizeLanguage(targetLanguage?.value || 'ar'));
        previewAudio.dataset.downloadText = String(trimmed).slice(0, 42);
        previewAudio.dataset.downloadSpeaker = voiceDisplayLabel(data?.voice || { label: i18n.selected_speaker, label_ar: i18n.selected_speaker });
        previewAudio.dataset.downloadQuality = String(data?.voice_tier_label || 'Standard');
        previewAudio.src = data.audio_url;
        previewAudio.load();
        previewAudio.addEventListener('canplaythrough', function autoPlayHandler() {
          previewAudio.removeEventListener('canplaythrough', autoPlayHandler);
          previewAudio.play().catch(() => {});
        });
        const voiceData = data?.voice || {};
        const label = voiceDisplayLabel(voiceData || { label: i18n.selected_speaker, label_ar: i18n.selected_speaker });
        const quality = data?.voice_tier_label ? ` - ${formatTierLabel(data.voice_tier_label)}` : '';
        audioMeta.textContent = `${i18n.speaker_prefix} ${label}${quality} - ${data.translate_note}`;
      } catch (err) {
        hideAudioPreview();
        const message = String(err?.message || i18n.preview_failed);
        if (err?.payload?.quota) renderQuota(err.payload.quota);
        setError(message);
        if (message.toLowerCase().includes('quota') || message.toLowerCase().includes('limit')) {
          setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
          renderQuota({ ...currentQuota, is_exhausted: true, remaining_chars: 0 });
        }
      } finally {
        previewBtn.disabled = false;
        previewBtn.querySelector('span').innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor"/></svg>' + i18n.generate_voice;
        setTimeout(() => heroWave?.classList.remove('active'), 900);
      }
    });

    document.querySelectorAll('[data-sample]').forEach((btn) => {
      btn.addEventListener('click', () => { textInput.value = btn.getAttribute('data-sample') || ''; updateCharCount(); });
    });
    form?.addEventListener('submit', (event) => { event.preventDefault(); previewBtn?.click(); });

    voiceTier?.addEventListener('change', () => { renderVoiceOptions(); renderDialectOptions(); applyStudioTierState(); });
    targetLanguage?.addEventListener('change', () => {
      if (isTranslateEnabled()) preferredTranslateLanguage = normalizeLanguage(targetLanguage.value);
      renderVoiceOptions(); renderDialectOptions(); applyStudioTierState();
    });
    voiceGender?.addEventListener('change', renderVoiceOptions);
    voiceSelect?.addEventListener('change', () => { rememberCurrentVoiceSelection(); });
    translateToggle?.addEventListener('change', () => {
      if (translateSelect) translateSelect.value = translateToggle.checked ? '1' : '0';
      if (translateToggle.checked) restorePreferredTranslationLanguage();
      else { preferredTranslateLanguage = normalizeLanguage(targetLanguage?.value); syncLanguageToCurrentTextWhenTranslationOff(oppositeLanguage(preferredTranslateLanguage)); }
      updateTranslateHint();
    });
    textInput?.addEventListener('input', () => {
      if (currentQuota.remaining_chars <= 0) setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
      updateCharCount();
    });

    function updateCharCount() {
      const counter = document.getElementById('charCounter');
      if (!counter || !textInput) return;
      const count = countBillableChars(textInput.value);
      counter.textContent = count > 0 ? `${formatNumber(count)} / ${formatNumber(currentQuota.remaining_chars)}` : '';
    }

    if (translateToggle && translateSelect) translateToggle.checked = translateSelect.value === '1';
    if (!isTranslateEnabled()) preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    renderVoiceOptions();
    renderDialectOptions();
    applyStudioTierState();
    updateTranslateHint();
    renderQuota(currentQuota);
    ensureFingerprint().catch(() => {});

    // Reveal on scroll animation
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('show'); revealObserver.unobserve(e.target); } });
    }, { threshold: 0.08 });
    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));
  </script>
  <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
