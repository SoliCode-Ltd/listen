@include('components.page-head', ['pageTitle' => __('ui.seo.demo_title'), 'page' => 'demo'])
  <link rel="stylesheet" href="{{ asset('css/voice-studio.css') }}?v={{ filemtime(public_path('css/voice-studio.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/demo-studio.css') }}?v={{ filemtime(public_path('css/demo-studio.css')) }}">
  @if($dubStudio ?? null)
    <link rel="stylesheet" href="{{ asset('css/dub-studio.css') }}?v={{ filemtime(public_path('css/dub-studio.css')) }}">
  @endif
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
      {{-- What the studio below can do; the counts come from the dialect and accent catalogs. --}}
      @php $demoAr = app()->getLocale() === 'ar'; @endphp
      <div class="studio-badge-row">
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <span class="num" dir="ltr">{{ $demoAr ? '+'.\App\Support\DialectCounts::arabic() : \App\Support\DialectCounts::arabic().'+' }}</span> {{ __('ui.demo.arabic_dialects') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
          <span class="num" dir="ltr">{{ $demoAr ? '+'.\App\Support\DialectCounts::english() : \App\Support\DialectCounts::english().'+' }}</span> {{ __('ui.demo.english_accents') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          {{ __('ui.demo.emotions_and_sounds') }}
        </span>
        <span class="studio-badge">
          <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          {{ __('ui.demo.hero_point_4') }}
        </span>
      </div>
    </section>

    {{-- ── Studio workspace: laid out like the dashboard generator ── --}}
    <section class="studio-workspace" @if($dubStudio ?? null) data-studio-host data-studio="tts" @endif>
      {{-- Text to speech or Dub a video: the same voices and choices, a script or a video. --}}
      @if($dubStudio ?? null)
        <x-dub.mode-switch />
      @endif
      {{-- An email sign-up confirms its address first: what to do, with a button for a new link. --}}
      @include('components.verify-email-notice', ['verifyEmail' => $verifyEmail ?? null, 'verifyEmailTitle' => __('ui.verify_email.notice_title')])
      {{-- Quota --}}
      <div class="s-card reveal" id="quotaBox" data-studio-pane="tts" style="padding:1rem 1.25rem">
        <div class="quota-panel">
          <div class="q-head">
            <span class="q-label">{{ __('ui.demo.trial_allowance') }}</span>
            <strong class="q-value" id="quotaValue" dir="ltr">
              @php
                $qTotalRemaining = (int) ($demoQuota['total_remaining_chars'] ?? ($demoQuota['remaining_chars'] ?? $trialAllowanceChars));
                $qTotalLimit = max(
                    $qTotalRemaining,
                    (int) ($demoQuota['limit_chars'] ?? $trialAllowanceChars) + (int) ($demoQuota['reward_chars_earned'] ?? 0)
                );
              @endphp
              {{ number_format($qTotalRemaining) }} / {{ number_format($qTotalLimit) }} {{ __('ui.demo.chars_left') }}
            </strong>
          </div>
          <div class="quota-track">
            <div class="quota-fill" id="quotaFill"></div>
          </div>
          <div class="quota-note">{{ __('ui.demo.one_time_trial_credit') }}</div>
          <div class="reward-badge" id="rewardBadge"
               @if ((int) ($demoQuota['reward_chars_remaining'] ?? 0) <= 0) hidden @endif
               aria-live="polite">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16">
              <path d="M20 12v9H4v-9"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
            </svg>
            <span>{{ __('ui.referral.reward_badge_prefix') }}</span>
            <strong dir="ltr"><span data-reward-value>{{ number_format((int) ($demoQuota['reward_chars_remaining'] ?? 0)) }}</span></strong>
            <span>{{ __('ui.referral.reward_badge_suffix') }}</span>
          </div>
        </div>
      </div>

      @if($dubStudio ?? null)
        {{-- The free video, where the text studio shows its trial characters (the dubbing studio's own box stays hidden here). --}}
        @php $dubLeft = in_array($dubStudio['state'], ['used', 'closed'], true) ? 0 : 1; @endphp
        <div class="s-card" data-studio-pane="dub" data-dub-allowance style="padding:1rem 1.25rem; margin-bottom:1.1rem">
          <div class="quota-panel">
            <div class="q-head">
              <span class="q-label">{{ __('ui.dub.allowance_label') }}</span>
              <strong class="q-value" data-dub-allowance-value>{{ __('ui.dub.js.allowance_value', ['left' => $dubLeft]) }}</strong>
            </div>
            <div class="quota-track">
              <div class="quota-fill" data-dub-allowance-fill style="width: {{ $dubLeft ? 100 : 0 }}%; background: linear-gradient(90deg, var(--primary-color, #8b5cf6), var(--secondary-color, #ec4899))"></div>
            </div>
            <div class="quota-note">{{ __('ui.dub.allowance_note', ['seconds' => $dubStudio['max_seconds']]) }}</div>
          </div>
        </div>
      @endif

      <form id="demoForm" class="vs-layout demo-vs-layout" data-studio-pane="tts">
        @php
          $isArabicLocale = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
          // Premium samples use the studio tags: [delivery] from that point on, <sound> where it happens.
          $defaultDemoSampleAr = '[هادئ] بقالك قد إيه بتقول هبدأ؟ <ضحكة خفيفة> كل مرة تقول… بكرة. <تنهيدة> بس بكرة دي مش بتيجي. <وقفة قصيرة> [دافئ] مش محتاج تبقى جاهز مية في المية… ابدأ بس، والباقي هييجي.';
          $defaultDemoSampleEn = "[calm] How long have you been telling yourself you'll start? <chuckle> Every time you say… tomorrow. <sigh> But tomorrow never comes. <short pause> [warm] You don't need to be completely ready… Just start, and the rest will follow.";
          // Standard voices (Google WaveNet / Chirp 3 HD) read every character,
          // so their samples are plain sentences: no timings, [tags] or (cues).
          $defaultStandardSampleAr = 'منذ متى وأنت تقول لنفسك إنك ستبدأ؟ في كل مرة تقول: غداً. لكن الغد لا يأتي أبداً. لست بحاجة إلى أن تكون مستعداً تماماً. ابدأ فقط، وسيأتي الباقي.';
          $defaultStandardSampleEn = "How long have you been telling yourself you'll start? Every time, you say: tomorrow. But tomorrow never comes. You don't need to be completely ready. Just start, and the rest will follow.";
          $sampleSetting = fn (string $key, string $default): string => ($value = trim((string) \App\Models\SiteSetting::get($key, ''))) !== '' ? $value : $default;
          $demoSampleScripts = [
            'hd' => [
              'ar' => $sampleSetting('demo_sample_ar', $defaultDemoSampleAr),
              'en' => $sampleSetting('demo_sample_en', $defaultDemoSampleEn),
            ],
            'standard' => [
              'ar' => $sampleSetting('demo_sample_standard_ar', $defaultStandardSampleAr),
              'en' => $sampleSetting('demo_sample_standard_en', $defaultStandardSampleEn),
            ],
          ];
        @endphp
        {{-- Left: the text, with its settings above and the generate button below --}}
        <div class="vs-main">
          {{-- The demo always uses the premium voices. --}}
          <input type="hidden" id="voice_tier" name="voice_tier" value="{{ $defaultTier }}">
          {{-- One column (tablets and phones): the voice library sits below, so say which voice is chosen here. --}}
          <button type="button" class="vs-voice-jump" id="voiceJump" hidden>
            <span class="vs-voice-jump-face" id="voiceJumpFace" aria-hidden="true"></span>
            <span class="vs-voice-jump-text">
              <small>{{ __('ui.dashboard.voice_jump_label') }}</small>
              <strong id="voiceJumpName"></strong>
            </span>
            <span class="vs-voice-jump-change">{{ __('ui.dashboard.voice_jump_change') }}</span>
          </button>
          <div class="vs-editor-card reveal">
            <x-voice-studio.setbar studio-id="studioPanel" :styles="$studioPerformanceStyles ?? []" :emotions="$studioEmotions ?? []">
              <div>
                <label class="vs-field-label" for="target_language">{{ __('ui.dashboard.voice_language') }}</label>
                <select id="target_language" name="target_language">
                  <option value="ar">{{ __('ui.dashboard.arabic') }}</option>
                  <option value="en">{{ __('ui.dashboard.english') }}</option>
                </select>
              </div>
            </x-voice-studio.setbar>
            <div class="tag-editor" data-vs-editor>
              <div class="tag-editor-backdrop" data-vs-backdrop aria-hidden="true" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}"></div>
              <textarea id="text" name="text" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}" placeholder="{{ __('ui.dashboard.paste_text') }}" aria-label="{{ __('ui.dashboard.text_box_label') }}" required></textarea>
            </div>
            <div class="demo-samples">
              <div class="sample-chips">
                <button class="sample-chip" type="button" data-sample-key="en">{{ __('ui.demo.english_sample') }}</button>
                <button class="sample-chip" type="button" data-sample-key="ar">{{ __('ui.demo.arabic_sample') }}</button>
              </div>
              <div class="sample-human-note" role="note">
                <strong>{{ $isArabicLocale ? 'ملاحظة احترافية' : 'Pro Tip' }}</strong>
                <span>{{ $isArabicLocale ? 'اضغط على عينات النص بالأعلى (عربي / إنجليزي) لتعبئة أمثلة جاهزة. ثم أضف الأصوات والمشاعر من شريط الوسوم فوق النص ليبدو الصوت بشريًا أكثر.' : 'Click the sample chips above (Arabic / English) to load ready scripts, then add sounds and feelings from the tag bar above the text for a more human voice.' }}</span>
              </div>
            </div>
            <div class="vs-editor-foot">
              <span class="char-count" id="charCounter" dir="ltr"></span>
              <div class="generate-row">
                <div class="wave-bar" id="heroWave" aria-hidden="true">
                  <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                  <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                </div>
                <button class="btn-generate" type="button" id="previewBtn">
                  <span>
                    <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    {{ __('ui.demo.generate_voice') }}
                  </span>
                </button>
              </div>
            </div>
          </div>
          {{-- One red notice under the generate button: the error, with the upgrade button when more credit would help. --}}
          <div id="errorBox" class="s-card error-box" role="alert" style="display:none;">
            <span class="error-text" id="errorText"></span>
            <a class="btn btn-primary error-upgrade" id="errorUpgrade" href="{{ route('billing.page', ['plan' => $upgradePlanSlug]) }}" hidden>{{ __('ui.demo.upgrade_plan') }}</a>
          </div>
        </div>

        {{-- Right: the voice library and fine-tuning (shared with the dashboard) --}}
        <aside class="vs-side reveal">
          <x-voice-studio.panel :show-tags="true">
            {{-- The library filters speakers by gender itself; the page script still reads this field. --}}
            <select id="voice_gender" class="vs-hidden-field" tabindex="-1" aria-hidden="true">
              <option value="all">{{ __('ui.demo.any') }}</option>
            </select>
            {{-- Sound environments, as on the dashboard: added to the voice after it is made. --}}
            @if(\App\Support\AudioEffects::available())
              <div class="vs-field vs-effect-field">
                <x-voice-studio.choice-field name="audio_effect" :label="__('ui.dashboard.audio_effect')" :options="\App\Support\AudioEffects::options()" :default-label="__('ui.dashboard.audio_effect_none')" />
              </div>
            @endif
            {{-- Dialogue mode: premium voices only. --}}
            <x-voice-studio.dialogue />
            <x-voice-studio.speed-pitch data-vs-hd-only />
            <div class="s-card vs-field">
              <label class="compact-toggle-row" for="translateToggle">
                <div class="txt">
                  <div class="t-title">🌐 {{ __('ui.demo.translate_before_speaking') }}</div>
                  <div class="t-desc" id="translateHint">{{ __('ui.demo.translate_disabled_hint') }}</div>
                </div>
                <div class="toggle-switch" aria-label="{{ __('ui.demo.translate_before_speaking') }}">
                  <input type="checkbox" id="translateToggle">
                  <span class="toggle-slider"></span>
                </div>
              </label>
              <input type="hidden" name="translate" id="translate" value="0">
            </div>
          </x-voice-studio.panel>
        </aside>
      </form>

      {{-- Below, as on the dashboard: the result --}}
      <div class="demo-output" data-studio-pane="tts">

        <div class="s-card output-panel" id="audioWrap" style="display:none;">
          <div class="panel-title">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            {{ __('ui.demo.hero_point_4') }}
          </div>
          <audio
            id="previewAudio"
            controls
            preload="none"
            data-download-redirect-url="{{ route('billing.page', ['plan' => $upgradePlanSlug]) }}"
          ></audio>
          <div class="audio-meta" id="audioMeta" role="status"></div>
        </div>

        <div class="s-card output-panel reveal" id="outputPlaceholder">
          <div class="output-placeholder">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            {{ __('ui.demo.hero_point_4') }}
          </div>
        </div>
      </div>

      {{-- The free dub: the same studio around a video (components/dub/studio.blade.php). --}}
      @if($dubStudio ?? null)
        <x-dub.studio :dub="$dubStudio" :compact-meter="true" />
      @endif
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $demoI18n = [
      'upgrade_text' => __('ui.demo.trial_exhausted_text'),
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
      'trial_exhausted' => __('ui.demo.trial_exhausted_text'),
      'speaker_prefix' => __('ui.demo.speaker_prefix'),
      'performance_style_required' => __('ui.backend.performance_style_required'),
      'emotion_required' => __('ui.backend.emotion_required'),
      'ready_in_seconds' => __('ui.demo.ready_in_seconds'),
      'preparing_next' => __('ui.demo.preparing_next'),
      'checking_allowance' => __('ui.demo.checking_allowance'),
      'quota_sync_failed' => __('ui.demo.quota_sync_failed'),
    ];
  @endphp
  <script src="{{ asset('js/dialogue-mode.js') }}?v={{ filemtime(public_path('js/dialogue-mode.js')) }}"></script>
  <script>
    const voiceCatalogByTier = @json($voiceCatalog);
    const defaultVoiceKeyByTierAndLanguage = @json($defaultVoiceKeysByTier ?? []);
    const fallbackDefaultVoiceKeyByLanguage = {
      ar: String(@json((string) data_get($defaultVoiceAr, 'key', '')) || '').trim(),
      en: String(@json((string) data_get($defaultVoiceEn, 'key', '')) || '').trim(),
    };
    const studioDefaultsByLanguage = @json($studioDefaultsByLanguage ?? []);
    const studioDialectsByLanguageRaw = @json($studioDialectsByLanguage ?? []);
    const uiLocale = @json(app()->getLocale());
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
    const errorText = document.getElementById('errorText');
    const errorUpgrade = document.getElementById('errorUpgrade');
    const translateSelect = document.getElementById('translate');
    const translateToggle = document.getElementById('translateToggle');
    const translateHint = document.getElementById('translateHint');
    let feedbackScrollFrame = null;
    const heroWave = document.getElementById('heroWave');
    const textInput = document.getElementById('text');
    const charCounter = document.getElementById('charCounter');
    const outputPlaceholder = document.getElementById('outputPlaceholder');
    const sampleScripts = {!! \Illuminate\Support\Js::from($demoSampleScripts ?? ['hd' => ['ar' => '', 'en' => ''], 'standard' => ['ar' => '', 'en' => '']]) !!};
    const sampleTierKey = () => (String(voiceTier?.value || '').toLowerCase() === 'standard' ? 'standard' : 'hd');
    const sampleFor = (tier, lang) => String(sampleScripts?.[tier]?.[lang] || '').trim();

    const autoDialectLabel = '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}';
    const dialectsByLanguage = {
      ar: [{ value: '', label: autoDialectLabel }],
      en: [{ value: '', label: autoDialectLabel }],
    };
    ['ar', 'en'].forEach((langCode) => {
      const rows = Array.isArray(studioDialectsByLanguageRaw?.[langCode]) ? studioDialectsByLanguageRaw[langCode] : [];
      rows.forEach((row) => {
        const value = String(row?.value || '').trim();
        if (!value) return;
        const localizedLabel = uiLocale === 'ar'
          ? String(row?.label_ar || row?.label || value).trim()
          : String(row?.label || row?.label_ar || value).trim();
        dialectsByLanguage[langCode].push({ value, label: localizedLabel || value });
      });
    });

    function renderDialectOptions() {
      if (!dialectSelect || !targetLanguage) return;
      const lang = normalizeLanguage(targetLanguage.value);
      const dialects = dialectsByLanguage[lang] || [{ value: '', label: autoDialectLabel }];
      const dialectField = dialectSelect.closest('[data-vs-dialect-field]');
      if (dialectField) dialectField.hidden = !dialectsByLanguage[lang];
      dialectSelect.innerHTML = '';
      for (const d of dialects) {
        const opt = document.createElement('option');
        opt.value = d.value;
        opt.textContent = d.label;
        dialectSelect.appendChild(opt);
      }
    }

    function setSelectValueIfAvailable(selectEl, value) {
      const normalized = String(value || '').trim();
      if (!selectEl || !normalized) return false;
      const hasOption = Array.from(selectEl.options || []).some((opt) => String(opt.value || '') === normalized);
      if (!hasOption) return false;
      selectEl.value = normalized;
      return true;
    }

    function applyConfiguredStudioDefaults() {
      const lang = normalizeLanguage(targetLanguage?.value || 'ar');
      const tier = normalizeTier(voiceTier?.value || 'standard');
      if (tier !== 'hd') return;
      const defaults = studioDefaultsByLanguage?.[lang] || {};

      // Style and emotion stay on "Default" until the visitor picks one.
      if (dialectSelect && !String(dialectSelect.value || '').trim()) {
        setSelectValueIfAvailable(dialectSelect, defaults.dialect || '');
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
      } else {
        applyConfiguredStudioDefaults();
      }
    }
    const demoQuota = @json($demoQuota ?? null);
    const trialAllowanceChars = Number(@json((int) $trialAllowanceChars));
    const i18n = @json($demoI18n);
    // Until the email is confirmed, the notice above takes the place of Generate.
    const emailVerificationRequired = @json(! empty($verifyEmail));
    const pageLocale = document.documentElement.lang || 'en';
    const isArabicPageLocale = String(pageLocale).toLowerCase().startsWith('ar');

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    function toFiniteNumber(value, fallback) {
      const num = Number(value);
      return Number.isFinite(num) ? num : fallback;
    }

    function toBoolean(value) {
      if (typeof value === 'boolean') return value;
      if (typeof value === 'number') return value !== 0;
      const normalized = String(value ?? '').trim().toLowerCase();
      if (!normalized) return false;
      return normalized === '1' || normalized === 'true' || normalized === 'yes';
    }

    function normalizeQuotaState(quota, fallback = null) {
      const fallbackLimit = Math.max(1, toFiniteNumber(fallback?.limit_chars, trialAllowanceChars || 300));
      const limit = Math.max(1, toFiniteNumber(quota?.limit_chars, fallbackLimit));
      let remaining = Math.max(0, toFiniteNumber(quota?.remaining_chars, toFiniteNumber(fallback?.remaining_chars, limit)));
      let used = Math.max(0, toFiniteNumber(quota?.used_chars, limit - remaining));
      const rewardRemaining = Math.max(0, toFiniteNumber(quota?.reward_chars_remaining, toFiniteNumber(fallback?.reward_chars_remaining, 0)));
      const rewardEarned = Math.max(0, toFiniteNumber(quota?.reward_chars_earned, toFiniteNumber(fallback?.reward_chars_earned, 0)));
      let totalRemaining = toFiniteNumber(quota?.total_remaining_chars, remaining + rewardRemaining);
      if (!Number.isFinite(totalRemaining) || totalRemaining < 0) totalRemaining = remaining + rewardRemaining;
      let exhausted = toBoolean(quota?.is_exhausted);

      if (totalRemaining <= 0) exhausted = true;
      if (exhausted) {
        used = limit;
      } else {
        used = Math.min(limit, used);
      }
      if (remaining <= 0) remaining = 0;

      return {
        ...quota,
        limit_chars: limit,
        remaining_chars: remaining,
        used_chars: used,
        is_exhausted: exhausted,
        reward_chars_remaining: rewardRemaining,
        reward_chars_earned: rewardEarned,
        total_remaining_chars: totalRemaining,
      };
    }

    let currentQuota = normalizeQuotaState(demoQuota, {
      limit_chars: trialAllowanceChars || 300,
      remaining_chars: trialAllowanceChars || 300,
      used_chars: 0,
      is_exhausted: false,
    });
    let preferredTranslateLanguage = ['ar', 'en'].includes(targetLanguage?.value) ? targetLanguage.value : 'ar';
    const preferredVoiceKeyByLanguageAndTier = {};
    let quotaRefreshPromise = null;
    let isQuotaRefreshing = false;
    let isGenerating = false;
    let hasQuotaSynced = !toBoolean(currentQuota?.requires_client_sync);
    let liveBillableChars = 0;
    // Set when Generate was refused because the text is longer than the credit left;
    // the error and the upgrade offer stay until the text fits again.
    let overflowNoticeShown = false;
    let charCountFrame = null;
    let lastGeneratedText = '';
    let lastGeneratedChars = 0;

    // Cooldown state for rate limiting with good UX
    let cooldownEndTime = 0;
    let cooldownIntervalId = null;
    const COOLDOWN_SECONDS = 6; // Seconds to wait between generations

    const previewButtonIcon = '<svg viewBox="0 0 24 24" width="18" height="18"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor"/></svg>';
    const previewButtonSpinner = '<svg viewBox="0 0 24 24" width="18" height="18" style="animation:spin .8s linear infinite;fill:currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z" opacity=".3"/><path d="M20 12h2A10 10 0 0 0 12 2v2a8 8 0 0 1 8 8z"/></svg>';
    const previewButtonCooldownIcon = '<svg viewBox="0 0 24 24" width="18" height="18" style="opacity:0.5"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15v-4H7l5-7v4h4l-5 7z" fill="currentColor"/></svg>';

    function formatNumber(value) { return new Intl.NumberFormat('en-US').format(Number(value || 0)); }
    // Every character is charged (spaces, line breaks and punctuation too), as on the server.
    function countBillableChars(value) {
      return Array.from(String(value || '').trim()).length;
    }
    function currentDraftBillableChars() {
      return countBillableChars(String(textInput?.value || '').trim());
    }
    function currentDraftText() {
      return String(textInput?.value || '').trim();
    }
    function hasPendingDraftUsage() {
      const draftText = currentDraftText();
      if (liveBillableChars <= 0 || draftText === '') {
        return false;
      }

      return !(draftText === lastGeneratedText && liveBillableChars === lastGeneratedChars);
    }
    const speechLanguageCodes = @json(\App\Support\SpeechLanguages::codes());
    function normalizeLanguage(value) { const code = String(value || '').toLowerCase(); return speechLanguageCodes.includes(code) ? code : 'ar'; }
    // Arabic and English have their own speakers; the other languages are read by the English premium speakers.
    function voiceLanguageOf(value) { const code = normalizeLanguage(value); return code === 'ar' || code === 'en' ? code : 'en'; }
    function oppositeLanguage(value) { return normalizeLanguage(value) === 'ar' ? 'en' : 'ar'; }
    const latinLanguages = ['en', 'es', 'fr', 'de', 'it', 'pt', 'tr', 'nl', 'pl', 'id', 'vi'];
    const scriptLanguages = [
      ['ar', /[\u0600-\u06FF\u0750-\u077F]/u], ['ru', /[\u0400-\u04FF]/u], ['hi', /[\u0900-\u097F]/u],
      ['bn', /[\u0980-\u09FF]/u], ['ja', /[\u3040-\u30FF]/u], ['ko', /[\uAC00-\uD7AF\u1100-\u11FF]/u],
      ['th', /[\u0E00-\u0E7F]/u], ['ta', /[\u0B80-\u0BFF]/u], ['te', /[\u0C00-\u0C7F]/u],
      ['gu', /[\u0A80-\u0AFF]/u], ['kn', /[\u0C80-\u0CFF]/u], ['ml', /[\u0D00-\u0D7F]/u],
    ];
    function detectSourceLanguage(value) { const text = String(value || ''); for (const [code, pattern] of scriptLanguages) { if (pattern.test(text)) return code; } return 'en'; }
    function textFitsLanguage(source, lang) { return source === lang || (source === 'en' && latinLanguages.includes(lang)); }
    // The other languages need the premium voices.
    function syncPremiumLanguages() {
      if (!targetLanguage) return;
      const hd = normalizeTier(voiceTier?.value || 'standard') === 'hd';
      targetLanguage.querySelectorAll('[data-premium-language]').forEach((opt) => { opt.disabled = !hd; });
      if (!hd && !['ar', 'en'].includes(targetLanguage.value)) targetLanguage.value = 'en';
    }
    function isTranslateEnabled() { return String(translateSelect?.value || '1') === '1'; }
    function voicePreferenceBucket(language = normalizeLanguage(targetLanguage?.value), tier = normalizeTier(voiceTier?.value || 'standard')) {
      return `${normalizeLanguage(language)}:${normalizeTier(tier)}`;
    }
    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value), tier = normalizeTier(voiceTier?.value || 'standard')) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguageAndTier[voicePreferenceBucket(language, tier)] = selectedVoiceKey;
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

    // The notice shows the error, or on its own the "trial used" text, and the
    // upgrade button whenever more credit would help.
    let noticeError = '';
    let noticeUpgrade = false;
    function renderNotice() {
      const text = noticeError || (noticeUpgrade ? i18n.upgrade_text : '');
      errorBox.style.display = text ? '' : 'none';
      errorText.textContent = text;
      errorUpgrade.hidden = !noticeUpgrade;
    }

    // Shown as given: callEndpoint has already put a server error in friendly words.
    function setError(message = '') {
      if (!message) { noticeError = ''; renderNotice(); return; }
      noticeError = message;
      renderNotice();
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
        // Clear of the floating WhatsApp and gift buttons at the bottom of the screen.
        const bottomOffset = 110;
        const isVisible = rect.top >= topOffset && rect.bottom <= (window.innerHeight - bottomOffset);
        if (isVisible) return;
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView({
          behavior: reduceMotion ? 'auto' : 'smooth',
          block: 'center',
          inline: 'nearest',
        });
      });
    }

    function setUpgradeVisible(show) {
      noticeUpgrade = Boolean(show);
      renderNotice();
    }

    // Referral popup invocation policy:
    //   post_generation — success moment, soft (respect user dismissal), once per tab
    //   quota_exhausted — peak conversion moment, forced, once per tab
    //   preview_overflow — selected text exceeds remaining chars, forced, once per tab
    const refPopupShownByReason = Object.create(null);
    function maybeShowReferralPopup({ reason }) {
      if (!reason || refPopupShownByReason[reason]) return;
      if (typeof window.openReferralPopup !== 'function') return;
      const force = reason === 'quota_exhausted' || reason === 'preview_overflow';
      // Peak-intent moments always show, even if dismissed earlier.
      const shown = window.openReferralPopup(force ? { force: true } : undefined);
      if (shown !== false) refPopupShownByReason[reason] = true;
    }

    function setPreviewButtonMarkup(iconMarkup, label) {
      if (!previewBtn) return;
      const content = previewBtn.querySelector('span');
      if (!content) return;
      content.innerHTML = `${iconMarkup}${label}`;
    }

    function resetPreviewButton() {
      if (!previewBtn) return;
      const cooldownRemaining = getRemainingCooldown();
      if (cooldownRemaining > 0) {
        previewBtn.disabled = true;
        updateCooldownDisplay();
        return;
      }

      const shouldDisable = emailVerificationRequired || isQuotaRefreshing || isGenerating || Boolean(currentQuota.is_exhausted);
      previewBtn.disabled = shouldDisable;
      setPreviewButtonMarkup(previewButtonIcon, i18n.generate_voice);
    }

    function setPreviewButtonBusy(label) {
      if (!previewBtn) return;
      previewBtn.disabled = true;
      setPreviewButtonMarkup(previewButtonSpinner, label);
    }

    function renderQuota(quota) {
      if (!quota || typeof quota !== 'object') return;
      currentQuota = normalizeQuotaState({ ...currentQuota, ...quota }, currentQuota);
      syncQuotaDisplay();
    }

    function syncQuotaDisplay() {
      const baseRemaining = Math.max(0, Number(currentQuota.remaining_chars || 0));
      const rewardRemaining = Math.max(0, Number(currentQuota.reward_chars_remaining || 0));
      const rewardEarned = Math.max(0, Number(currentQuota.reward_chars_earned || 0));
      const limit = Math.max(1, Number(currentQuota.limit_chars || 1));
      const hasDraft = hasPendingDraftUsage();
      const totalRemaining = Math.max(0, baseRemaining + rewardRemaining);
      const totalLimit = Math.max(totalRemaining, limit + rewardEarned);
      const displayRemaining = hasDraft ? Math.max(0, totalRemaining - liveBillableChars) : totalRemaining;
      const rawPercent = Math.max(0, Math.min(100, Math.round((displayRemaining / totalLimit) * 100)));
      const percent = displayRemaining > 0 ? Math.max(1, rawPercent) : 0;

      if (quotaFill) {
        quotaFill.style.width = `${percent}%`;
        quotaFill.style.background = 'linear-gradient(90deg, var(--primary-color, #8b5cf6), var(--secondary-color, #ec4899))';
      }
      if (quotaValue) {
        quotaValue.textContent = `${formatNumber(displayRemaining)} / ${formatNumber(totalLimit)} ${i18n.chars_left}`;
      }

      renderRewardBadge(rewardRemaining);
      const exhausted = Boolean(currentQuota.is_exhausted || totalRemaining <= 0);
      const overLimit = hasDraft && liveBillableChars > totalRemaining;
      if (overflowNoticeShown && !overLimit) {
        overflowNoticeShown = false;
        setError('');
      }
      setUpgradeVisible(exhausted || (overflowNoticeShown && overLimit));
      resetPreviewButton();
    }

    function renderRewardBadge(rewardRemaining) {
      const badge = document.getElementById('rewardBadge');
      if (!badge) return;
      if (rewardRemaining > 0) {
        const valueNode = badge.querySelector('[data-reward-value]');
        if (valueNode) valueNode.textContent = formatNumber(rewardRemaining);
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    }

    function isTrialExhaustedMessage(message) {
      const normalized = String(message || '').trim().toLowerCase();
      if (!normalized) return false;

      const markers = [
        'trial allowance has been used',
        'trial allowance is used',
        'used your trial allowance',
        'انتهى رصيدك التجريبي',
        'انتهي رصيدك التجريبي',
      ];

      return markers.some((marker) => normalized.includes(marker.toLowerCase()));
    }

    // Browser fingerprint for quota abuse prevention
    const fingerprintComponents = {};
    const deviceSignals = { id: '', touch: false };

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

    // The kind of browser and system, without version numbers.
    function browserFamily() {
      const ua = navigator.userAgent || '';
      const browser = /Edg\//.test(ua) ? 'edge' : /OPR\/|Opera/.test(ua) ? 'opera' : /Firefox\//.test(ua) ? 'firefox'
        : /Chrome\/|CriOS\//.test(ua) ? 'chrome' : /Safari\//.test(ua) ? 'safari' : 'other';
      const system = /Windows/.test(ua) ? 'windows' : /Android/.test(ua) ? 'android' : /iPhone|iPad|iPod/.test(ua) ? 'ios'
        : /Mac OS X|Macintosh/.test(ua) ? 'mac' : /CrOS/.test(ua) ? 'chromeos' : /Linux/.test(ua) ? 'linux' : 'other';
      return browser + ':' + system;
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

      // The device itself, the same in an incognito window (App\Services\DemoLinkedAccounts):
      // not the window's size, the zoom, the sound test (it can time out) or the
      // browser's version, which change by themselves.
      deviceSignals.touch = (navigator.maxTouchPoints || 0) > 0 || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
      deviceSignals.id = await sha256([
        fingerprintComponents.canvas,
        fingerprintComponents.webgl,
        fingerprintComponents.fonts,
        String(navigator.hardwareConcurrency || ''),
        String(navigator.deviceMemory || ''),
        String(navigator.maxTouchPoints || ''),
        navigator.platform || '',
        Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        browserFamily(),
      ].join('|'));

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

    // Build fingerprint on load
    let clientFingerprint = '';
    let fingerprintPromise = null;
    ensureFingerprint();

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1' ? i18n.translate_enabled_hint : i18n.translate_disabled_hint;
    }

    function getFilteredVoices() {
      const tier = voiceTier?.value || 'standard';
      const lang = voiceLanguageOf(targetLanguage.value);
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

    function findPreferredArabicHdVoiceKey(voices, language, tier) {
      if (normalizeLanguage(language) !== 'ar' || normalizeTier(tier) !== 'hd') return '';
      const preferredLabels = ['fahad', 'fahd', 'فهد'];
      const match = (Array.isArray(voices) ? voices : []).find((voice) => {
        const label = String(voice?.label || '').trim().toLowerCase();
        const labelAr = String(voice?.label_ar || '').trim().toLowerCase();
        return preferredLabels.some((candidate) => label === candidate || labelAr === candidate || label.includes(candidate) || labelAr.includes(candidate));
      });
      return String(match?.key || '').trim();
    }

    function renderVoiceOptions() {
      if (!voiceSelect || !targetLanguage) return;
      const voices = getFilteredVoices();
      const lang = normalizeLanguage(targetLanguage.value);
      const tier = normalizeTier(voiceTier?.value || 'standard');
      const preferenceBucket = voicePreferenceBucket(lang, tier);
      const remembered = String(preferredVoiceKeyByLanguageAndTier[preferenceBucket]||'').trim();
      const defaultVoiceKey = String(
        defaultVoiceKeyByTierAndLanguage?.[tier]?.[voiceLanguageOf(lang)]
        || fallbackDefaultVoiceKeyByLanguage?.[voiceLanguageOf(lang)]
        || ''
      ).trim();
      const preferredArabicHdVoiceKey = findPreferredArabicHdVoiceKey(voices, lang, tier);
      const current = String(remembered || defaultVoiceKey || preferredArabicHdVoiceKey || '').trim();
      voiceSelect.innerHTML = '';
      if (!voices.length) { voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>'; preferredVoiceKeyByLanguageAndTier[preferenceBucket] = ''; return; }
      for (const voice of voices) { const option = document.createElement('option'); option.value = voice.key; option.textContent = voiceDisplayLabel(voice); option.dataset.avatar = voice.avatar || ''; voiceSelect.appendChild(option); }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
      preferredVoiceKeyByLanguageAndTier[preferenceBucket] = String(voiceSelect.value||'').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !textInput || isTranslateEnabled()) return false;
      const text = String(textInput.value||'').trim();
      if (!text) { const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null; if (!fallback || !['ar', 'en'].includes(normalizeLanguage(targetLanguage.value))) return false; const currentLanguage = normalizeLanguage(targetLanguage.value); if (currentLanguage === fallback) return false; rememberCurrentVoiceSelection(currentLanguage); targetLanguage.value = fallback; renderVoiceOptions(); renderDialectOptions(); applyStudioTierState(); return true; }
      const sourceLanguage = detectSourceLanguage(text);
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      if (textFitsLanguage(sourceLanguage, currentLanguage)) return false;
      const sourceOption = Array.from(targetLanguage.options || []).find((opt) => opt.value === sourceLanguage);
      if (!sourceOption || sourceOption.disabled) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = sourceLanguage;
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioTierState();
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
      renderDialectOptions();
      applyStudioTierState();
      return true;
    }

    // Dialogue mode (js/dialogue-mode.js): two speakers, each with a premium voice
    // and their own dialect or accent. Premium voices only.
    const dialogueMode = window.ListenDialogue?.init({
      textarea: textInput,
      language: () => normalizeLanguage(targetLanguage?.value),
      voices: (lang) => sortVoices(Array.isArray(voiceCatalogByTier?.hd?.[lang]) ? voiceCatalogByTier.hd[lang] : []),
      dialects: (lang) => dialectsByLanguage[lang] || [],
      voiceLabel: voiceDisplayLabel,
      allowed: () => normalizeTier(voiceTier?.value || 'standard') === 'hd',
    });
    targetLanguage?.addEventListener('change', () => dialogueMode?.refresh());

    // One column, as on the dashboard: the chosen voice above the text; a tap scrolls to the library.
    (function () {
      const jump = document.getElementById('voiceJump');
      const nameEl = document.getElementById('voiceJumpName');
      const faceEl = document.getElementById('voiceJumpFace');
      if (!jump || !voiceSelect || !nameEl || !faceEl) return;
      let voices = {};
      try {
        voices = (JSON.parse(document.querySelector('#demoForm [data-vs-meta]')?.textContent || '{}') || {}).voices || {};
      } catch (e) { /* the button still names the voice */ }
      function refresh() {
        const option = voiceSelect.options[voiceSelect.selectedIndex];
        if (!option || !option.value) { jump.hidden = true; return; }
        const label = String(option.textContent || '').trim();
        const avatar = String((voices[option.value] || {}).a || option.dataset.avatar || '');
        nameEl.textContent = label;
        faceEl.textContent = '';
        if (avatar) {
          const img = document.createElement('img');
          img.src = avatar;
          img.alt = '';
          img.width = 40;
          img.height = 40;
          faceEl.appendChild(img);
        } else {
          faceEl.textContent = label.charAt(0).toUpperCase();
        }
        jump.hidden = false;
      }
      voiceSelect.addEventListener('change', refresh);
      new MutationObserver(refresh).observe(voiceSelect, { childList: true });
      refresh();
      jump.addEventListener('click', function () {
        document.querySelector('#demoForm .vs-panel')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      });
    })();

    async function callEndpoint(endpoint, options = {}) {
      const includeForm = options?.includeForm !== false;
      overflowNoticeShown = false;
      setError('');
      setUpgradeVisible(false);
      const fd = new FormData();

      if (includeForm && form) {
        const formData = new FormData(form);
        for (const [key, value] of formData.entries()) {
          fd.append(key, value);
        }
      }

      if (!clientFingerprint) {
        try {
          await ensureFingerprint();
        } catch (_e) {}
      }

      const headers = { ...csrfHeaders };
      if (clientFingerprint) {
        headers['X-Client-FP'] = clientFingerprint;
      }
      if (deviceSignals.id) {
        headers['X-Client-Device'] = deviceSignals.id;
        headers['X-Client-Device-Touch'] = deviceSignals.touch ? '1' : '0';
      }

      let res;
      try {
        res = await fetch(endpoint, { method: 'POST', headers, body: fd });
      } catch (_networkError) {
        // No reply at all (offline, a dropped connection): never the browser's own words.
        const error = new Error(i18n.server_overload || i18n.request_failed);
        error.payload = null;
        throw error;
      }
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || data.ok === false) {
        // Decided by the reply, never by its wording: a refusal (a 4xx, or ok:false) in the
        // server's own words, even with a number in them such as a 1,500 allowance; the
        // "busy" text only for a server error without a message of ours, or a reply that is not JSON.
        const serverTrouble = !data || (res.status >= 500 && data.ok !== false);
        const firstError = data?.errors && typeof data.errors === 'object' ? Object.values(data.errors)[0] : null;
        const firstErrorText = Array.isArray(firstError) ? String(firstError[0]||'') : '';
        const error = new Error(serverTrouble
          ? (i18n.server_overload || i18n.request_failed)
          : String(data.message || firstErrorText || i18n.request_failed));
        error.payload = data;
        throw error;
      }
      return data;
    }

    async function refreshQuotaFromServer({ interactive = false } = {}) {
      if (quotaRefreshPromise) {
        return quotaRefreshPromise;
      }

      isQuotaRefreshing = true;
      setPreviewButtonBusy(i18n.checking_allowance);

      quotaRefreshPromise = (async () => {
        try {
          const data = await callEndpoint('{{ route("demo.quota") }}', { includeForm: false });
          renderQuota(data?.quota || null);
          currentQuota.requires_client_sync = false;
          hasQuotaSynced = true;

          return true;
        } catch (_error) {
          if (interactive) {
            setError(i18n.quota_sync_failed || i18n.request_failed);
          }

          return false;
        } finally {
          isQuotaRefreshing = false;
          quotaRefreshPromise = null;
          resetPreviewButton();
          updateCharCount();
        }
      })();

      return quotaRefreshPromise;
    }

    // Cooldown UI functions for smooth rate limiting
    function getRemainingCooldown() {
      return Math.max(0, Math.ceil((cooldownEndTime - Date.now()) / 1000));
    }

    function startCooldownCountdown(seconds) {
      if (cooldownIntervalId) clearInterval(cooldownIntervalId);
      cooldownEndTime = Date.now() + (seconds * 1000);
      previewBtn.disabled = true;
      updateCooldownDisplay();
      cooldownIntervalId = setInterval(() => {
        const remaining = getRemainingCooldown();
        if (remaining <= 0) {
          clearInterval(cooldownIntervalId);
          cooldownIntervalId = null;
          cooldownEndTime = 0;
          resetPreviewButton();
        } else {
          updateCooldownDisplay();
        }
      }, 1000);
    }

    function updateCooldownDisplay() {
      const remaining = getRemainingCooldown();
      const text = (i18n.ready_in_seconds || 'Ready in :seconds...').replace(':seconds', remaining);
      setPreviewButtonMarkup(previewButtonCooldownIcon, text);
    }

    previewBtn?.addEventListener('click', async () => {
      // Check if still in cooldown
      const remaining = getRemainingCooldown();
      if (remaining > 0) {
        return; // Silently ignore - button should be disabled anyway
      }
      heroWave?.classList.add('active');
      try {
        if (!hasQuotaSynced || toBoolean(currentQuota?.requires_client_sync)) {
          const synced = await refreshQuotaFromServer({ interactive: true });
          if (!synced) return;
        }
        if (!isTranslateEnabled()) syncLanguageToCurrentTextWhenTranslationOff();
        const rawText = String(textInput?.value || '');
        const trimmed = rawText.trim();
        const selectedChars = currentDraftBillableChars();
        if (!trimmed) { hideAudioPreview(); setError(i18n.text_required); return; }
        // Style and emotion left on "Default" are filled in by the server.
        const totalAllowed = Number(currentQuota.remaining_chars || 0) + Number(currentQuota.reward_chars_remaining || 0);
        if (selectedChars > totalAllowed) {
          hideAudioPreview();
          const message = i18n.selected_chars_exceed.replace(':selected', formatNumber(selectedChars)).replace(':remaining', formatNumber(totalAllowed));
          setError(message);
          overflowNoticeShown = totalAllowed > 0;
          setUpgradeVisible(true);
          maybeShowReferralPopup({ reason: totalAllowed <= 0 ? 'quota_exhausted' : 'preview_overflow' });
          return;
        }
        isGenerating = true;
        setPreviewButtonBusy(i18n.generating);
        hideAudioPreview();
        const data = await callEndpoint('{{ route("demo.preview") }}');
        lastGeneratedText = trimmed;
        lastGeneratedChars = selectedChars;
        renderQuota(data?.quota || null);
        updateCharCount();
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
        // Start cooldown after successful generation
        startCooldownCountdown(COOLDOWN_SECONDS);
        // Celebrate-and-share: after a successful generation, softly invite the
        // user to share for bonus chars. Once per tab, skipped if dismissed.
        maybeShowReferralPopup({ reason: 'post_generation' });
      } catch (err) {
        hideAudioPreview();
        const message = String(err?.message || i18n.preview_failed);
        const quotaFromError = err?.payload?.quota && typeof err.payload.quota === 'object'
          ? err.payload.quota
          : null;
        // Always trust the server's quota over the local state
        if (quotaFromError) {
          renderQuota(quotaFromError);
          updateCharCount();
        }
        setError(message);
        // Check if server returned a rate limit with retry seconds
        const secondsMatch = message.match(/(\d+)\s*(second|ثانية|ثواني)/i);
        const serverSaysExhausted = quotaFromError
          ? Boolean(Number(quotaFromError.remaining_chars || 0) <= 0 || quotaFromError.is_exhausted)
          : Boolean(Number(currentQuota.remaining_chars || 0) <= 0 || currentQuota.is_exhausted);
        if (secondsMatch) {
          startCooldownCountdown(parseInt(secondsMatch[1], 10));
        } else if (serverSaysExhausted || isTrialExhaustedMessage(message)) {
          setUpgradeVisible(true);
          renderQuota({ ...currentQuota, is_exhausted: true, remaining_chars: 0, used_chars: Number(currentQuota.limit_chars || 0) });
          maybeShowReferralPopup({ reason: 'quota_exhausted' });
        }
      } finally {
        isGenerating = false;
        resetPreviewButton();
        setTimeout(() => heroWave?.classList.remove('active'), 900);
      }
    });

    document.querySelectorAll('[data-sample], [data-sample-key]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const sampleKey = String(btn.getAttribute('data-sample-key') || '').trim().toLowerCase();
        const mappedScript = sampleKey ? sampleFor(sampleTierKey(), sampleKey) : '';
        const fallbackScript = String(btn.getAttribute('data-sample') || '').trim();
        textInput.value = mappedScript || fallbackScript;
        updateCharCount();
      });
    });
    form?.addEventListener('submit', (event) => { event.preventDefault(); previewBtn?.click(); });

    voiceTier?.addEventListener('change', () => {
      syncPremiumLanguages();
      // A loaded sample follows the quality: plain sentences for Standard,
      // the directed script for HD. Text the visitor typed is left alone.
      const current = String(textInput?.value || '').trim();
      const otherTier = sampleTierKey() === 'standard' ? 'hd' : 'standard';
      ['ar', 'en'].forEach((lang) => {
        if (current !== '' && current === sampleFor(otherTier, lang)) {
          textInput.value = sampleFor(sampleTierKey(), lang);
          updateCharCount();
        }
      });
      renderVoiceOptions(); renderDialectOptions(); applyStudioTierState();
    });
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
    function scheduleCharCountUpdate() {
      if (charCountFrame) cancelAnimationFrame(charCountFrame);
      charCountFrame = requestAnimationFrame(() => {
        charCountFrame = null;
        const liveTotalRemaining = Number(currentQuota.remaining_chars || 0) + Number(currentQuota.reward_chars_remaining || 0);
        if (liveTotalRemaining <= 0) setUpgradeVisible(true);
        updateCharCount();
      });
    }
    ['input', 'change', 'keyup', 'paste', 'cut'].forEach((eventName) => {
      textInput?.addEventListener(eventName, scheduleCharCountUpdate);
    });
    textInput?.addEventListener('compositionend', scheduleCharCountUpdate);

    function updateCharCount() {
      if (!charCounter || !textInput) return;
      const count = currentDraftBillableChars();
      liveBillableChars = count;
      const baseRemaining = Math.max(0, Number(currentQuota.remaining_chars || 0));
      const rewardRemaining = Math.max(0, Number(currentQuota.reward_chars_remaining || 0));
      const totalRemaining = baseRemaining + rewardRemaining;
      if (count > 0) {
        charCounter.textContent = `${formatNumber(count)} / ${formatNumber(totalRemaining)}`;
        charCounter.style.color = count > totalRemaining ? 'var(--error-color, #ef4444)' : '';
      } else {
        charCounter.textContent = '';
        charCounter.style.color = '';
      }
      syncQuotaDisplay();
    }

    if (isArabicPageLocale && targetLanguage) {
      targetLanguage.value = 'ar';
      preferredTranslateLanguage = 'ar';
    }
    if (translateToggle && translateSelect) translateToggle.checked = translateSelect.value === '1';
    if (!isTranslateEnabled()) preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    syncPremiumLanguages();
    renderVoiceOptions();
    renderDialectOptions();
    applyStudioTierState();
    updateTranslateHint();
    renderQuota(currentQuota);
    updateCharCount(); // Initialize char counter on load
    if (toBoolean(currentQuota?.requires_client_sync)) {
      refreshQuotaFromServer().catch(() => {});
    }

    // Reveal on scroll animation
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('show'); revealObserver.unobserve(e.target); } });
    }, { threshold: 0.08 });
    document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));
  </script>
  <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
  @auth
    {{-- Only while a referral campaign runs: without one no reward is given. --}}
    @if (\App\Models\ReferralCampaign::active())
      @include('referral.popup')
    @endif
  @endauth
  <script src="{{ asset('js/voice-studio-icons.js') }}?v={{ filemtime(public_path('js/voice-studio-icons.js')) }}" defer></script>
  @if($dubStudio ?? null)
    <script src="{{ asset('js/dub-studio.js') }}?v={{ filemtime(public_path('js/dub-studio.js')) }}" defer></script>
  @endif
  <script src="{{ asset('js/voice-studio.js') }}?v={{ filemtime(public_path('js/voice-studio.js')) }}" defer></script>
</body>
</html>
